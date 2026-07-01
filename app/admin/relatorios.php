<?php
$titulo = 'Relatórios & Exportação'; $pagina = 'relatorios';
require __DIR__ . '/_layout.php';

$inicio = $_GET['inicio'] ?? date('Y-m-01');
$fim    = $_GET['fim']    ?? date('Y-m-t');

// Estatísticas gerais
$stmt = db()->prepare("SELECT
    COUNT(DISTINCT s.usuario_id) AS funcs_ativos,
    COUNT(s.id) AS sessoes,
    SUM(s.minutos_trabalhados) AS min_trab,
    SUM(s.minutos_ociosos) AS min_ocio,
    SUM(s.minutos_extras) AS min_extra
    FROM dot_sessoes s JOIN dot_usuarios u ON u.id=s.usuario_id
    WHERE u.empresa_id=? AND s.data_ref BETWEEN ? AND ?");
$stmt->execute([$user['empresa_id'], $inicio, $fim]);
$g = $stmt->fetch();

// Ranking por funcionário
$stmt = db()->prepare("SELECT u.nome_completo, u.matricula,
    SUM(s.minutos_trabalhados) AS trab,
    SUM(s.minutos_ociosos) AS ocio,
    SUM(s.minutos_extras) AS extra,
    COUNT(s.id) AS dias
    FROM dot_sessoes s JOIN dot_usuarios u ON u.id=s.usuario_id
    WHERE u.empresa_id=? AND s.data_ref BETWEEN ? AND ?
    GROUP BY u.id ORDER BY trab DESC");
$stmt->execute([$user['empresa_id'], $inicio, $fim]);
$ranking = $stmt->fetchAll();

// Série por dia (para gráfico)
$stmt = db()->prepare("SELECT s.data_ref,
    SUM(s.minutos_trabalhados) AS trab,
    SUM(s.minutos_ociosos) AS ocio,
    SUM(s.minutos_extras) AS extra
    FROM dot_sessoes s JOIN dot_usuarios u ON u.id=s.usuario_id
    WHERE u.empresa_id=? AND s.data_ref BETWEEN ? AND ?
    GROUP BY s.data_ref ORDER BY s.data_ref");
$stmt->execute([$user['empresa_id'], $inicio, $fim]);
$serie = $stmt->fetchAll();
?>
<form method="get" class="form-inline">
    <label>Início <input type="date" name="inicio" value="<?= htmlspecialchars($inicio) ?>"></label>
    <label>Fim <input type="date" name="fim" value="<?= htmlspecialchars($fim) ?>"></label>
    <button class="btn btn-primary">Filtrar</button>
    <a href="export_afd.php?inicio=<?= $inicio ?>&fim=<?= $fim ?>" class="btn btn-success">📄 Exportar AFD</a>
    <a href="export_aej.php?inicio=<?= $inicio ?>&fim=<?= $fim ?>" class="btn btn-success">📋 Exportar AEJ</a>
</form>

<div class="cards">
    <div class="card"><div class="num"><?= (int)$g['funcs_ativos'] ?></div><div class="lbl">Funcionários ativos</div></div>
    <div class="card"><div class="num"><?= (int)$g['sessoes'] ?></div><div class="lbl">Dias trabalhados</div></div>
    <div class="card"><div class="num"><?= fmt_minutos((int)$g['min_trab']) ?></div><div class="lbl">Horas trabalhadas</div></div>
    <div class="card"><div class="num" style="color:#f59e0b"><?= fmt_minutos((int)$g['min_ocio']) ?></div><div class="lbl">Ociosidade total</div></div>
    <div class="card"><div class="num" style="color:#7c3aed"><?= fmt_minutos((int)$g['min_extra']) ?></div><div class="lbl">Horas extras</div></div>
</div>

<div class="panel">
    <h2>📊 Distribuição diária</h2>
    <canvas id="grafico" height="80"></canvas>
</div>

<div class="panel">
    <h2>🏆 Ranking por funcionário</h2>
    <table class="tbl">
        <thead><tr><th>#</th><th>Funcionário</th><th>Matrícula</th><th>Dias</th><th>Trabalhadas</th><th>Ociosas</th><th>Extras</th></tr></thead>
        <tbody>
        <?php foreach ($ranking as $i => $r): ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><?= htmlspecialchars($r['nome_completo']) ?></td>
                <td><code><?= $r['matricula'] ?></code></td>
                <td><?= $r['dias'] ?></td>
                <td><?= fmt_minutos((int)$r['trab']) ?></td>
                <td><?= fmt_minutos((int)$r['ocio']) ?></td>
                <td><?= fmt_minutos((int)$r['extra']) ?></td>
            </tr>
        <?php endforeach; if(!$ranking): ?>
            <tr><td colspan="7" class="empty">Sem dados no período</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const dados = <?= json_encode($serie) ?>;
new Chart(document.getElementById('grafico'), {
    type: 'bar',
    data: {
        labels: dados.map(d => new Date(d.data_ref).toLocaleDateString('pt-BR', {day:'2-digit',month:'2-digit'})),
        datasets: [
            {label:'Trabalhadas (h)', data: dados.map(d => (d.trab/60).toFixed(2)), backgroundColor:'#2563eb'},
            {label:'Ociosas (h)',     data: dados.map(d => (d.ocio/60).toFixed(2)), backgroundColor:'#f59e0b'},
            {label:'Extras (h)',      data: dados.map(d => (d.extra/60).toFixed(2)), backgroundColor:'#7c3aed'},
        ]
    },
    options: {responsive:true, scales:{x:{stacked:true},y:{stacked:true,title:{display:true,text:'Horas'}}}}
});
</script>

</main></body></html>
