<?php
$pagina = 'smtp'; $titulo = 'Configuração SMTP global';
require_once __DIR__ . '/_layout.php';

$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar') {
    csrf_check();
    require_once __DIR__ . '/../includes/mailer.php';
    $para = $_POST['destinatario'] ?? '';
    if (filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $ok = email_enviar($para, 'Administrador', 'Teste SMTP — DOT-ON SyscomAI', '<h2>✓ SMTP funcionando!</h2><p>Este é um teste enviado pelo Painel SuperAdmin do DOT-ON em ' . date('d/m/Y H:i:s') . '.</p>');
        $msg = $ok ? "✓ E-mail de teste enviado para $para com sucesso." : "✗ Falha no envio do e-mail.";
    } else {
        $msg = "✗ E-mail inválido.";
    }
}

$smtp = $pdo->query("SELECT * FROM dot_smtp_config WHERE ativo=1 LIMIT 1")->fetch();
$fila_pendente = $pdo->query("SELECT COUNT(*) FROM dot_email_fila WHERE enviado=0")->fetchColumn();
$fila_enviado = $pdo->query("SELECT COUNT(*) FROM dot_email_fila WHERE enviado=1")->fetchColumn();
$ultimos = $pdo->query("SELECT * FROM dot_email_fila ORDER BY id DESC LIMIT 20")->fetchAll();
?>

<h1>📧 Configuração SMTP & Fila de E-mails</h1>

<?php if ($msg): ?><div class="alert <?= strpos($msg,'✓')===0?'success':'error' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="cards">
    <div class="card success">
        <div class="label">📤 E-mails enviados</div>
        <div class="value"><?= $fila_enviado ?></div>
    </div>
    <div class="card warning">
        <div class="label">⏳ Pendentes na fila</div>
        <div class="value"><?= $fila_pendente ?></div>
    </div>
</div>

<div class="panel">
    <h2 style="margin-top:0;">⚙️ Configuração ativa</h2>
    <?php if ($smtp): ?>
        <table>
            <tr><th style="width:200px;">Host</th><td><?= htmlspecialchars($smtp['host']) ?></td></tr>
            <tr><th>Porta</th><td><?= htmlspecialchars($smtp['port']) ?></td></tr>
            <tr><th>Usuário</th><td><?= htmlspecialchars($smtp['username']) ?></td></tr>
            <tr><th>Senha</th><td>••••••••</td></tr>
            <tr><th>Criptografia</th><td><?= htmlspecialchars($smtp['encryption']) ?></td></tr>
            <tr><th>From</th><td><?= htmlspecialchars($smtp['from_name'] . ' <' . $smtp['from_email'] . '>') ?></td></tr>
            <tr><th>Status</th><td><?= $smtp['ativo']?'<span class="badge-pill active">ativo</span>':'<span class="badge-pill inactive">inativo</span>' ?></td></tr>
        </table>
    <?php else: ?>
        <div class="alert error">⚠️ Nenhuma configuração SMTP ativa.</div>
    <?php endif; ?>
</div>

<div class="panel">
    <h2 style="margin-top:0;">🧪 Testar envio</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="testar">
        <input type="email" name="destinatario" placeholder="seu@email.com.br" required style="width:300px;">
        <button class="btn">📨 Enviar e-mail de teste</button>
    </form>
</div>

<div class="panel">
    <h2 style="margin-top:0;">📋 Últimos 20 e-mails na fila</h2>
    <table>
        <thead><tr><th>#</th><th>Para</th><th>Assunto</th><th>Status</th><th>Tentativas</th><th>Quando</th></tr></thead>
        <tbody>
        <?php foreach ($ultimos as $e): ?>
            <tr>
                <td>#<?= $e['id'] ?></td>
                <td><?= htmlspecialchars($e['para_email']) ?></td>
                <td><?= htmlspecialchars(substr($e['assunto'], 0, 60)) ?></td>
                <td><?= $e['enviado'] ? '<span class="badge-pill active">enviado</span>' : '<span class="badge-pill trial">pendente</span>' ?></td>
                <td><?= $e['tentativas'] ?></td>
                <td class="muted"><?= date('d/m H:i', strtotime($e['criado_em'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$ultimos): ?>
            <tr><td colspan="6" style="text-align:center; padding:30px; color:#64748b;">Nenhum e-mail na fila ainda.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
