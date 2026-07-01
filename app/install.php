<?php
/**
 * /app/install/{slug} → entrega instalador personalizado da empresa
 * Modo download: /app/install/{slug}/download → gera ZIP com .exe + dot-on.ini
 */
require_once __DIR__ . '/includes/db.php';

$slug = $_GET['slug'] ?? '';
$slug = preg_replace('/[^a-z0-9-]/i', '', strtolower($slug));
$is_download = !empty($_GET['download']);

if (!$slug) {
    http_response_code(404);
    echo "Link inválido.";
    exit;
}

$stmt = db()->prepare("SELECT id, slug, razao_social, nome_fantasia, plano, ativo FROM dot_empresas WHERE slug = ? LIMIT 1");
$stmt->execute([$slug]);
$emp = $stmt->fetch();

if (!$emp) {
    http_response_code(404);
    echo "Empresa não encontrada para o link <code>" . htmlspecialchars($slug) . "</code>.";
    exit;
}

if (!$emp['ativo']) {
    http_response_code(403);
    echo "Esta empresa está inativa. Entre em contato com o administrador.";
    exit;
}

$nome_empresa = $emp['nome_fantasia'] ?: $emp['razao_social'];
$exe_path  = __DIR__ . '/downloads/DOT-ON-Agent.exe';  // executavel standalone (PyInstaller)
$exe_disponivel = file_exists($exe_path);

// === MODO DOWNLOAD: entrega APENAS o .exe ===
// O funcionario nunca ve o codigo Python. Um unico .exe serve todas as empresas:
// a empresa do usuario e identificada no login (e-mail -> empresa), nao por arquivo de config.
if ($is_download) {
    if (!$exe_disponivel) {
        http_response_code(503);
        echo "O instalador está sendo preparado. Tente novamente em instantes ou contate o RH.";
        exit;
    }

    // Log do download
    try {
        db()->prepare("INSERT INTO dot_sysadmin_log (super_admin_id, acao, empresa_id, detalhes, ip)
                       VALUES (0, 'download_instalador', ?, ?, ?)")
            ->execute([$emp['id'], json_encode(['slug'=>$slug, 'ua'=>$_SERVER['HTTP_USER_AGENT']??'']), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {}

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="DOT-ON-Agent.exe"');
    header('Content-Length: ' . filesize($exe_path));
    header('X-Content-Type-Options: nosniff');
    readfile($exe_path);
    exit;
}

// === MODO PÁGINA: HTML institucional ===
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Instalar DOT-ON · <?= htmlspecialchars($nome_empresa) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%); min-height:100vh; color:#e2e8f0; padding:40px 20px; }
.container { max-width:760px; margin:0 auto; }
.hero { background:rgba(255,255,255,0.05); padding:36px 32px; border-radius:16px; backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.1); margin-bottom:24px; text-align:center; }
.hero .empresa { font-size:13px; color:#94a3b8; letter-spacing:1px; text-transform:uppercase; margin-bottom:8px; }
.hero h1 { font-size:32px; color:#fff; margin-bottom:8px; font-weight:700; }
.hero p { color:#cbd5e1; font-size:16px; line-height:1.6; }
.logo-emoji { font-size:48px; margin-bottom:12px; }
.download-card { background:#fff; color:#0f172a; padding:32px; border-radius:16px; text-align:center; margin-bottom:24px; box-shadow:0 16px 32px rgba(0,0,0,0.3); }
.download-card h2 { font-size:22px; margin-bottom:12px; color:#0f172a; }
.download-card .info { color:#475569; margin-bottom:24px; }
.btn-download { display:inline-block; background:linear-gradient(135deg,#2563eb,#1e40af); color:#fff; padding:16px 40px; border-radius:12px; font-size:18px; font-weight:600; text-decoration:none; box-shadow:0 8px 16px rgba(37,99,235,0.4); transition:transform .15s; }
.btn-download:hover { transform:translateY(-2px); }
.btn-download .filesize { display:block; font-size:13px; font-weight:400; opacity:0.85; margin-top:4px; }
.steps { background:rgba(255,255,255,0.05); padding:28px 32px; border-radius:16px; border:1px solid rgba(255,255,255,0.1); }
.steps h2 { color:#fff; margin-bottom:18px; font-size:20px; }
.step { display:flex; gap:14px; padding:14px 0; border-bottom:1px solid rgba(255,255,255,0.05); }
.step:last-child { border:none; }
.step-num { flex:0 0 32px; height:32px; background:#2563eb; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; }
.step-text { flex:1; color:#cbd5e1; line-height:1.5; padding-top:4px; }
.step-text strong { color:#fff; }
.footer { text-align:center; margin-top:24px; color:#64748b; font-size:13px; }
.footer a { color:#94a3b8; }
.badge-empresa { display:inline-block; background:rgba(37,99,235,0.2); color:#bfdbfe; padding:4px 14px; border-radius:20px; font-size:12px; letter-spacing:0.5px; }
</style>
</head>
<body>
<div class="container">
    <div class="hero">
        <div class="logo-emoji">⏱️</div>
        <div class="empresa">INSTALADOR PERSONALIZADO</div>
        <h1>DOT-ON</h1>
        <p>Sistema de Registro de Ponto Digital<br><span class="badge-empresa"><?= htmlspecialchars($nome_empresa) ?></span></p>
    </div>

    <div class="download-card">
        <h2>📥 Baixar o Agente para Windows</h2>
        <p class="info">
            Aplicativo pronto para a <strong><?= htmlspecialchars($nome_empresa) ?></strong>.<br>
            Não precisa instalar nada além do app — basta executar e fazer login.
        </p>
        <?php if ($exe_disponivel): ?>
        <a href="?slug=<?= urlencode($slug) ?>&download=1" class="btn-download">
            ⬇ Baixar DOT-ON-Agent.exe
            <span class="filesize">Windows 10/11 · 64-bit · ~9 MB</span>
        </a>
        <?php else: ?>
        <p style="background:#fffbeb;color:#92400e;padding:14px 18px;border-radius:10px;font-weight:600;">
            ⏳ O instalador está sendo preparado. Tente novamente em instantes ou fale com o RH.
        </p>
        <?php endif; ?>
    </div>

    <div class="steps">
        <h2>📋 Como instalar (2 minutos)</h2>
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-text">Clique no botão acima e <strong>baixe o arquivo</strong> <code>DOT-ON-Agent.exe</code>.</div>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-text">Dê <strong>duplo-clique</strong> no arquivo baixado. Se o Windows mostrar o aviso "SmartScreen", clique em <em>Mais informações → Executar assim mesmo</em>.</div>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <div class="step-text">Faça login com o <strong>e-mail e senha temporária</strong> que você recebeu por e-mail. O sistema vai pedir para você criar uma nova senha.</div>
        </div>
        <div class="step">
            <div class="step-num">4</div>
            <div class="step-text">Pronto! O ícone do DOT-ON aparece na <strong>bandeja do Windows</strong> (ao lado do relógio). Clique nele para bater ponto durante o expediente.</div>
        </div>
    </div>

    <div class="footer">
        Não recebeu seu e-mail? Fale com o RH da <?= htmlspecialchars($nome_empresa) ?>.<br>
        Problema técnico? <a href="mailto:pierre@syscomai.com.br">pierre@syscomai.com.br</a>
        · <a href="/app/">DOT-ON SyscomAI</a>
    </div>
</div>
</body>
</html>
