<?php
$pagina = 'auditoria'; $titulo = 'Log de auditoria';
require_once __DIR__ . '/_layout.php';

$pdo = db();
$logs = $pdo->query("
    SELECT l.*, u.nome_completo, e.nome_fantasia, e.razao_social
    FROM dot_sysadmin_log l
    LEFT JOIN dot_usuarios u ON u.id = l.super_admin_id
    LEFT JOIN dot_empresas e ON e.id = l.empresa_id
    ORDER BY l.id DESC LIMIT 200
")->fetchAll();
?>

<h1>🔍 Log de Auditoria SuperAdmin</h1>

<div class="panel">
    <h2 style="margin-top:0;">Últimas 200 ações</h2>
    <table>
        <thead><tr><th>Data</th><th>Super Admin</th><th>Ação</th><th>Empresa</th><th>Detalhes</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <tr>
                <td class="muted"><?= date('d/m H:i:s', strtotime($l['criado_em'])) ?></td>
                <td><?= htmlspecialchars($l['nome_completo'] ?: '-') ?></td>
                <td><strong><?= htmlspecialchars($l['acao']) ?></strong></td>
                <td><?= $l['empresa_id'] ? '<a href="empresas.php?id='.$l['empresa_id'].'" style="color:#3b82f6;">'.htmlspecialchars($l['nome_fantasia'] ?: $l['razao_social'] ?: '#'.$l['empresa_id']).'</a>' : '—' ?></td>
                <td class="muted" style="font-size:11px;"><?= htmlspecialchars($l['detalhes'] ?: '') ?></td>
                <td class="muted"><?= htmlspecialchars($l['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?>
            <tr><td colspan="6" style="text-align:center; padding:30px; color:#64748b;">Nenhum log ainda.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
