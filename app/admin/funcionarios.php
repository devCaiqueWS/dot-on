<?php
$titulo = 'Funcionários'; $pagina = 'funcionarios';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/mailer.php';

$emp_id = $user['empresa_id'];
$msg = ''; $erro = '';

// Helper p/ senha temporária
function gerar_senha_temp_admin($len = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i=0; $i<$len; $i++) $s .= $chars[random_int(0, strlen($chars)-1)];
    return $s;
}

// AÇÕES POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user['perfil'], ['admin','rh','gestor'])) {
    csrf_check();

    // Reenviar credenciais
    if (!empty($_POST['acao']) && $_POST['acao'] === 'reenviar') {
        $uid = (int)$_POST['user_id'];
        $st = db()->prepare("SELECT * FROM dot_usuarios WHERE id=? AND empresa_id=?");
        $st->execute([$uid, $emp_id]);
        $u = $st->fetch();
        if ($u) {
            $senha_nova = gerar_senha_temp_admin();
            $hash = password_hash($senha_nova, PASSWORD_BCRYPT);
            db()->prepare("UPDATE dot_usuarios SET senha_hash=?, precisa_trocar_senha=1 WHERE id=?")->execute([$hash, $uid]);
            $url_exe = 'https://dot-on.com.br/app/downloads/DOT-ON-Agent.exe';
            $url_painel = 'https://dot-on.com.br/app/admin/login.php';
            [$assunto, $html, $texto] = email_template_boasvindas_funcionario($u['nome_completo'], $empresa['nome_fantasia'] ?: $empresa['razao_social'], $u['email'], $senha_nova, $url_exe, $url_painel);
            email_enviar($u['email'], $u['nome_completo'], $assunto, $html, $texto, $emp_id);
            $msg = "✅ Nova senha enviada para <strong>" . htmlspecialchars($u['email']) . "</strong>";
        }
    }
    // Ativar/Desativar
    elseif (!empty($_POST['acao']) && in_array($_POST['acao'], ['ativar','desativar'])) {
        $uid = (int)$_POST['user_id'];
        $ativo = $_POST['acao'] === 'ativar' ? 1 : 0;
        db()->prepare("UPDATE dot_usuarios SET ativo=? WHERE id=? AND empresa_id=?")
            ->execute([$ativo, $uid, $emp_id]);
        $msg = $ativo ? "✅ Funcionário ativado" : "🚫 Funcionário desativado";
    }
    // Criar novo
    elseif (!empty($_POST['nome']) && !empty($_POST['email'])) {
        $nome = trim($_POST['nome']);
        $email = strtolower(trim($_POST['email']));
        $matricula = trim($_POST['matricula'] ?? '');
        $cpf = preg_replace('/\D/','', $_POST['cpf'] ?? '');
        // Só admin pode criar perfis elevados; gestor/rh criam apenas funcionários.
        $perfis_permitidos = ($user['perfil'] === 'admin') ? ['funcionario','gestor','rh','admin'] : ['funcionario'];
        $perfil = in_array($_POST['perfil'] ?? '', $perfis_permitidos, true) ? $_POST['perfil'] : 'funcionario';
        $enviar_email = !empty($_POST['enviar_email']);

        // Verifica e-mail duplicado
        $st = db()->prepare("SELECT id FROM dot_usuarios WHERE email=?");
        $st->execute([$email]);
        if ($st->fetch()) {
            $erro = "Já existe um usuário com este e-mail.";
        } else {
            try {
                $senha_temp = gerar_senha_temp_admin();
                $hash = password_hash($senha_temp, PASSWORD_BCRYPT);
                $token = bin2hex(random_bytes(32));
                if (!$matricula) {
                    $st = db()->prepare("SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id=?");
                    $st->execute([$emp_id]);
                    $matricula = sprintf('FUN%03d', $st->fetchColumn() + 1);
                }
                db()->prepare("INSERT INTO dot_usuarios (empresa_id, escala_id, matricula, nome_completo, email, cpf, senha_hash, perfil, api_token, precisa_trocar_senha, ativo, criado_em)
                    VALUES (?, (SELECT id FROM dot_escalas WHERE empresa_id=? LIMIT 1), ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())")
                    ->execute([$emp_id, $emp_id, $matricula, $nome, $email, $cpf, $hash, $perfil, $token]);

                if ($enviar_email) {
                    $url_exe = 'https://dot-on.com.br/app/downloads/DOT-ON-Agent.exe';
                    $url_painel = 'https://dot-on.com.br/app/admin/login.php';
                    [$assunto, $html, $texto] = email_template_boasvindas_funcionario($nome, $empresa['nome_fantasia'] ?: $empresa['razao_social'], $email, $senha_temp, $url_exe, $url_painel);
                    email_enviar($email, $nome, $assunto, $html, $texto, $emp_id);
                    $msg = "✅ Funcionário criado e e-mail de boas-vindas enviado para <strong>$email</strong>";
                } else {
                    $msg = "✅ Funcionário criado. Senha temporária: <code>$senha_temp</code> (anote ou copie agora)";
                }
            } catch (Throwable $e) {
                error_log("DOT-ON funcionarios criar: " . $e->getMessage());
                $erro = "Não foi possível criar o funcionário. Verifique os dados e tente novamente.";
            }
        }
    }
}

