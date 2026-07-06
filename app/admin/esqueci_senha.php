<?php
/**
 * Esqueci minha senha - envia link de reset por e-mail
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/icons.php';

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
body{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:#0f172a;min-height:100vh;
    display:flex;align-items:center;justify-content:center;padding:20px;-webkit-font-smoothing:antialiased;
    background:radial-gradient(1100px 600px at 100% -10%,#dbeafe 0,transparent 55%),
               radial-gradient(900px 500px at -10% 110%,#d1fae5 0,transparent 55%),#f8fafc}
.card{background:#fff;border-radius:20px;padding:38px 36px;width:100%;max-width:410px;border:1px solid #e2e8f0;box-shadow:0 20px 50px rgba(15,23,42,.10)}
.brand{display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:22px}
.brand .mark{display:flex;align-items:center;gap:10px;font-size:1.5rem;font-weight:800;letter-spacing:-.02em;margin-bottom:10px}
.brand h1{font-size:1.25rem;color:#0f172a}
.brand p{color:#64748b;font-size:.88rem;margin-top:6px}
.alert{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:10px;font-size:.86rem;margin-bottom:16px}
.alert.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert.success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.alert svg{flex-shrink:0}
.field{margin-bottom:14px}
.field label{display:block;font-size:.8rem;font-weight:600;color:#334155;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:.95rem;outline:none;font-family:inherit}
.field input:focus{border-color:#1d4ed8;box-shadow:0 0 0 3px rgba(29,78,216,.14)}
.btn{width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:10px;border:none;background:#1d4ed8;color:#fff;font-size:.98rem;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(29,78,216,.28);margin-top:6px;transition:all .15s;font-family:inherit}
.btn:hover{background:#1e40af}
.links{margin-top:18px;text-align:center;font-size:.85rem}
.links a{color:#1d4ed8;text-decoration:none;font-weight:500}
</style>
</head>
<body>
<form class="card" method="post">
<div class="brand">
<div class="mark"><?= icon_logo(30) ?><span>DOT-ON</span></div>
<h1>Esqueceu sua senha?</h1>
<p>Informe seu e-mail e enviaremos um link para você criar uma nova senha.</p>
</div>

<?php if ($erro): ?><div class="alert err"><?= icon('alert', 18) ?><span><?=htmlspecialchars($erro)?></span></div><?php endif; ?>

<?php if ($ok): ?>
<div class="alert success"><?= icon('mail', 18) ?><span>Se este e-mail estiver cadastrado, você receberá um link em alguns minutos.</span></div>
<div class="links"><a href="login.php">← Voltar ao login</a></div>
<?php else: ?>
<div class="field">
<label>Seu e-mail cadastrado</label>
<input type="email" name="email" required autofocus placeholder="voce@empresa.com.br">
</div>
<button class="btn" type="submit"><?= icon('mail', 17) ?><span>Enviar link de redefinição</span></button>
<div class="links"><a href="login.php">← Voltar ao login</a></div>
<?php endif; ?>
</form>
</body>
</html>
