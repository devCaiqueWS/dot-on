<?php
$pagina = 'empresas';
$titulo = 'Empresas';
require_once __DIR__ . '/_layout.php';

$pdo = db();
$msg = '';
$msg_tipo = '';

// Ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'ativar' || $acao === 'desativar') {
        $novo = $acao === 'ativar' ? 1 : 0;
        $pdo->prepare("UPDATE dot_empresas SET ativo = ? WHERE id = ?")->execute([$novo, $id]);
        sysadmin_log("empresa_$acao", $id);
        $msg = "Empresa #$id " . ($novo ? "ativada" : "desativada") . " com sucesso.";
        $msg_tipo = 'success';
    }

    if ($acao === 'mudar_plano') {
        $plano = $_POST['plano'] ?? 'free';
        if (in_array($plano, ['free','basic','pro','enterprise'])) {
            $pdo->prepare("UPDATE dot_empresas SET plano = ? WHERE id = ?")->execute([$plano, $id]);
            sysadmin_log('mudar_plano', $id, ['plano' => $plano]);
            $msg = "Plano da empresa #$id alterado para '$plano'.";
            $msg_tipo = 'success';
        }
    }

    if ($acao === 'estender_trial') {
        $dias = max(1, min(365, (int)($_POST['dias'] ?? 30)));
        $pdo->prepare("UPDATE dot_empresas SET trial_expira = DATE_ADD(COALESCE(trial_expira, CURDATE()), INTERVAL ? DAY) WHERE id = ?")
            ->execute([$dias, $id]);
        sysadmin_log('estender_trial', $id, ['dias' => $dias]);
        $msg = "Trial da empresa #$id estendido em $dias dias.";
        $msg_tipo = 'success';
    }

    if ($acao === 'deletar') {
        // CUIDADO! Apaga em cascata
        try {
            $pdo->beginTransaction();
            foreach (['dot_email_fila','dot_onboarding','dot_auditoria','dot_eventos_sensiveis',
                      'dot_exports','dot_crp','dot_ociosidade','dot_horas_extras','dot_batidas',
                      'dot_sessoes','dot_bloqueios','dot_nsr_sequencia','dot_escalas',
                      'dot_departamentos','dot_usuarios','dot_config'] as $t) {
                try { $pdo->prepare("DELETE FROM $t WHERE empresa_id = ?")->execute([$id]); }
                catch (Throwable $e) {}
            }
            $pdo->prepare("DELETE FROM dot_empresas WHERE id = ?")->execute([$id]);
            $pdo->commit();
            sysadmin_log('deletar_empresa', $id);
            $msg = "Empresa #$id apagada permanentemente.";
            $msg_tipo = 'success';
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log("DOT-ON deletar empresa #$id: " . $e->getMessage());
            $msg = "Erro ao apagar a empresa. Verifique o log do servidor.";
            $msg_tipo = 'error';
        }
    }
}

// Detalhe de uma empresa específica?
$detalhe_id = (int)($_GET['id'] ?? 0);

