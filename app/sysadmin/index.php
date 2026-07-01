<?php
$pagina = 'dashboard';
$titulo = 'Dashboard SuperAdmin';
require_once __DIR__ . '/_layout.php';

$pdo = db();

// Métricas globais
$total_empresas = $pdo->query("SELECT COUNT(*) FROM dot_empresas")->fetchColumn();
$empresas_ativas = $pdo->query("SELECT COUNT(*) FROM dot_empresas WHERE ativo = 1")->fetchColumn();
$empresas_trial = $pdo->query("SELECT COUNT(*) FROM dot_empresas WHERE trial_expira >= CURDATE()")->fetchColumn();
$empresas_pagas = $pdo->query("SELECT COUNT(*) FROM dot_empresas WHERE plano IN ('basic','pro','enterprise')")->fetchColumn();

$total_usuarios = $pdo->query("SELECT COUNT(*) FROM dot_usuarios WHERE perfil != 'super_admin'")->fetchColumn();
$total_funcionarios = $pdo->query("SELECT COUNT(*) FROM dot_usuarios WHERE perfil = 'funcionario'")->fetchColumn();
$usuarios_hoje = $pdo->query("SELECT COUNT(*) FROM dot_usuarios WHERE DATE(ultimo_login) = CURDATE()")->fetchColumn();

$batidas_hoje = $pdo->query("SELECT COUNT(*) FROM dot_batidas WHERE DATE(momento) = CURDATE()")->fetchColumn();
$batidas_total = $pdo->query("SELECT COUNT(*) FROM dot_batidas")->fetchColumn();

$sessoes_abertas = $pdo->query("SELECT COUNT(*) FROM dot_sessoes WHERE status='aberta' AND data_ref=CURDATE()")->fetchColumn();

// Últimas empresas cadastradas
$ultimas = $pdo->query("SELECT id, cnpj, nome_fantasia, razao_social, plano, ativo, trial_expira, criado_em,
    (SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id = dot_empresas.id AND perfil='funcionario') AS qtd_funcs
    FROM dot_empresas ORDER BY id DESC LIMIT 10")->fetchAll();
?>

<h1>📊 Dashboard SyscomAI</h1>

<div class="cards">
    <div class="card success">
        <div class="label">🏢 Empresas Totais</div>
        <div class="value"><?= $total_empresas ?></div>
        <div class="sub"><?= $empresas_ativas ?> ativas · <?= $empresas_pagas ?> pagantes</div>
    </div>
    <div class="card warning">
        <div class="label">⏰ Em Trial</div>
        <div class="value"><?= $empresas_trial ?></div>
        <div class="sub">trial ativo (30 dias)</div>
    </div>
    <div class="card info">
        <div class="label">👥 Usuários Totais</div>
        <div class="value"><?= $total_usuarios ?></div>
        <div class="sub"><?= $total_funcionarios ?> funcionários · <?= $usuarios_hoje ?> ativos hoje</div>
    </div>
    <div class="card danger">
        <div class="label">⏱️ Batidas Hoje</div>
        <div class="value"><?= $batidas_hoje ?></div>
        <div class="sub"><?= $batidas_total ?> totais · <?= $sessoes_abertas ?> sessões abertas agora</div>
    </div>
</div>

<div class="panel">
    <h2 style="margin-top:0;">🆕 Últimas Empresas Cadastradas</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Empresa</th>
                <th>CNPJ</th>
                <th>Plano</th>
                <th>Funcs</th>
                <th>Trial</th>
                <th>Status</th>
                <th>Cadastro</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ultimas as $e): ?>
            <tr>
                <td>#<?= $e['id'] ?></td>
                <td><strong><?= htmlspecialchars($e['nome_fantasia'] ?: $e['razao_social']) ?></strong></td>
                <td class="muted"><?= htmlspecialchars($e['cnpj']) ?></td>
                <td><span class="badge-pill <?= htmlspecialchars($e['plano']) ?>"><?= htmlspecialchars($e['plano']) ?></span></td>
                <td><?= $e['qtd_funcs'] ?></td>
                <td class="muted">
                    <?php if ($e['trial_expira']): ?>
                        <?php
                        $dias = (strtotime($e['trial_expira']) - time()) / 86400;
                        if ($dias < 0): ?>
                            <span style="color:#ef4444;">expirado</span>
                        <?php elseif ($dias < 7): ?>
                            <span style="color:#f59e0b;"><?= ceil($dias) ?>d restantes</span>
                        <?php else: ?>
                            <?= date('d/m/Y', strtotime($e['trial_expira'])) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($e['ativo']): ?>
                        <span class="badge-pill active">ativa</span>
                    <?php else: ?>
                        <span class="badge-pill inactive">inativa</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= date('d/m/Y H:i', strtotime($e['criado_em'])) ?></td>
                <td><a href="empresas.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline">Ver</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$ultimas): ?>
            <tr><td colspan="9" style="text-align:center; padding:30px; color:#64748b;">Nenhuma empresa cadastrada ainda. <a href="../signup.php" style="color:#3b82f6;">Criar primeira empresa →</a></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2 style="margin-top:0;">🚀 Ações Rápidas</h2>
    <a href="empresas.php" class="btn">📋 Listar todas empresas</a>
    <a href="metricas.php" class="btn btn-outline">📈 Métricas detalhadas</a>
    <a href="instaladores.php" class="btn btn-outline">📦 Gerar instaladores</a>
    <a href="auditoria.php" class="btn btn-outline">🔍 Log de auditoria</a>
    <a href="../signup.php" target="_blank" class="btn btn-outline">+ Novo cadastro (página pública)</a>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
