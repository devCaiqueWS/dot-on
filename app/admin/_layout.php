<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/tenant.php';
$user = requer_login();
$empresa = tenant_empresa();
$pagina = $pagina ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>DOT-ON · <?= htmlspecialchars($titulo ?? 'Painel') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin-body">
<aside class="sidebar">
    <div class="logo">⏱ <span>DOT-ON</span></div>
    <?php if ($empresa): ?>
    <div style="padding:0 16px;margin-bottom:14px;border-bottom:1px solid rgba(255,255,255,.1);padding-bottom:14px">
        <small style="opacity:.6;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em">Empresa</small>
        <div style="font-weight:600;color:#38bdf8;font-size:.92rem;margin-top:3px">🏢 <?= htmlspecialchars($empresa['nome_fantasia'] ?: $empresa['razao_social']) ?></div>
        <?php if ($empresa['plano'] === 'free' && $empresa['trial_expira']): 
            $dias = max(0, (strtotime($empresa['trial_expira']) - time()) / 86400);
        ?>
        <div style="font-size:.72rem;opacity:.8;margin-top:4px"><?= round($dias) ?> dias de trial restantes</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <nav>
        <a href="index.php" class="<?= $pagina==='dashboard'?'active':'' ?>">📊 Dashboard</a>
        <a href="funcionarios.php" class="<?= $pagina==='funcionarios'?'active':'' ?>">👥 Funcionários</a>
        <a href="empresa.php" class="<?= $pagina==='empresa'?'active':'' ?>">🏢 Empresa</a>
        <a href="certificado.php" class="<?= $pagina==='certificado'?'active':'' ?>">🔐 Certificado ICP</a>
        <a href="batidas.php" class="<?= $pagina==='batidas'?'active':'' ?>">⏱ Batidas</a>
        <a href="ajuste_ponto.php" class="<?= $pagina==='ajuste_ponto'?'active':'' ?>">🛠 Ajuste de Ponto</a>
        <a href="auditoria.php" class="<?= $pagina==='auditoria'?'active':'' ?>">🔗 Auditoria Fiscal</a>
        <a href="horas_extras.php" class="<?= $pagina==='extras'?'active':'' ?>">⏰ Horas Extras
            <?php
            try {
                $stmt = db()->prepare("SELECT COUNT(*) FROM dot_horas_extras he 
                                       JOIN dot_usuarios u ON u.id = he.usuario_id
                                       WHERE u.empresa_id = ? AND he.status='pendente' AND he.minutos_solicitados > 0");
                $stmt->execute([$user['empresa_id']]);
                $pend = (int)$stmt->fetchColumn();
                if ($pend > 0) echo "<span class='badge'>$pend</span>";
            } catch (Throwable $e) {}
            ?>
        </a>
        <a href="justificativas.php" class="<?= $pagina==='justificativas'?'active':'' ?>">📝 Justificativas
            <?php
            try {
                require_once __DIR__ . '/../includes/justificativas.php';
                $jpend = jus_contar_pendentes((int)$user['empresa_id']);
                if ($jpend > 0) echo "<span class='badge'>$jpend</span>";
            } catch (Throwable $e) {}
            ?>
        </a>
        <a href="banco_horas.php" class="<?= $pagina==='banco_horas'?'active':'' ?>">⚖ Banco de Horas</a>
        <a href="espelho.php" class="<?= $pagina==='espelho'?'active':'' ?>">📋 Espelho de Ponto</a>
        <a href="relatorios.php" class="<?= $pagina==='relatorios'?'active':'' ?>">📊 Relatórios & AFD/AEJ</a>
        <a href="config.php" class="<?= $pagina==='config'?'active':'' ?>">⚙ Configurações</a>
        <a href="downloads.php" class="<?= $pagina==='downloads'?'active':'' ?>">⬇ Agente Windows</a>
    </nav>
    <div class="userbox">
        <strong><?= htmlspecialchars($user['nome_completo']) ?></strong>
        <small><?= htmlspecialchars($user['perfil']) ?></small>
        <a href="logout.php">Sair</a>
    </div>
</aside>
<main class="content">
    <h1><?= htmlspecialchars($titulo ?? 'Painel') ?></h1>
