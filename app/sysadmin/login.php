<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $u = autenticar($email, $senha);
    if (!$u) {
        $erro = 'Credenciais inválidas.';
    } elseif ($u['perfil'] !== 'super_admin') {
        $erro = 'Acesso restrito a Super Administradores SyscomAI.';
    } else {
        login_sessao($u);
        // Log de acesso
        db()->prepare("INSERT INTO dot_sysadmin_log (super_admin_id, acao, ip) VALUES (?, 'login', ?)")
            ->execute([$u['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
        header('Location: index.php');
        exit;
    }
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>SuperAdmin SyscomAI · Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; color:#e2e8f0; }
.card { background:#1e293b; padding:36px 32px; border-radius:14px; box-shadow:0 24px 48px rgba(0,0,0,0.5); width:380px; border:1px solid #334155; }
.brand { text-align:center; margin-bottom:24px; }
.brand h1 { font-size:22px; color:#fff; margin-bottom:6px; }
.brand .badge { background:#dc2626; color:#fff; padding:3px 10px; border-radius:4px; font-size:11px; letter-spacing:0.5px; }
.brand p { color:#94a3b8; font-size:13px; margin-top:8px; }
.field { margin-bottom:14px; }
.field label { display:block; color:#cbd5e1; font-size:13px; margin-bottom:6px; }
.field input { width:100%; background:#0f172a; border:1px solid #475569; color:#e2e8f0; padding:11px 14px; border-radius:8px; font-size:14px; }
.field input:focus { outline:none; border-color:#dc2626; }
.btn { width:100%; padding:12px; background:#dc2626; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer; margin-top:10px; }
.btn:hover { background:#b91c1c; }
.alert { padding:10px 14px; background:rgba(239,68,68,0.15); border:1px solid #ef4444; color:#fecaca; border-radius:8px; margin-bottom:16px; font-size:13px; }
.footer { text-align:center; margin-top:18px; font-size:12px; color:#64748b; }
.footer a { color:#94a3b8; text-decoration:none; }
</style>
</head>
<body>
<div class="card">
    <div class="brand">
        <h1>🛡️ DOT-ON SuperAdmin</h1>
        <span class="badge">SYSCOMAI</span>
        <p>Painel restrito · acesso apenas para administradores SyscomAI</p>
    </div>
    <?php if ($erro): ?>
        <div class="alert">⚠️ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="field">
            <label>E-mail</label>
            <input type="email" name="email" required autofocus placeholder="pierre@syscomai.com.br" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Senha</label>
            <input type="password" name="senha" required>
        </div>
        <button type="submit" class="btn">🔓 Entrar como SuperAdmin</button>
    </form>
    <div class="footer">
        <a href="../">← voltar para o site</a>
    </div>
</div>
</body>
</html>
