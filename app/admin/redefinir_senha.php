<?php
/**
 * Redefinir senha via token recebido por e-mail
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/icons.php';

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
body{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:#0f172a;min-height:100vh;
    display:flex;align-items:center;justify-content:center;padding:20px;-webkit-font-smoothing:antialiased;
    background:radial-gradient(1100px 600px at 100% -10%,#dbeafe 0,transparent 55%),
               radial-gradient(900px 500px at -10% 110%,#d1fae5 0,transparent 55%),#f8fafc}
.card{background:#fff;border-radius:20px;padding:38px 36px;width:100%;max-width:440px;border:1px solid #e2e8f0;box-shadow:0 20px 50px rgba(15,23,42,.10)}
.brand{display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:22px}
.brand .mark{display:flex;align-items:center;gap:10px;font-size:1.5rem;font-weight:800;letter-spacing:-.02em;margin-bottom:10px}
.brand h1{font-size:1.25rem;color:#0f172a}
.brand p{color:#64748b;font-size:.88rem;margin-top:6px}
.alert{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:10px;font-size:.86rem;margin-bottom:16px}
.alert.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert svg{flex-shrink:0}
.field{margin-bottom:14px}
.field label{display:block;font-size:.8rem;font-weight:600;color:#334155;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:.95rem;outline:none;font-family:inherit}
.field input:focus{border-color:#1d4ed8;box-shadow:0 0 0 3px rgba(29,78,216,.14)}
.btn{width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:10px;border:none;background:#1d4ed8;color:#fff;font-size:.98rem;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(29,78,216,.28);transition:all .15s;font-family:inherit}
.btn:hover{background:#1e40af}
.links{margin-top:18px;text-align:center;font-size:.85rem}
.links a{color:#1d4ed8;text-decoration:none;font-weight:500}
</style>
</head>
<body>
<form class="card" method="post">
<div class="brand">
<div class="mark"><?= icon_logo(30) ?><span>DOT-ON</span></div>
<h1>Crie sua nova senha</h1>
<?php if ($user): ?>
<p>Olá, <strong><?=htmlspecialchars($user['nome_completo'])?></strong></p>
<?php endif; ?>
</div>

<?php if ($erro): ?><div class="alert err"><?= icon('alert', 18) ?><span><?=htmlspecialchars($erro)?></span></div><?php endif; ?>

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

<button class="btn" type="submit"><?= icon('check', 18) ?><span>Salvar nova senha</span></button>
<?php else: ?>
<div class="links"><a href="esqueci_senha.php">Solicitar novo link de redefinição</a></div>
<?php endif; ?>

<div class="links" style="margin-top:24px;border-top:1px solid #e2e8f0;padding-top:14px"><a href="login.php">← Voltar ao login</a></div>
</form>
</body>
</html>
