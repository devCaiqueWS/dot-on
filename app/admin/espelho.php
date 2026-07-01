<?php
$titulo = 'Espelho de Ponto'; $pagina = 'espelho';
require __DIR__ . '/_layout.php';

$emp_id = $user['empresa_id'];
$func = (int)($_GET['func'] ?? $user['id']);
// Garante que o funcionário pertence à empresa do usuário logado
$st = db()->prepare("SELECT id FROM dot_usuarios WHERE id=? AND empresa_id=?");
$st->execute([$func, $emp_id]);
if (!$st->fetch()) { $func = $user['id']; }
$mes = $_GET['mes'] ?? date('Y-m');
[$ano, $m] = explode('-', $mes);
$inicio = "$ano-$m-01";
$fim = date('Y-m-t', strtotime($inicio));

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
    <a href="espelho_pdf.php?func=<?= $func ?>&mes=<?= $mes ?>" class="btn btn-secondary" target="_blank">📄 Exportar PDF</a>
</form>

<div class="cards">
    <div class="card"><div class="num"><?= fmt_minutos($total_trab) ?></div><div class="lbl">Trabalhadas</div></div>
    <div class="card"><div class="num"><?= fmt_minutos($total_extra) ?></div><div class="lbl">Extras</div></div>
    <div class="card"><div class="num"><?= fmt_minutos($total_ocioso) ?></div><div class="lbl">Ociosas</div></div>
    <div class="card"><div class="num"><?= count($sessoes) ?></div><div class="lbl">Dias trabalhados</div></div>
</div>

<div class="panel">
    <table class="tbl espelho">
        <thead><tr>
            <th>Data</th><th>Entrada</th><th>Saída interv.</th><th>Retorno</th><th>Saída</th>
            <th>Trab.</th><th>Ocioso</th><th>Extra</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($sessoes as $s):
            $b = $batidas_por_dia[$s['data_ref']] ?? [];
            $busca = fn($t) => current(array_filter($b, fn($x) => $x['tipo'] === $t));
        ?>
            <tr>
                <td><strong><?= date('d/m (D)', strtotime($s['data_ref'])) ?></strong></td>
                <td><?= ($r=$busca('entrada')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
                <td><?= ($r=$busca('saida_intervalo')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
                <td><?= ($r=$busca('retorno_intervalo')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
                <td><?= ($r=$busca('saida')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
                <td><?= fmt_minutos($s['minutos_trabalhados']) ?></td>
                <td class="ocioso"><?= fmt_minutos($s['minutos_ociosos']) ?></td>
                <td class="extra"><?= fmt_minutos($s['minutos_extras']) ?></td>
                <td><span class="tag tag-<?= $s['status'] ?>"><?= $s['status'] ?></span></td>
            </tr>
        <?php endforeach; if (!$sessoes): ?>
            <tr><td colspan="9" class="empty">Sem registros no período.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main></body></html>
