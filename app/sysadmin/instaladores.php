<?php
$pagina = 'instaladores';
$titulo = 'Instaladores personalizados';
require_once __DIR__ . '/_layout.php';

$pdo = db();
$msg = ''; $msg_tipo = '';
$empresa_id = (int)($_GET['empresa'] ?? 0);

// Gerar novo slug/link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'gerar') {
    csrf_check();
    $emp_id = (int)$_POST['empresa_id'];
    $stmt = $pdo->prepare("SELECT id, slug, nome_fantasia, razao_social FROM dot_empresas WHERE id=?");
    $stmt->execute([$emp_id]);
    $emp = $stmt->fetch();
    if ($emp) {
        $slug = $emp['slug'];
        if (!$slug) {
            // Gera slug a partir do nome
            $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $emp['nome_fantasia'] ?: $emp['razao_social']));
            $base = trim($base, '-');
            $slug = $base;
            $i = 1;
            while (true) {
                $check = $pdo->prepare("SELECT id FROM dot_empresas WHERE slug=? AND id<>?");
                $check->execute([$slug, $emp_id]);
                if (!$check->fetch()) break;
                $slug = $base . '-' . (++$i);
            }
            $pdo->prepare("UPDATE dot_empresas SET slug=? WHERE id=?")->execute([$slug, $emp_id]);
            sysadmin_log('gerar_instalador', $emp_id, ['slug'=>$slug]);
        }
        $msg = "✓ Link de instalação gerado: /app/install/$slug";
        $msg_tipo = 'success';
    }
}

// Listar empresas com slug
$empresas = $pdo->query("SELECT id, slug, nome_fantasia, razao_social, plano, ativo,
    (SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id=dot_empresas.id AND perfil='funcionario') AS qtd_funcs
    FROM dot_empresas ORDER BY id DESC")->fetchAll();

$base_url = (($_SERVER['HTTPS']??'')==='on'?'https':'http') . '://' . $_SERVER['HTTP_HOST'] . '/app';
?>

<h1>📦 Instaladores Personalizados</h1>

<?php if ($msg): ?><div class="alert <?= $msg_tipo ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="panel">
    <h2 style="margin-top:0;">Como funciona</h2>
    <p style="color:#cbd5e1; line-height:1.7;">
        Cada empresa tem uma <strong>página única de instalação</strong> que entrega o agente Windows
        (<code>DOT-ON-Agent.exe</code>) com o endereço do servidor de produção já embutido.
    </p>
    <ul style="color:#94a3b8; margin:12px 0 12px 24px; line-height:1.8;">
        <li>⬇ Entrega <strong>apenas o executável</strong> — o funcionário não vê código nem configura nada</li>
        <li>🌐 URL da API embutida no app (https://dot-on.com.br/app/api/)</li>
        <li>🏢 A empresa do funcionário é identificada no <strong>login</strong> (e-mail → empresa)</li>
        <li>🎨 Logo/cores da empresa (futuramente)</li>
    </ul>
    <p style="color:#cbd5e1;">
        Quando o funcionário acessa o link da empresa, baixa o <code>.exe</code> e dá duplo-clique.
        Não precisa configurar URL, empresa, nada. Só fazer login.
    </p>
</div>

<div class="panel">
    <h2 style="margin-top:0;">🏢 Empresas e seus instaladores</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Empresa</th>
                <th>Slug</th>
                <th>Link de instalação</th>
                <th>Funcs</th>
                <th>Ação</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($empresas as $e): ?>
            <tr>
                <td>#<?= $e['id'] ?></td>
                <td><strong><?= htmlspecialchars($e['nome_fantasia'] ?: $e['razao_social']) ?></strong></td>
                <td>
                    <?php if ($e['slug']): ?>
                        <code style="background:#0f172a; padding:3px 8px; border-radius:4px;"><?= htmlspecialchars($e['slug']) ?></code>
                    <?php else: ?>
                        <span class="muted">— não gerado —</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($e['slug']): ?>
                        <a href="<?= htmlspecialchars($base_url) ?>/install/<?= htmlspecialchars($e['slug']) ?>" target="_blank" style="color:#3b82f6;">
                            <?= htmlspecialchars($base_url) ?>/install/<?= htmlspecialchars($e['slug']) ?>
                        </a>
                        <button onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($base_url . '/install/' . $e['slug']), ENT_QUOTES) ?>); this.textContent='✓ copiado!'" class="btn btn-sm btn-outline" style="margin-left:8px;">📋 copiar</button>
                    <?php endif; ?>
                </td>
                <td><?= $e['qtd_funcs'] ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="gerar">
                        <input type="hidden" name="empresa_id" value="<?= $e['id'] ?>">
                        <button class="btn btn-sm <?= $e['slug']?'btn-outline':'' ?>">
                            <?= $e['slug'] ? '🔄 Regenerar' : '✨ Gerar link' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2 style="margin-top:0;">💡 O que o funcionário recebe</h2>
    <p style="color:#cbd5e1;">Quando acessa o link <code>/app/install/m4b-cosmeticos</code>:</p>
    <ol style="color:#94a3b8; margin:12px 0 12px 24px; line-height:1.8;">
        <li>Página com instruções claras + nome da empresa em destaque</li>
        <li>Botão "⬇ Baixar DOT-ON-Agent.exe"</li>
        <li>O funcionário recebe <strong>apenas o executável</strong> (<code>.exe</code>) — sem código Python, sem configurar nada</li>
        <li>Dá duplo-clique no <code>.exe</code> (se aparecer o SmartScreen: "Mais informações" → "Executar assim mesmo")</li>
        <li>Vê a tela de login do "DOT-ON" (a empresa é identificada pelo login)</li>
        <li>Login com e-mail/senha temporária recebida por e-mail</li>
        <li>Sistema força troca de senha, e pronto: já está batendo ponto.</li>
    </ol>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
