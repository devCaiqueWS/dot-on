<?php
/**
 * Tela de login web - DOT-ON SaaS
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$erro = '';
// Evita open redirect: só aceita caminhos locais relativos (sem host/esquema).
$back = $_GET['back'] ?? 'index.php';
if (!is_string($back) || $back === '' || preg_match('#^(?:[a-z]+:)?//#i', $back) || $back[0] === '/' || strpos($back, "\\") !== false) {
    $back = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $user = autenticar($email, $senha);
    if ($user) {
        login_sessao($user);
        // Se precisa trocar senha, vai para tela de troca
        if (!empty($user['precisa_trocar_senha'])) {
            header('Location: trocar_senha.php');
            exit;
        }
        // Funcionário vai para portal /me/, demais para admin
        if ($user['perfil'] === 'funcionario' && $back === 'index.php') {
            header('Location: ../me/');
            exit;
        }
        header('Location: ' . $back);
        exit;
    }
    $erro = 'E-mail ou senha incorretos';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login · DOT-ON</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#0c4a6e,#0284c7);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#1e293b}
.card{background:white;border-radius:14px;padding:40px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.brand{text-align:center;margin-bottom:28px}
.brand .logo{font-size:1.8rem;font-weight:800;color:#0284c7;margin-bottom:6px}
.brand .sub{color:#64748b;font-size:.9rem}
.alert{padding:11px 14px;border-radius:8px;font-size:.88rem;margin-bottom:16px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b}
.alert.success{background:#d1fae5;border-color:#6ee7b7;color:#065f46}
.field{margin-bottom:14px}
.field label{display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:.95rem;outline:none;transition:all .15s}
.field input:focus{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.15)}
.btn{width:100%;padding:13px;border-radius:8px;border:none;background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;font-size:1rem;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(2,132,199,.3);margin-top:6px}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(2,132,199,.4)}
.links{margin-top:20px;display:flex;justify-content:space-between;font-size:.85rem}
.links a{color:#0284c7;text-decoration:none;font-weight:500}
.links a:hover{text-decoration:underline}
.divider{margin:20px 0;padding-top:18px;border-top:1px solid #e2e8f0;text-align:center;color:#64748b;font-size:.85rem}
.divider .new-link{background:linear-gradient(135deg,#10b981,#059669);color:white;display:inline-block;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:600;margin-top:10px}
</style>
</head>
<body>
<form class="card" method="post">
<div class="brand">
<div class="logo">⏱ DOT-ON</div>
<div class="sub">Controle de Ponto Digital</div>
</div>

<?php if ($erro): ?><div class="alert">❌ <?=htmlspecialchars($erro)?></div><?php endif; ?>
<?php if (!empty($_GET['msg']) && $_GET['msg']==='senha_alterada'): ?><div class="alert success">✅ Senha alterada com sucesso! Faça login.</div><?php endif; ?>
<?php if (!empty($_GET['msg']) && $_GET['msg']==='reset_enviado'): ?><div class="alert success">📧 Verifique seu e-mail para redefinir a senha.</div><?php endif; ?>

<div class="field">
<label>E-mail</label>
<input type="email" name="email" required autofocus placeholder="voce@empresa.com.br" value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
</div>
<div class="field">
<label>Senha</label>
<input type="password" name="senha" required placeholder="••••••••">
</div>
<button class="btn" type="submit">🔓 Entrar</button>

<div class="links">
<a href="esqueci_senha.php">Esqueci minha senha</a>
<a href="../">← Voltar ao site</a>
</div>

<div class="divider">
Ainda não tem conta?<br>
<a href="../signup.php" class="new-link">🚀 Criar conta grátis</a>
</div>
</form>
</body>
</html>
