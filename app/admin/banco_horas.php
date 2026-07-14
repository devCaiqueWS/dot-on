<?php
$titulo = 'Banco de Horas'; $pagina = 'banco_horas';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/ajuste_ponto.php'; // jornada_listar() / jornada por dia

$emp_id = $user['empresa_id'];
$func = (int)($_GET['func'] ?? $user['id']);
$periodo_inicio = $_GET['inicio'] ?? date('Y-m-01', strtotime('-2 months'));
$periodo_fim    = $_GET['fim']    ?? date('Y-m-t');

// Colunas de âncora do banco de horas (auto-migração idempotente).
bh_garantir_schema();

// RH/gestor define a data-âncora e o saldo herdado do funcionário. É isso que
// impede a apuração de contar dias anteriores à entrada da pessoa no sistema
// (débito falso: trabalhado=0 vs. carga>0 em todo dia antes de ela existir).
$aviso_salvo = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user['perfil'], ['admin','rh','gestor'])) {
    csrf_check();
    $nova_data = trim($_POST['data_inicio_banco'] ?? '');
    $nova_data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $nova_data) ? $nova_data : null;
    $saldo_ini = bh_parse_hhmm($_POST['saldo_inicial'] ?? '');
    db()->prepare("UPDATE dot_usuarios SET data_inicio_banco=?, saldo_inicial_minutos=? WHERE id=? AND empresa_id=?")
        ->execute([$nova_data, $saldo_ini, $func, $emp_id]);
    $aviso_salvo = true;
}

$stmt = db()->prepare("SELECT u.* FROM dot_usuarios u WHERE u.id=? AND u.empresa_id=?");
$stmt->execute([$func, $emp_id]);
$f = $stmt->fetch();
if (!$f) { echo "<p>Funcionário não encontrado.</p></main></body></html>"; exit; }

// Âncora efetiva do funcionário: a apuração NUNCA conta dias anteriores a ela.
// $inicio_efetivo = o maior entre o filtro de tela e a âncora do funcionário.
$data_inicio_banco = bh_data_inicio_banco($f);          // pode ser null (sem batidas e sem cadastro)
$data_inicio_fonte = !empty($f['data_inicio_banco']) ? 'definida pelo RH'
                   : ($data_inicio_banco ? 'primeira batida / cadastro' : '—');
$saldo_inicial     = (int)($f['saldo_inicial_minutos'] ?? 0);
$inicio_efetivo    = $data_inicio_banco ? max($periodo_inicio, $data_inicio_banco) : $periodo_inicio;

// ================================================================
// APURAÇÃO DO BANCO DE HORAS
// A meta diária (carga) vem da JORNADA DO FUNCIONÁRIO por dia da
// semana (dot_usuario_jornada), não de uma carga fixa. Regras:
//   • Dia útil trabalhado ....... saldo = trabalhado − carga do dia
//   • Falta em dia útil ......... saldo = −carga do dia (débito)
//   • Folga trabalhada .......... carga 0 → tudo é crédito
//   • Folga não trabalhada ...... não entra na conta
// Varremos TODOS os dias do período (até hoje) para que faltas
// gerem débito — a apuração antiga só via dias com batida.
// ================================================================
$jornada = jornada_listar($func); // [dia_semana 0..6 => linha]

// Auto-recálculo: sessões podem ter minutos_trabalhados desatualizado (batidas
// antigas que não recalcularam a sessão). Recalcula antes de apurar.
$stmt = db()->prepare("SELECT id FROM dot_sessoes WHERE usuario_id=? AND data_ref BETWEEN ? AND ?");
$stmt->execute([$func, $inicio_efetivo, $periodo_fim]);
foreach ($stmt->fetchAll() as $row) {
    try { jus_recalcular_sessao((int)$row['id']); } catch (Throwable $e) {}
}

