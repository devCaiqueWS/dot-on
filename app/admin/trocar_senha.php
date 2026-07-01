<?php
/**
 * Tela obrigatória de troca de senha no 1º login
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = requer_login(true); // permite acessar nesta tela mesmo com flag

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $senha_atual = $_POST['senha_atual'] ?? '';
    $nova = $_POST['nova_senha'] ?? '';
    $conf = $_POST['confirma_senha'] ?? '';

    // Busca senha hash atual
    $st = db()->prepare("SELECT senha_hash FROM dot_usuarios WHERE id = ?");
    $st->execute([$user['id']]);
    $row = $st->fetch();

    if (!password_verify($senha_atual, $row['senha_hash'])) {
        $erro = 'Senha atual incorreta';
    } elseif (strlen($nova) < 8) {
        $erro = 'A nova senha precisa ter no mínimo 8 caracteres';
    } elseif ($nova !== $conf) {
        $erro = 'As senhas não coincidem';
    } elseif ($nova === $senha_atual) {
        $erro = 'A nova senha deve ser diferente da atual';
    } else {
        trocar_senha_usuario($user['id'], $nova);
        $_SESSION['dot_user']['precisa_trocar_senha'] = 0;
        header('Location: index.php?msg=senha_trocada');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trocar senha · DOT-ON</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#0c4a6e,#0284c7);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#1e293b}
.card{background:white;border-radius:14px;padding:40px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.brand{text-align:center;margin-bottom:24px}
.brand .icon{font-size:3rem;margin-bottom:8px}
.brand h1{font-size:1.4rem;color:#0c4a6e;margin-bottom:6px}
.brand p{color:#64748b;font-size:.9rem}
.alert{padding:11px 14px;border-radius:8px;font-size:.88rem;margin-bottom:16px}
.alert.err{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b}
.alert.info{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a;font-size:.83rem}
.field{margin-bottom:14px}
.field label{display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:.95rem;outline:none}
.field input:focus{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.15)}
.requirements{background:#f1f5f9;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:.82rem;color:#475569}
.requirements li{margin:3px 0;list-style:none;padding-left:24px;position:relative}
.requirements li::before{content:'○';position:absolute;left:8px;color:#94a3b8}
.requirements li.ok{color:#059669}
.requirements li.ok::before{content:'✓'}
.btn{width:100%;padding:13px;border-radius:8px;border:none;background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;font-size:1rem;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(2,132,199,.3);margin-top:6px}
.btn:hover{transform:translateY(-1px)}
</style>
</head>
<body>
<form class="card" method="post" id="form">
<?= csrf_field() ?>
<div class="brand">
<div class="icon">🔐</div>
<h1>Defina sua nova senha</h1>
<p>Como é seu primeiro acesso, você precisa criar uma senha pessoal.</p>
</div>

<?php if ($erro): ?><div class="alert err">❌ <?=htmlspecialchars($erro)?></div><?php endif; ?>

<div class="alert info">👤 Olá <strong><?=htmlspecialchars($user['nome_completo'])?></strong>. Por segurança, é necessário alterar a senha temporária recebida por e-mail.</div>

<div class="field">
<label>Senha atual (a que veio no e-mail)</label>
<input type="password" name="senha_atual" required autofocus>
</div>

<div class="field">
<label>Nova senha</label>
<input type="password" name="nova_senha" id="nova" required minlength="8">
</div>

<div class="requirements">
<ul style="padding-left:0">
<li id="r_len">Mínimo 8 caracteres</li>
<li id="r_num">Pelo menos 1 número</li>
<li id="r_letra">Pelo menos 1 letra</li>
</ul>
</div>

<div class="field">
<label>Confirme a nova senha</label>
<input type="password" name="confirma_senha" id="conf" required minlength="8">
</div>

<button class="btn" type="submit">✅ Salvar nova senha</button>
</form>

<script>
const nova = document.getElementById('nova');
const r_len = document.getElementById('r_len');
const r_num = document.getElementById('r_num');
const r_letra = document.getElementById('r_letra');
nova.addEventListener('input', () => {
    const v = nova.value;
    r_len.classList.toggle('ok', v.length >= 8);
    r_num.classList.toggle('ok', /\d/.test(v));
    r_letra.classList.toggle('ok', /[a-zA-Z]/.test(v));
});
</script>
</body>
</html>
