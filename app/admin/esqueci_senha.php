<?php
/**
 * Esqueci minha senha - envia link de reset por e-mail
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$erro = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail inválido';
    } else {
        $info = criar_token_reset($email);
        if ($info) {
            // Envia e-mail
            $base = 'https://dot-on.com.br/app';
            $link = "$base/admin/redefinir_senha.php?token=" . urlencode($info['token']);

            $html = "<!DOCTYPE html><html><body style='font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;padding:20px;color:#1e293b'>
<div style='max-width:560px;margin:auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)'>
<div style='background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;padding:24px;text-align:center'>
<h2 style='margin:0'>⏱ DOT-ON</h2>
<p style='margin:6px 0 0;opacity:.92'>Redefinição de Senha</p></div>
<div style='padding:30px'>
<h3>Olá, " . htmlspecialchars($info['nome']) . "</h3>
<p>Recebemos uma solicitação para redefinir a senha da sua conta DOT-ON. Clique no botão abaixo para criar uma nova senha:</p>
<p style='text-align:center;margin:24px 0'>
<a href='$link' style='display:inline-block;background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;padding:13px 30px;border-radius:8px;text-decoration:none;font-weight:700'>🔐 Redefinir Senha</a>
</p>
<p style='font-size:.85rem;color:#64748b'>Ou copie e cole este link no navegador:<br><code style='word-break:break-all'>$link</code></p>
<p style='font-size:.85rem;color:#94a3b8;margin-top:24px'>⏰ Este link é válido por 1 hora.<br>
Se você não solicitou esta alteração, ignore este e-mail.</p>
</div></div></body></html>";

            $texto = "Olá " . $info['nome'] . "\nClique no link para redefinir sua senha:\n$link\nVálido por 1 hora.";

            email_enviar($email, $info['nome'], 'DOT-ON · Redefinição de senha', $html, $texto);
            $ok = true;
        } else {
            // Por segurança, não revelamos se o e-mail existe ou não
            $ok = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Esqueci minha senha · DOT-ON</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#0c4a6e,#0284c7);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#1e293b}
.card{background:white;border-radius:14px;padding:40px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.brand{text-align:center;margin-bottom:24px}
.brand .icon{font-size:3rem;margin-bottom:8px}
.brand h1{font-size:1.4rem;color:#0c4a6e}
.brand p{color:#64748b;font-size:.9rem;margin-top:6px}
.alert{padding:11px 14px;border-radius:8px;font-size:.88rem;margin-bottom:16px}
.alert.err{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b}
.alert.success{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46}
.field{margin-bottom:14px}
.field label{display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:.95rem;outline:none}
.field input:focus{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.15)}
.btn{width:100%;padding:13px;border-radius:8px;border:none;background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;font-size:1rem;font-weight:700;cursor:pointer;margin-top:6px}
.btn:hover{transform:translateY(-1px)}
.links{margin-top:18px;text-align:center;font-size:.85rem}
.links a{color:#0284c7;text-decoration:none;font-weight:500}
</style>
</head>
<body>
<form class="card" method="post">
<div class="brand">
<div class="icon">🔑</div>
<h1>Esqueceu sua senha?</h1>
<p>Informe seu e-mail e enviaremos um link para você criar uma nova senha.</p>
</div>

<?php if ($erro): ?><div class="alert err">❌ <?=htmlspecialchars($erro)?></div><?php endif; ?>

<?php if ($ok): ?>
<div class="alert success">📧 Se este e-mail estiver cadastrado, você receberá um link em alguns minutos.</div>
<div class="links"><a href="login.php">← Voltar ao login</a></div>
<?php else: ?>
<div class="field">
<label>Seu e-mail cadastrado</label>
<input type="email" name="email" required autofocus placeholder="voce@empresa.com.br">
</div>
<button class="btn" type="submit">✉️ Enviar link de redefinição</button>
<div class="links"><a href="login.php">← Voltar ao login</a></div>
<?php endif; ?>
</form>
</body>
</html>