// LISTA
$stmt = db()->prepare("SELECT u.*, e.nome AS esc_nome
    FROM dot_usuarios u
    LEFT JOIN dot_escalas e ON e.id=u.escala_id
    WHERE u.empresa_id=?
    ORDER BY u.ativo DESC, u.nome_completo");
$stmt->execute([$emp_id]);
$lista = $stmt->fetchAll();
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-erro">❌ <?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if (in_array($user['perfil'], ['admin','rh','gestor'])): ?>
<div class="panel">
    <h2>➕ Adicionar funcionário</h2>
    <form method="post" class="form-inline">
        <?= csrf_field() ?>
        <input name="matricula" placeholder="Matrícula (opcional)">
        <input name="nome" placeholder="Nome completo" required style="flex:2">
        <input name="email" type="email" placeholder="E-mail" required style="flex:2">
        <input name="cpf" placeholder="CPF (opcional)" maxlength="14">
        <select name="perfil">
            <option value="funcionario">Funcionário</option>
            <option value="gestor">Gestor</option>
            <option value="rh">RH</option>
        </select>
        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;color:#94a3b8">
            <input type="checkbox" name="enviar_email" value="1" checked>
            Enviar e-mail
        </label>
        <button class="btn btn-primary">Adicionar</button>
    </form>
    <p style="margin-top:10px;font-size:.85rem;color:#94a3b8">
    💡 <strong>Importar em lote:</strong> Para adicionar vários funcionários de uma vez, 
    <a href="funcionarios_importar.php" style="color:#38bdf8">use o importador de planilha CSV/XLSX</a>.
    </p>
</div>
<?php endif; ?>

<div class="panel">
    <h2>📋 Funcionários da <?= htmlspecialchars($empresa['nome_fantasia'] ?: $empresa['razao_social']) ?> (<?= count($lista) ?>)</h2>
    <table class="tbl">
        <thead><tr><th>Mat.</th><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Último login</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $u): ?>
            <tr style="<?= $u['ativo']?'':'opacity:.55' ?>">
                <td><code><?= htmlspecialchars($u['matricula']) ?></code></td>
                <td><?= htmlspecialchars($u['nome_completo']) ?>
                    <?php if ($u['precisa_trocar_senha']): ?><span class="tag" style="background:#fef3c7;color:#92400e;font-size:.7rem">🔑 1º login</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="tag"><?= $u['perfil'] ?></span></td>
                <td><?= $u['ativo'] ? '<span style="color:#10b981">●</span> Ativo' : '<span style="color:#94a3b8">●</span> Inativo' ?></td>
                <td><?= $u['ultimo_login'] ? date('d/m H:i', strtotime($u['ultimo_login'])) : '—' ?></td>
                <td style="display:flex;gap:6px">
                    <?php if (in_array($user['perfil'], ['admin','rh','gestor'])): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Gerar nova senha temporária e enviar e-mail?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="reenviar">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button class="btn btn-sm" title="Reenviar credenciais">📧</button>
                    </form>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="<?= $u['ativo']?'desativar':'ativar' ?>">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button class="btn btn-sm" title="<?= $u['ativo']?'Desativar':'Ativar' ?>"><?= $u['ativo']?'🚫':'✅' ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; if (!$lista): ?>
            <tr><td colspan="7" class="empty">📭 Nenhum funcionário cadastrado ainda. Use o formulário acima ou <a href="funcionarios_importar.php">importe uma planilha</a>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main></body></html>
