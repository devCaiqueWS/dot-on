<?php
$pagina = 'metricas'; $titulo = 'Métricas globais';
require_once __DIR__ . '/_layout.php';

$pdo = db();

// Crescimento por dia (últimos 30 dias)
$crescimento = $pdo->query("
    SELECT DATE(criado_em) AS dia, COUNT(*) AS qtd
    FROM dot_empresas
    WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(criado_em) ORDER BY dia
")->fetchAll();

$batidas_dia = $pdo->query("
    SELECT DATE(momento) AS dia, COUNT(*) AS qtd
    FROM dot_batidas WHERE momento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(momento) ORDER BY dia
")->fetchAll();

$por_plano = $pdo->query("SELECT plano, COUNT(*) AS qtd FROM dot_empresas GROUP BY plano")->fetchAll();
$por_setor = $pdo->query("SELECT setor, COUNT(*) AS qtd FROM dot_empresas WHERE setor IS NOT NULL AND setor <> '' GROUP BY setor ORDER BY qtd DESC LIMIT 10")->fetchAll();

$top_empresas = $pdo->query("
    SELECT e.id, e.nome_fantasia, e.razao_social, e.plano,
           COUNT(DISTINCT u.id) AS total_users, COUNT(b.id) AS total_batidas
    FROM dot_empresas e
    LEFT JOIN dot_usuarios u ON u.empresa_id = e.id AND u.perfil = 'funcionario'
    LEFT JOIN dot_batidas b ON b.empresa_id = e.id
    GROUP BY e.id ORDER BY total_batidas DESC LIMIT 10
")->fetchAll();
?>

<h1>📈 Métricas Globais SyscomAI</h1>

<div class="cards">
    <div class="card success">
        <div class="label">Empresas (30d)</div>
        <div class="value"><?= array_sum(array_column($crescimento,'qtd')) ?></div>
        <div class="sub">cadastros nos últimos 30 dias</div>
    </div>
    <div class="card info">
        <div class="label">Batidas (30d)</div>
        <div class="value"><?= number_format(array_sum(array_column($batidas_dia,'qtd')), 0, ',', '.') ?></div>
        <div class="sub">total nos últimos 30 dias</div>
    </div>
    <div class="card warning">
        <div class="label">Média batidas/dia</div>
        <div class="value"><?= count($batidas_dia)?number_format(array_sum(array_column($batidas_dia,'qtd'))/max(1,count($batidas_dia)), 0):0 ?></div>
        <div class="sub">considerando dias com atividade</div>
    </div>
</div>

<div class="panel">
    <h2 style="margin-top:0;">💳 Empresas por plano</h2>
    <table>
        <thead><tr><th>Plano</th><th>Quantidade</th></tr></thead>
        <tbody>
        <?php foreach ($por_plano as $p): ?>
            <tr><td><span class="badge-pill <?= htmlspecialchars($p['plano']) ?>"><?= htmlspecialchars($p['plano']) ?></span></td><td><?= $p['qtd'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($por_setor): ?>
<div class="panel">
    <h2 style="margin-top:0;">🏭 Top setores</h2>
    <table>
        <thead><tr><th>Setor</th><th>Empresas</th></tr></thead>
        <tbody>
        <?php foreach ($por_setor as $s): ?>
            <tr><td><?= htmlspecialchars($s['setor']) ?></td><td><?= $s['qtd'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="panel">
    <h2 style="margin-top:0;">🏆 Top 10 empresas mais ativas</h2>
    <table>
        <thead><tr><th>#</th><th>Empresa</th><th>Plano</th><th>Funcs</th><th>Batidas</th></tr></thead>
        <tbody>
        <?php foreach ($top_empresas as $e): ?>
            <tr>
                <td>#<?= $e['id'] ?></td>
                <td><a href="empresas.php?id=<?= $e['id'] ?>" style="color:#3b82f6;"><?= htmlspecialchars($e['nome_fantasia'] ?: $e['razao_social']) ?></a></td>
                <td><span class="badge-pill <?= htmlspecialchars($e['plano']) ?>"><?= htmlspecialchars($e['plano']) ?></span></td>
                <td><?= $e['total_users'] ?></td>
                <td><?= number_format($e['total_batidas'], 0, ',', '.') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
