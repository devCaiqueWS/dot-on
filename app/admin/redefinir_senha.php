<?php
/**
 * Redefinir senha via token recebido por e-mail
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$user = validar_token_reset($token);
$erro = '';

if (!$user) {
    $erro = 'Link inválido ou expirado. Solicite outro link.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova = $_POST['nova_senha'] ?? '';
    $conf = $_POST['confirma_senha'] ?? '';
    if (strlen($nova) < 8) {
        $erro = 'Senha precisa ter no mínimo 8 caracteres';
    } elseif ($nova !== $conf) {
        $erro = 'As senhas não coincidem';
    } else {
        trocar_senha_usuario($user['id'], $nova);
        header('Location: login.php?msg=senha_alterada');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redefinir senha · DOT-ON</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#0c4a6e,#0284c7);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#1e293b}
.card{background:white;border-radius:14px;padding:40px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.brand{text-align:center;margin-bottom:24px}
.brand .icon{font-size:3rem;margin-bottom:8px}
.brand h1{font-size:1.4rem;color:#0c4a6e}
.brand p{color:#64748b;font-size:.9rem;margin-top:6px}
.alert{padding:11px 14px;border-radius:8px;font-size:.88rem;margin-bottom:16px}
.alert.err{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b}
.field{margin-bottom:14px}
.field label{display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:.95rem;outline:none}
.field input:focus{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.15)}
.btn{width:100%;padding:13px;border-radius:8px;border:none;background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;font-size:1rem;font-weight:700;cursor:pointer}
.btn:hover{transform:translateY(-1px)}
.links{margin-top:18px;text-align:center;font-size:.85rem}
.links a{color:#0284c7;text-decoration:none;font-weight:500}
</style>
</head>
<body>
<form class="card" method="post">
<div class="brand">
<div class="icon">🔐</div>
<h1>Crie sua nova senha</h1>
<?php if ($user): ?>
<p>Olá, <strong><?=htmlspecialchars($user['nome_completo'])?></strong></p>
<?php endif; ?>
</div>

<?php if ($erro): ?><div class="alert err">❌ <?=htmlspecialchars($erro)?></div><?php endif; ?>

<?php if ($user): ?>
<input type="hidden" name="token" value="<?=htmlspecialchars($token)?>">

<div class="field">
<label>Nova senha</label>
<input type="password" name="nova_senha" required autofocus minlength="8" placeholder="Mínimo 8 caracteres">
</div>

<div class="field">
<label>Confirme a senha</label>
<input type="password" name="confirma_senha" required minlength="8">
</div>

<button class="btn" type="submit">✅ Salvar nova senha</button>
<?php else: ?>
<div class="links"><a href="esqueci_senha.php">Solicitar novo link de redefinição</a></div>
<?php endif; ?>

<div class="links" style="margin-top:24px;border-top:1px solid #e2e8f0;padding-top:14px"><a href="login.php">← Voltar ao login</a></div>
</form>
</body>
</html>
