<?php
$titulo = 'Espelho de Ponto'; $pagina = 'espelho';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/banco_horas.php'; // apuração compartilhada (bh_apurar)

$emp_id = $user['empresa_id'];
$func = (int)($_GET['func'] ?? $user['id']);
// Perfis comuns só veem o próprio espelho (dados pessoais de colegas).
if (!in_array($user['perfil'], ['admin','rh','gestor'])) { $func = (int)$user['id']; }
// Garante que o funcionário pertence à empresa do usuário logado
$st = db()->prepare("SELECT id FROM dot_usuarios WHERE id=? AND empresa_id=?");
$st->execute([$func, $emp_id]);
if (!$st->fetch()) { $func = $user['id']; }
$mes = $_GET['mes'] ?? date('Y-m');
[$ano, $m] = explode('-', $mes);
$inicio = "$ano-$m-01";
$fim = date('Y-m-t', strtotime($inicio));

// Horas a favor / em débito do mês — mesma apuração do Banco de Horas
// (jornada por dia da semana, faltas geram débito, abonos neutralizam).
// Roda antes da consulta abaixo porque também recalcula os minutos das sessões.
$ap = bh_apurar($func, $emp_id, $inicio, $fim);

$stmt = db()->prepare("SELECT s.*, u.nome_completo, u.matricula, e.entrada, e.intervalo_inicio, e.intervalo_fim, e.saida, e.carga_diaria_minutos
    FROM dot_sessoes s
    JOIN dot_usuarios u ON u.id = s.usuario_id
    LEFT JOIN dot_escalas e ON e.id = u.escala_id
    WHERE s.usuario_id = ? AND s.data_ref BETWEEN ? AND ?
    ORDER BY s.data_ref");
$stmt->execute([$func, $inicio, $fim]);
$sessoes = $stmt->fetchAll();

// batidas (espelho = visão tratada: ignora batidas anuladas)
batidas_garantir_cancelamento();
$stmt = db()->prepare("SELECT * FROM dot_batidas WHERE usuario_id=? AND DATE(momento) BETWEEN ? AND ? AND COALESCE(cancelada,0)=0 ORDER BY momento");
$stmt->execute([$func, $inicio, $fim]);
$batidas_por_dia = [];
foreach ($stmt->fetchAll() as $b) {
    $batidas_por_dia[substr($b['momento'],0,10)][] = $b;
}

$stmt = db()->prepare("SELECT id, nome_completo FROM dot_usuarios WHERE empresa_id=? AND ativo=1 ORDER BY nome_completo");
$stmt->execute([$emp_id]);
$funcs = $stmt->fetchAll();

$total_trab = array_sum(array_column($sessoes, 'minutos_trabalhados'));
$total_extra = array_sum(array_column($sessoes, 'minutos_extras'));
$total_ocioso = array_sum(array_column($sessoes, 'minutos_ociosos'));

// Mescla sessões com a apuração: dias sem batida (falta, falta abonada,
// feriado) também viram linha do espelho, em vez de sumir da tabela.
$linhas = [];
foreach ($sessoes as $s) $linhas[$s['data_ref']]['sessao'] = $s;
foreach ($ap['dias'] as $d) $linhas[$d['data_ref']]['ap'] = $d;
ksort($linhas);

$cor_situacao = ['Falta'=>'#dc2626','Falta abonada'=>'#16a34a','Feriado'=>'#0d9488','Folga trab.'=>'#0284c7'];
?>
<form method="get" class="form-inline">
    <label>Funcionário
        <select name="func">
            <?php foreach ($funcs as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $func===(int)$f['id']?'selected':'' ?>><?= htmlspecialchars($f['nome_completo']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Mês <input type="month" name="mes" value="<?= htmlspecialchars($mes) ?>"></label>
    <button class="btn btn-primary">Gerar</button>
    <a href="espelho_pdf.php?func=<?= $func ?>&mes=<?= $mes ?>" class="btn btn-secondary" target="_blank"><?= icon('print', 16) ?>Exportar PDF</a>
</form>

<div class="cards">
    <div class="card"><div class="num"><?= fmt_minutos($total_trab) ?></div><div class="lbl">Trabalhadas</div></div>
    <div class="card"><div class="num"><?= fmt_minutos($total_extra) ?></div><div class="lbl">Extras</div></div>
    <div class="card"><div class="num"><?= fmt_minutos($total_ocioso) ?></div><div class="lbl">Ociosas</div></div>
    <div class="card"><div class="num" style="color:#16a34a"><?= fmt_minutos($ap['total_positivo']) ?></div><div class="lbl">Horas a favor</div></div>
    <div class="card"><div class="num" style="color:#dc2626"><?= fmt_minutos($ap['total_negativo']) ?></div><div class="lbl">Horas em débito</div></div>
    <div class="card"><div class="num" style="color:<?= $ap['saldo_periodo']>=0?'#16a34a':'#dc2626' ?>"><?= fmt_minutos($ap['saldo_periodo']) ?></div><div class="lbl">Saldo do mês</div></div>
    <div class="card"><div class="num"><?= count($sessoes) ?></div><div class="lbl">Dias trabalhados</div></div>
</div>

<div class="panel">
    <table class="tbl espelho">
        <thead><tr>
            <th>Data</th><th>Entrada</th><th>Saída interv.</th><th>Retorno</th><th>Saída</th>
            <th>Trab.</th><th>Ocioso</th><th>Extra</th><th>Saldo dia</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($linhas as $data_ref => $ln):
            $s  = $ln['sessao'] ?? null;
            $sd = $ln['ap'] ?? null;
        ?>
            <?php if ($s):
                $b = $batidas_por_dia[$data_ref] ?? [];
                $busca = fn($t) => current(array_filter($b, fn($x) => $x['tipo'] === $t));
            ?>
            <tr>
                <td><strong><?= date('d/m (D)', strtotime($data_ref)) ?></strong></td>
                <td><?= ($r=$busca('entrada')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
                <td><?= ($r=$busca('saida_intervalo')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
                <td><?= ($r=$busca('retorno_intervalo')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
                <td><?= ($r=$busca('saida')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
                <td><?= fmt_minutos($s['minutos_trabalhados']) ?></td>
                <td class="ocioso"><?= fmt_minutos($s['minutos_ociosos']) ?></td>
                <td class="extra"><?= fmt_minutos($s['minutos_extras']) ?></td>
                <td style="font-weight:600;color:<?= $sd ? ($sd['saldo']>=0?'#16a34a':'#dc2626') : 'inherit' ?>"><?= $sd ? fmt_minutos($sd['saldo']) : '—' ?></td>
                <td><span class="tag tag-<?= $s['status'] ?>"><?= $s['status'] ?></span></td>
            </tr>
            <?php else: // dia apurado sem batida: falta, falta abonada ou feriado ?>
            <tr>
                <td><strong><?= date('d/m (D)', strtotime($data_ref)) ?></strong></td>
                <td colspan="4" style="color:var(--muted)">— sem batidas —</td>
                <td><?= fmt_minutos($sd['minutos_trabalhados']) ?></td>
                <td class="ocioso">—</td>
                <td class="extra">—</td>
                <td style="font-weight:600;color:<?= $sd['saldo']>=0?'#16a34a':'#dc2626' ?>"><?= fmt_minutos($sd['saldo']) ?></td>
                <td>
                    <span style="color:<?= $cor_situacao[$sd['situacao']] ?? '#64748b' ?>;font-weight:600"><?= $sd['situacao'] ?></span>
                    <?php if (!empty($sd['abono'])): ?>
                        <div style="font-size:.72rem;color:var(--muted)" title="<?= htmlspecialchars($sd['abono']['motivo']) ?>"><?= htmlspecialchars(jus_label_tipo($sd['abono']['tipo'])) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; if (!$linhas): ?>
            <tr><td colspan="10" class="empty">Sem registros no período.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main></body></html>
