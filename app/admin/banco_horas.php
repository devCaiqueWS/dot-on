<?php
$titulo = 'Banco de Horas'; $pagina = 'banco_horas';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/banco_horas.php'; // apuração compartilhada (bh_apurar)

$emp_id = $user['empresa_id'];
$func = (int)($_GET['func'] ?? $user['id']);
// Perfis comuns só veem o próprio banco de horas.
if (!in_array($user['perfil'], ['admin','rh','gestor'])) { $func = (int)$user['id']; }
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

// Apuração compartilhada (mesma usada no Espelho de Ponto e no portal /me).
// carry=true: o acumulado considera também o trecho entre a âncora e o início
// do filtro — o "Saldo final" não muda quando se filtra um período menor.
$ap = bh_apurar($func, $emp_id, $periodo_inicio, $periodo_fim, true, true);
$f = $ap['funcionario'];
if (!$f) { echo "<p>Funcionário não encontrado.</p></main></body></html>"; exit; }

$data_inicio_banco = $ap['data_inicio_banco'];
$data_inicio_fonte = $ap['data_inicio_fonte'];
$saldo_inicial     = $ap['saldo_inicial'];
$saldo_anterior    = $ap['saldo_anterior'];
$inicio_efetivo    = $ap['inicio_efetivo'];
$dias              = $ap['dias'];
$total_positivo    = $ap['total_positivo'];
$total_negativo    = $ap['total_negativo'];
$saldo_acumulado   = $ap['saldo_acumulado'];

$stmt = db()->prepare("SELECT id, nome_completo FROM dot_usuarios WHERE empresa_id=? AND ativo=1 ORDER BY nome_completo");
$stmt->execute([$user['empresa_id']]);
$funcs = $stmt->fetchAll();
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
    <div class="panel" style="border-left:4px solid #16a34a;background:#f0fdf4;color:#166534">
        ✔ Âncora do banco de horas atualizada.
    </div>
<?php endif; ?>

<?php if (in_array($user['perfil'], ['admin','rh','gestor'])): ?>
<div class="panel">
    <h2>Âncora do banco de horas · <?= htmlspecialchars($f['nome_completo']) ?></h2>
    <p style="color:var(--muted);font-size:.85rem;margin:.25rem 0 .75rem">
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
    <p style="color:var(--muted);font-size:.78rem;margin-top:.5rem">
        Apurando a partir de <strong><?= htmlspecialchars($inicio_efetivo) ?></strong>
        (âncora: <?= $data_inicio_banco ? htmlspecialchars($data_inicio_banco) : '—' ?>, <?= htmlspecialchars($data_inicio_fonte) ?>).
    </p>
</div>
<?php endif; ?>

<div class="cards">
    <?php if ($saldo_inicial): ?>
    <div class="card"><div class="num" style="color:<?= $saldo_inicial>=0?'#16a34a':'#dc2626' ?>"><?= fmt_minutos($saldo_inicial) ?></div><div class="lbl">Saldo inicial herdado</div></div>
    <?php endif; ?>
    <?php if ($saldo_anterior): ?>
    <div class="card"><div class="num" style="color:<?= $saldo_anterior>=0?'#16a34a':'#dc2626' ?>"><?= fmt_minutos($saldo_anterior) ?></div><div class="lbl">Saldo antes do período</div></div>
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
                        <div style="font-size:.72rem;color:var(--muted)" title="<?= htmlspecialchars($ab['motivo']) ?>">
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