// Sessões do período (já recalculadas), indexadas por data (Y-m-d)
$stmt = db()->prepare("SELECT s.data_ref, s.minutos_trabalhados, s.minutos_ociosos, s.minutos_extras, s.status
    FROM dot_sessoes s
    WHERE s.usuario_id=? AND s.data_ref BETWEEN ? AND ?");
$stmt->execute([$func, $inicio_efetivo, $periodo_fim]);
$sessoes = [];
foreach ($stmt->fetchAll() as $s) $sessoes[$s['data_ref']] = $s;

// Abonos: justificativas de falta APROVADAS pelo gestor (a pessoa escreveu o
// motivo e/ou anexou comprovação). Uma falta abonada não gera débito.
jus_garantir_schema();
$stmt = db()->prepare("SELECT id, data_ref, tipo, motivo, anexo_arquivo
    FROM dot_justificativas
    WHERE usuario_id=? AND empresa_id=? AND categoria='justificativa'
      AND status='aprovada' AND data_ref BETWEEN ? AND ?
    ORDER BY decidido_em ASC"); // último aprovado vence o slot do dia
$stmt->execute([$func, $emp_id, $inicio_efetivo, $periodo_fim]);
$abonos = [];
foreach ($stmt->fetchAll() as $a) $abonos[$a['data_ref']] = $a; // 1 por dia basta

$hoje = date('Y-m-d');
$fim_efetivo = min($periodo_fim, $hoje); // não conta dias no futuro

$dias = [];
$saldo_acumulado = $saldo_inicial; // parte do saldo herdado (migração), não de zero
$total_positivo = 0; $total_negativo = 0;

$cur = new DateTime($inicio_efetivo);
$lim = new DateTime($fim_efetivo);
while ($cur <= $lim) {
    $data = $cur->format('Y-m-d');
    $dow  = (int)$cur->format('w');          // 0=domingo .. 6=sábado
    $cur->modify('+1 day');

    $jd       = $jornada[$dow] ?? null;
    $trabalha = $jd ? (int)$jd['trabalha'] : ($dow >= 1 && $dow <= 5 ? 1 : 0);
    $carga    = $trabalha ? (int)($jd['carga_minutos'] ?: 480) : 0;

    $sess       = $sessoes[$data] ?? null;
    $trabalhado = $sess ? (int)$sess['minutos_trabalhados'] : 0;

    // Dia de hoje: só apura depois que a pessoa registrar a saída (sessão
    // encerrada). Enquanto está em curso, não penaliza nem conta como falta.
    if ($data === $hoje && (!$sess || ($sess['status'] ?? '') !== 'encerrada')) continue;
    // Folga não trabalhada: não polui o relatório.
    if ($carga === 0 && $trabalhado === 0) continue;

    $abono = $abonos[$data] ?? null;

    if (!$sess && $carga > 0) {
        // Falta: abonada (justificativa aprovada) não gera débito.
        // Feriado é um abono especial: o dia é neutro e aparece como "Feriado".
        if ($abono) {
            $situacao = ($abono['tipo'] === 'feriado') ? 'Feriado' : 'Falta abonada';
        } else {
            $situacao = 'Falta';
        }
    } elseif ($carga === 0 && $trabalhado) {
        $situacao = 'Folga trab.';
    } else {
        $situacao = 'Normal';
    }

    // Saldo do dia. Falta abonada zera o débito (dia neutro).
    if (!$sess && $carga > 0 && $abono) {
        $saldo = 0;
    } else {
        $saldo = $trabalhado - $carga;
    }
    $saldo_acumulado += $saldo;
    if ($saldo > 0) $total_positivo += $saldo; else $total_negativo += $saldo;

    $dias[] = [
        'data_ref'            => $data,
        'carga'               => $carga,
        'minutos_trabalhados' => $trabalhado,
        'minutos_ociosos'     => $sess ? (int)$sess['minutos_ociosos'] : 0,
        'minutos_extras'      => $sess ? (int)$sess['minutos_extras'] : 0,
        'situacao'            => $situacao,
        'abono'              => $abono,
        'saldo'               => $saldo,
        'saldo_acumulado'     => $saldo_acumulado,
    ];
}

$stmt = db()->prepare("SELECT id, nome_completo FROM dot_usuarios WHERE empresa_id=? AND ativo=1 ORDER BY nome_completo");
$stmt->execute([$user['empresa_id']]);
$funcs = $stmt->fetchAll();

/** Auto-migração idempotente: colunas de âncora do banco de horas em dot_usuarios. */
function bh_garantir_schema(): void {
    static $ok = false; if ($ok) return;
    $tem = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dot_usuarios'
          AND COLUMN_NAME='data_inicio_banco'")->fetchColumn();
    if (!$tem) {
        db()->exec("ALTER TABLE dot_usuarios
            ADD COLUMN data_inicio_banco DATE NULL,
            ADD COLUMN saldo_inicial_minutos INT NOT NULL DEFAULT 0");
    }
    $ok = true;
}

/**
 * Data-âncora do banco de horas: a apuração nunca conta dias anteriores a ela.
 * Prioridade: data_inicio_banco (definida pelo RH) → 1ª batida (menor data_ref
 * com sessão) → data de cadastro. É explícita e estável: ao contrário de
 * "recalcular sempre pela batida mais antiga", não se desloca se alguém corrigir
 * ou apagar a batida mais antiga — o cálculo do saldo continua determinístico.
 */
function bh_data_inicio_banco(array $f): ?string {
    if (!empty($f['data_inicio_banco'])) return (string)$f['data_inicio_banco'];
    $st = db()->prepare("SELECT MIN(data_ref) FROM dot_sessoes WHERE usuario_id=?");
    $st->execute([$f['id']]);
    $primeira = $st->fetchColumn();
    if ($primeira) return (string)$primeira;
    return !empty($f['criado_em']) ? substr((string)$f['criado_em'], 0, 10) : null;
}

/** Converte "±HH:MM" (ou minutos avulsos) em minutos inteiros com sinal. */
function bh_parse_hhmm(string $txt): int {
    $txt = trim($txt);
    if ($txt === '') return 0;
    $neg = strncmp($txt, '-', 1) === 0;
    $txt = ltrim($txt, '+-');
    if (strpos($txt, ':') !== false) {
        [$h, $m] = array_pad(explode(':', $txt, 2), 2, '0');
        $min = ((int)$h) * 60 + (int)$m;
    } else {
        $min = (int)$txt;
    }
    return $neg ? -$min : $min;
}
?>
<form method="get" class="form-inline">
    <label>Funcionário
        <select name="func">
            <?php foreach ($funcs as $ff): ?>
                <option value="<?= $ff['id'] ?>" <?= $func===(int)$ff['id']?'selected':'' ?>><?= htmlspecialchars($ff['nome_completo']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Início <input type="date" name="inicio" value="<?= htmlspecialchars($periodo_inicio) ?>"></label>
    <label>Fim <input type="date" name="fim" value="<?= htmlspecialchars($periodo_fim) ?>"></label>
    <button class="btn btn-primary">Filtrar</button>
</form>

<?php if ($aviso_salvo): ?>
    <div class="panel" style="border-left:4px solid #16a34a;background:#f0fdf4">
        ✔ Âncora do banco de horas atualizada.
    </div>
<?php endif; ?>

<?php if (in_array($user['perfil'], ['admin','rh','gestor'])): ?>
<div class="panel">
    <h2>Âncora do banco de horas · <?= htmlspecialchars($f['nome_completo']) ?></h2>
    <p style="color:#64748b;font-size:.85rem;margin:.25rem 0 .75rem">
        A apuração não conta nenhum dia anterior a esta data — é o que evita
        débito falso do período em que a pessoa ainda não registrava ponto.
        Em branco, o sistema usa a <strong>primeira batida</strong> como início.
    </p>
    <form method="post" class="form-inline">
        <?= csrf_field() ?>
        <label>Início do banco (admissão)
            <input type="date" name="data_inicio_banco" value="<?= htmlspecialchars($f['data_inicio_banco'] ?? '') ?>">
        </label>
        <label>Saldo inicial herdado (±HH:MM)
            <input type="text" name="saldo_inicial" placeholder="ex.: -12:30" value="<?= $saldo_inicial ? htmlspecialchars(fmt_minutos($saldo_inicial)) : '' ?>">
        </label>
        <button class="btn btn-primary">Salvar âncora</button>
    </form>
    <p style="color:#94a3b8;font-size:.78rem;margin-top:.5rem">
        Apurando a partir de <strong><?= htmlspecialchars($inicio_efetivo) ?></strong>
        (âncora: <?= $data_inicio_banco ? htmlspecialchars($data_inicio_banco) : '—' ?>, <?= htmlspecialchars($data_inicio_fonte) ?>).
    </p>
</div>
<?php endif; ?>

<div class="cards">
    <?php if ($saldo_inicial): ?>
    <div class="card"><div class="num" style="color:<?= $saldo_inicial>=0?'#16a34a':'#dc2626' ?>"><?= fmt_minutos($saldo_inicial) ?></div><div class="lbl">Saldo inicial herdado</div></div>
    <?php endif; ?>
    <div class="card"><div class="num" style="color:#16a34a"><?= fmt_minutos($total_positivo) ?></div><div class="lbl">Horas a favor</div></div>
    <div class="card"><div class="num" style="color:#dc2626"><?= fmt_minutos($total_negativo) ?></div><div class="lbl">Horas em débito</div></div>
    <div class="card"><div class="num" style="color:<?= $saldo_acumulado>=0?'#16a34a':'#dc2626' ?>"><?= fmt_minutos($saldo_acumulado) ?></div><div class="lbl">Saldo final</div></div>
    <div class="card"><div class="num"><?= count($dias) ?></div><div class="lbl">Dias apurados</div></div>
</div>

<div class="panel">
    <h2>Apuração diária · <?= htmlspecialchars($f['nome_completo']) ?> · carga conforme a jornada do funcionário</h2>
    <table class="tbl">
        <thead><tr><th>Data</th><th>Carga</th><th>Trab.</th><th>Situação</th><th>Ocioso</th><th>Extra</th><th>Saldo dia</th><th>Acumulado</th></tr></thead>
        <tbody>
        <?php foreach ($dias as $d): $cor = $d['saldo']>=0?'#16a34a':'#dc2626';
            $badge = ['Falta'=>'#dc2626','Falta abonada'=>'#16a34a','Feriado'=>'#0d9488','Folga trab.'=>'#0284c7','Normal'=>'#64748b'][$d['situacao']] ?? '#64748b';
            $ab = $d['abono'] ?? null; ?>
            <tr>
                <td><?= date('d/m/Y D', strtotime($d['data_ref'])) ?></td>
                <td><?= $d['carga'] ? fmt_minutos($d['carga']) : '—' ?></td>
                <td><?= fmt_minutos($d['minutos_trabalhados']) ?></td>
                <td>
                    <span style="color:<?= $badge ?>;font-weight:600"><?= $d['situacao'] ?></span>
                    <?php if ($ab): ?>
                        <div style="font-size:.72rem;color:#64748b" title="<?= htmlspecialchars($ab['motivo']) ?>">
                            <?= htmlspecialchars(jus_label_tipo($ab['tipo'])) ?>
                            <?php if (!empty($ab['anexo_arquivo'])): ?>
                                · <a href="anexo.php?id=<?= (int)$ab['id'] ?>" target="_blank" style="color:#1d4ed8">comprovação</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?= fmt_minutos($d['minutos_ociosos']) ?></td>
                <td><?= fmt_minutos($d['minutos_extras']) ?></td>
                <td style="color:<?= $cor ?>;font-weight:600"><?= fmt_minutos($d['saldo']) ?></td>
                <td style="color:<?= $d['saldo_acumulado']>=0?'#16a34a':'#dc2626' ?>;font-weight:700"><?= fmt_minutos($d['saldo_acumulado']) ?></td>
            </tr>
        <?php endforeach; if(!$dias): ?>
            <tr><td colspan="8" class="empty">Sem dados no período</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main></body></html>