if ($detalhe_id) {
    // === MODO DETALHE ===
    $stmt = $pdo->prepare("SELECT * FROM dot_empresas WHERE id = ?");
    $stmt->execute([$detalhe_id]);
    $emp = $stmt->fetch();
    if (!$emp) {
        echo "<div class='alert error'>Empresa não encontrada.</div>";
        echo "<a href='empresas.php' class='btn'>← voltar</a>";
        require_once __DIR__ . '/_footer.php';
        exit;
    }

    // Métricas da empresa
    $qtd_func = $pdo->prepare("SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id = ? AND perfil='funcionario'");
    $qtd_func->execute([$detalhe_id]); $qtd_func = $qtd_func->fetchColumn();

    $qtd_admin = $pdo->prepare("SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id = ? AND perfil IN ('admin','gestor','rh')");
    $qtd_admin->execute([$detalhe_id]); $qtd_admin = $qtd_admin->fetchColumn();

    $qtd_batidas = $pdo->prepare("SELECT COUNT(*) FROM dot_batidas WHERE empresa_id = ?");
    $qtd_batidas->execute([$detalhe_id]); $qtd_batidas = $qtd_batidas->fetchColumn();

    $qtd_sessoes = $pdo->prepare("SELECT COUNT(*) FROM dot_sessoes s JOIN dot_usuarios u ON u.id=s.usuario_id WHERE u.empresa_id = ?");
    $qtd_sessoes->execute([$detalhe_id]); $qtd_sessoes = $qtd_sessoes->fetchColumn();

    $users_admin = $pdo->prepare("SELECT id, matricula, nome_completo, email, perfil, ativo, ultimo_login, criado_em FROM dot_usuarios WHERE empresa_id = ? AND perfil IN ('admin','gestor','rh') ORDER BY perfil DESC");
    $users_admin->execute([$detalhe_id]); $users_admin = $users_admin->fetchAll();
?>

<h1>🏢 <?= htmlspecialchars($emp['nome_fantasia'] ?: $emp['razao_social']) ?> <span class="muted" style="font-size:14px;">(#<?= $emp['id'] ?>)</span></h1>

<?php if ($msg): ?><div class="alert <?= $msg_tipo ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<a href="empresas.php" class="btn btn-outline">← voltar para lista</a>

<div class="cards" style="margin-top:20px;">
    <div class="card info">
        <div class="label">Funcionários</div>
        <div class="value"><?= $qtd_func ?></div>
    </div>
    <div class="card info">
        <div class="label">Admins/Gestores</div>
        <div class="value"><?= $qtd_admin ?></div>
    </div>
    <div class="card success">
        <div class="label">Sessões</div>
        <div class="value"><?= $qtd_sessoes ?></div>
    </div>
    <div class="card warning">
        <div class="label">Batidas Totais</div>
        <div class="value"><?= $qtd_batidas ?></div>
    </div>
</div>

<div class="panel">
    <h2 style="margin-top:0;">📋 Dados Cadastrais</h2>
    <table>
        <tr><th style="width:200px;">CNPJ</th><td><?= htmlspecialchars($emp['cnpj']) ?></td></tr>
        <tr><th>Razão Social</th><td><?= htmlspecialchars($emp['razao_social']) ?></td></tr>
        <tr><th>Nome Fantasia</th><td><?= htmlspecialchars($emp['nome_fantasia'] ?: '—') ?></td></tr>
        <tr><th>Setor</th><td><?= htmlspecialchars($emp['setor'] ?: '—') ?></td></tr>
        <tr><th>Telefone</th><td><?= htmlspecialchars($emp['telefone'] ?: '—') ?></td></tr>
        <tr><th>E-mail Contato</th><td><?= htmlspecialchars($emp['email_contato'] ?: '—') ?></td></tr>
        <tr><th>Endereço</th><td><?= htmlspecialchars($emp['endereco'] ?: '—') ?></td></tr>
        <tr><th>Cidade/UF</th><td><?= htmlspecialchars(($emp['cidade'] ?: '?') . ' / ' . ($emp['uf'] ?: '?')) ?></td></tr>
        <tr><th>Plano</th><td><span class="badge-pill <?= htmlspecialchars($emp['plano']) ?>"><?= htmlspecialchars($emp['plano']) ?></span></td></tr>
        <tr><th>Status</th><td>
            <?php if ($emp['ativo']): ?><span class="badge-pill active">Ativa</span>
            <?php else: ?><span class="badge-pill inactive">Inativa</span><?php endif; ?>
        </td></tr>
        <tr><th>Trial expira em</th><td><?= $emp['trial_expira'] ? date('d/m/Y', strtotime($emp['trial_expira'])) : '—' ?></td></tr>
        <tr><th>Cadastro</th><td><?= date('d/m/Y H:i', strtotime($emp['criado_em'])) ?></td></tr>
        <tr><th>Slug (URL)</th><td><code><?= htmlspecialchars($emp['slug'] ?: '—') ?></code></td></tr>
    </table>
</div>

<div class="panel">
    <h2 style="margin-top:0;">👥 Administradores / Gestores</h2>
    <table>
        <thead><tr><th>Matrícula</th><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Último Login</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($users_admin as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['matricula']) ?></td>
                <td><strong><?= htmlspecialchars($u['nome_completo']) ?></strong></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['perfil']) ?></td>
                <td class="muted"><?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : 'nunca' ?></td>
                <td><?= $u['ativo'] ? '<span class="badge-pill active">ativo</span>' : '<span class="badge-pill inactive">inativo</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2 style="margin-top:0;">⚙️ Ações Administrativas</h2>

    <form method="post" style="display:inline-block; margin-right:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $emp['id'] ?>">
        <input type="hidden" name="acao" value="<?= $emp['ativo'] ? 'desativar' : 'ativar' ?>">
        <button type="submit" class="btn <?= $emp['ativo'] ? 'btn-warning' : 'btn-success' ?>">
            <?= $emp['ativo'] ? '⏸ Desativar empresa' : '▶ Reativar empresa' ?>
        </button>
    </form>

    <form method="post" style="display:inline-block; margin-right:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $emp['id'] ?>">
        <input type="hidden" name="acao" value="estender_trial">
        <select name="dias">
            <option value="7">+7 dias</option>
            <option value="30" selected>+30 dias</option>
            <option value="90">+90 dias</option>
            <option value="365">+365 dias</option>
        </select>
        <button type="submit" class="btn btn-outline">⏰ Estender trial</button>
    </form>

    <form method="post" style="display:inline-block; margin-right:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $emp['id'] ?>">
        <input type="hidden" name="acao" value="mudar_plano">
        <select name="plano">
            <option value="free" <?= $emp['plano']==='free'?'selected':'' ?>>Free (até 10 funcs)</option>
            <option value="basic" <?= $emp['plano']==='basic'?'selected':'' ?>>Basic</option>
            <option value="pro" <?= $emp['plano']==='pro'?'selected':'' ?>>Pro</option>
            <option value="enterprise" <?= $emp['plano']==='enterprise'?'selected':'' ?>>Enterprise</option>
        </select>
        <button type="submit" class="btn btn-outline">💳 Alterar plano</button>
    </form>

    <a href="instaladores.php?empresa=<?= $emp['id'] ?>" class="btn btn-outline">📦 Gerar instalador personalizado</a>

    <form method="post" style="display:inline-block; margin-left:20px;" onsubmit="return confirm('Tem certeza? Vai apagar TUDO (funcionários, batidas, sessões, certificado). Não tem volta!')">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $emp['id'] ?>">
        <input type="hidden" name="acao" value="deletar">
        <button type="submit" class="btn btn-danger">🗑️ Apagar empresa permanentemente</button>
    </form>
</div>

<?php } else { 
    // === MODO LISTA ===
    $filtro = $_GET['filtro'] ?? 'todas';
    $where = "1=1";
    if ($filtro === 'ativas') $where = "ativo = 1";
    if ($filtro === 'inativas') $where = "ativo = 0";
    if ($filtro === 'trial') $where = "trial_expira >= CURDATE() AND plano = 'free'";
    if ($filtro === 'pagas') $where = "plano IN ('basic','pro','enterprise')";

    $empresas = $pdo->query("SELECT e.*, 
        (SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id = e.id AND perfil='funcionario') AS qtd_funcs,
        (SELECT MAX(momento) FROM dot_batidas WHERE empresa_id = e.id) AS ultima_batida
        FROM dot_empresas e WHERE $where ORDER BY id DESC")->fetchAll();
?>

<h1>🏢 Empresas Cadastradas</h1>

<?php if ($msg): ?><div class="alert <?= $msg_tipo ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div style="margin-bottom:16px;">
    <a href="?filtro=todas" class="btn <?= $filtro==='todas'?'':'btn-outline' ?>">Todas (<?= count($empresas) ?>)</a>
    <a href="?filtro=ativas" class="btn <?= $filtro==='ativas'?'':'btn-outline' ?>">Ativas</a>
    <a href="?filtro=inativas" class="btn <?= $filtro==='inativas'?'':'btn-outline' ?>">Inativas</a>
    <a href="?filtro=trial" class="btn <?= $filtro==='trial'?'':'btn-outline' ?>">Em trial</a>
    <a href="?filtro=pagas" class="btn <?= $filtro==='pagas'?'':'btn-outline' ?>">Pagantes</a>
</div>

<div class="panel">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Empresa</th>
                <th>CNPJ</th>
                <th>Plano</th>
                <th>Funcs</th>
                <th>Última batida</th>
                <th>Trial</th>
                <th>Status</th>
                <th>Cadastro</th>
                <th>Ação</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($empresas as $e): ?>
            <tr>
                <td>#<?= $e['id'] ?></td>
                <td>
                    <strong><a href="?id=<?= $e['id'] ?>" style="color:#fff;"><?= htmlspecialchars($e['nome_fantasia'] ?: $e['razao_social']) ?></a></strong>
                    <?php if ($e['razao_social'] && $e['nome_fantasia']): ?>
                        <div class="muted" style="font-size:11px;"><?= htmlspecialchars($e['razao_social']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= htmlspecialchars($e['cnpj']) ?></td>
                <td><span class="badge-pill <?= htmlspecialchars($e['plano']) ?>"><?= htmlspecialchars($e['plano']) ?></span></td>
                <td><?= $e['qtd_funcs'] ?></td>
                <td class="muted"><?= $e['ultima_batida'] ? date('d/m H:i', strtotime($e['ultima_batida'])) : '—' ?></td>
                <td class="muted"><?= $e['trial_expira'] ? date('d/m/Y', strtotime($e['trial_expira'])) : '—' ?></td>
                <td>
                    <?php if ($e['ativo']): ?>
                        <span class="badge-pill active">ativa</span>
                    <?php else: ?>
                        <span class="badge-pill inactive">inativa</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= date('d/m/Y', strtotime($e['criado_em'])) ?></td>
                <td><a href="?id=<?= $e['id'] ?>" class="btn btn-sm">Detalhes →</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$empresas): ?>
            <tr><td colspan="10" style="text-align:center; padding:30px; color:#64748b;">Nenhuma empresa encontrada com este filtro.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php } require_once __DIR__ . '/_footer.php'; ?>
