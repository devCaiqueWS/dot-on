<?php
$titulo = 'Banco de Horas'; $pagina = 'banco_horas';
require __DIR__ . '/_layout.php';

$emp_id = $user['empresa_id'];
$func = (int)($_GET['func'] ?? $user['id']);
$periodo_inicio = $_GET['inicio'] ?? date('Y-m-01', strtotime('-2 months'));
$periodo_fim    = $_GET['fim']    ?? date('Y-m-t');

$stmt = db()->prepare("SELECT u.*, e.carga_diaria_minutos, e.entrada, e.saida
    FROM dot_usuarios u LEFT JOIN dot_escalas e ON e.id=u.escala_id
    WHERE u.id=? AND u.empresa_id=?");
$stmt->execute([$func, $emp_id]);
$f = $stmt->fetch();
if (!$f) { echo "<p>Funcionário não encontrado.</p></main></body></html>"; exit; }

$carga_diaria = (int)($f['carga_diaria_minutos'] ?? 480);

$stmt = db()->prepare("SELECT s.data_ref, s.minutos_trabalhados, s.minutos_ociosos, s.minutos_extras, s.status
    FROM dot_sessoes s
    WHERE s.usuario_id=? AND s.data_ref BETWEEN ? AND ?
    ORDER BY s.data_ref");
$stmt->execute([$func, $periodo_inicio, $periodo_fim]);
$dias = $stmt->fetchAll();

// Apuração: trabalhado - carga = saldo do dia
$saldo_acumulado = 0;
$total_positivo = 0; $total_negativo = 0;
foreach ($dias as &$d) {
    $diff = (int)$d['minutos_trabalhados'] - $carga_diaria;
    $d['saldo'] = $diff;
    $saldo_acumulado += $diff;
    if ($diff > 0) $total_positivo += $diff; else $total_negativo += $diff;
    $d['saldo_acumulado'] = $saldo_acumulado;
}

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

<div class="cards">
    <div class="card"><div class="num" style="color:#16a34a"><?= fmt_minutos($total_positivo) ?></div><div class="lbl">Horas a favor</div></div>
    <div class="card"><div class="num" style="color:#dc2626"><?= fmt_minutos($total_negativo) ?></div><div class="lbl">Horas em débito</div></div>
    <div class="card"><div class="num" style="color:<?= $saldo_acumulado>=0?'#16a34a':'#dc2626' ?>"><?= fmt_minutos($saldo_acumulado) ?></div><div class="lbl">Saldo final</div></div>
    <div class="card"><div class="num"><?= count($dias) ?></div><div class="lbl">Dias apurados</div></div>
</div>

<div class="panel">
    <h2>Apuração diária · <?= htmlspecialchars($f['nome_completo']) ?> · Jornada <?= fmt_minutos($carga_diaria) ?>/dia</h2>
    <table class="tbl">
        <thead><tr><th>Data</th><th>Trab.</th><th>Ocioso</th><th>Extra</th><th>Saldo dia</th><th>Acumulado</th></tr></thead>
        <tbody>
        <?php foreach ($dias as $d): $cor = $d['saldo']>=0?'#16a34a':'#dc2626'; ?>
            <tr>
                <td><?= date('d/m/Y D', strtotime($d['data_ref'])) ?></td>
                <td><?= fmt_minutos($d['minutos_trabalhados']) ?></td>
                <td><?= fmt_minutos($d['minutos_ociosos']) ?></td>
                <td><?= fmt_minutos($d['minutos_extras']) ?></td>
                <td style="color:<?= $cor ?>;font-weight:600"><?= fmt_minutos($d['saldo']) ?></td>
                <td style="color:<?= $d['saldo_acumulado']>=0?'#16a34a':'#dc2626' ?>;font-weight:700"><?= fmt_minutos($d['saldo_acumulado']) ?></td>
            </tr>
        <?php endforeach; if(!$dias): ?>
            <tr><td colspan="6" class="empty">Sem dados no período</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main></body></html>
