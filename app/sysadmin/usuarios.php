<?php
$pagina = 'usuarios';
$titulo = 'Usuários (todas empresas)';
require_once __DIR__ . '/_layout.php';

$pdo = db();
$perfil = $_GET['perfil'] ?? 'todos';
$empresa = (int)($_GET['empresa'] ?? 0);

$where = ["u.perfil != 'super_admin'"];
$params = [];
if ($perfil !== 'todos') { $where[] = "u.perfil = ?"; $params[] = $perfil; }
if ($empresa)            { $where[] = "u.empresa_id = ?"; $params[] = $empresa; }
$where_sql = implode(' AND ', $where);

$users = $pdo->prepare("SELECT u.*, e.nome_fantasia, e.razao_social 
    FROM dot_usuarios u 
    LEFT JOIN dot_empresas e ON e.id = u.empresa_id 
    WHERE $where_sql 
    ORDER BY u.empresa_id, u.perfil DESC, u.id 
    LIMIT 500");
$users->execute($params);
$users = $users->fetchAll();

$empresas_list = $pdo->query("SELECT id, nome_fantasia, razao_social FROM dot_empresas ORDER BY nome_fantasia")->fetchAll();
?>

<h1>👥 Usuários do Sistema (todas as empresas)</h1>

<div style="margin-bottom:16px;">
    <form method="get" style="display:inline;">
        <label style="color:#94a3b8; margin-right:8px;">Empresa:</label>
        <select name="empresa" onchange="this.form.submit()">
            <option value="0">Todas</option>
            <?php foreach ($empresas_list as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $empresa==$e['id']?'selected':'' ?>>
                    <?= htmlspecialchars($e['nome_fantasia'] ?: $e['razao_social']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label style="color:#94a3b8; margin-left:16px; margin-right:8px;">Perfil:</label>
        <select name="perfil" onchange="this.form.submit()">
            <option value="todos">Todos</option>
            <option value="admin" <?= $perfil==='admin'?'selected':'' ?>>Admin empresa</option>
            <option value="gestor" <?= $perfil==='gestor'?'selected':'' ?>>Gestor</option>
            <option value="rh" <?= $perfil==='rh'?'selected':'' ?>>RH</option>
            <option value="funcionario" <?= $perfil==='funcionario'?'selected':'' ?>>Funcionário</option>
        </select>
    </form>
</div>

<div class="panel">
    <h2 style="margin-top:0;">Total: <?= count($users) ?> usuário(s)</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Empresa</th>
                <th>Matrícula</th>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Perfil</th>
                <th>Último login</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td>#<?= $u['id'] ?></td>
                <td>
                    <a href="empresas.php?id=<?= $u['empresa_id'] ?>" style="color:#3b82f6;">
                        <?= htmlspecialchars($u['nome_fantasia'] ?: $u['razao_social'] ?: '—') ?>
                    </a>
                </td>
                <td class="muted"><?= htmlspecialchars($u['matricula'] ?: '—') ?></td>
                <td><strong><?= htmlspecialchars($u['nome_completo']) ?></strong></td>
                <td class="muted"><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="badge-pill <?= $u['perfil']==='admin'?'enterprise':($u['perfil']==='gestor'?'pro':($u['perfil']==='rh'?'basic':'free')) ?>"><?= htmlspecialchars($u['perfil']) ?></span></td>
                <td class="muted"><?= $u['ultimo_login'] ? date('d/m H:i', strtotime($u['ultimo_login'])) : 'nunca' ?></td>
                <td><?= $u['ativo'] ? '<span class="badge-pill active">ativo</span>' : '<span class="badge-pill inactive">inativo</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
