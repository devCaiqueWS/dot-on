<?php
/**
 * Portal do Funcionário - DOT-ON
 * /app/me/ - Acesso pessoal, batida via web, espelho
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = requer_login();

// Pegar empresa
$st = db()->prepare("SELECT * FROM dot_empresas WHERE id = ?");
$st->execute([$user['empresa_id']]);
$empresa = $st->fetch();

// Pegar escala + aplicar a jornada do dia da semana atual
$st = db()->prepare("SELECT * FROM dot_escalas WHERE id = ?");
$st->execute([$user['escala_id']]);
$escala = $st->fetch();
$folga_hoje = false; $almoco_hoje = 60;
if ($escala) {
    require_once __DIR__ . '/../includes/ajuste_ponto.php';
    $dia_hoje = jornada_dia((int)$user['id'], (int)date('w'));
    if ($dia_hoje) {
        $folga_hoje = ((int)$dia_hoje['trabalha'] === 0);
        $almoco_hoje = (int)$dia_hoje['almoco_minutos'];
        if (!$folga_hoje) {
            $escala['entrada'] = $dia_hoje['entrada'] ?: $escala['entrada'];
            $escala['saida'] = $dia_hoje['saida'] ?: $escala['saida'];
            if ($dia_hoje['carga_minutos'] !== null) $escala['carga_diaria_minutos'] = (int)$dia_hoje['carga_minutos'];
        }
    }
}

// Sessão de hoje
$hoje = date('Y-m-d');
$st = db()->prepare("SELECT * FROM dot_sessoes WHERE usuario_id=? AND data_ref=? LIMIT 1");
$st->execute([$user['id'], $hoje]);
$sessao_hoje = $st->fetch();

// Batidas de hoje (ignora anuladas)
require_once __DIR__ . '/../includes/justificativas.php';
batidas_garantir_cancelamento();
$st = db()->prepare("SELECT * FROM dot_batidas WHERE usuario_id=? AND DATE(momento)=? AND COALESCE(cancelada,0)=0 ORDER BY momento");
$st->execute([$user['id'], $hoje]);
$batidas_hoje = $st->fetchAll();

// Próxima batida esperada
$tipos_feitos = array_column($batidas_hoje, 'tipo');
$proxima = null;
if (!in_array('entrada', $tipos_feitos)) $proxima = ['tipo'=>'entrada', 'label'=>'Registrar entrada', 'cor'=>'#059669'];
elseif (!in_array('saida_intervalo', $tipos_feitos)) $proxima = ['tipo'=>'saida_intervalo', 'label'=>'Saída para intervalo', 'cor'=>'#d97706'];
elseif (!in_array('retorno_intervalo', $tipos_feitos)) $proxima = ['tipo'=>'retorno_intervalo', 'label'=>'Retorno do intervalo', 'cor'=>'#0369a1'];
elseif (!in_array('saida', $tipos_feitos)) $proxima = ['tipo'=>'saida', 'label'=>'Registrar saída', 'cor'=>'#dc2626'];

// Mês atual - resumo
$mes_inicio = date('Y-m-01');

// Banco de horas do mês: mesma apuração do painel admin (jornada por dia da
// semana, faltas geram débito, abonos neutralizam). Roda antes do resumo
// porque também recalcula os minutos das sessões.
require_once __DIR__ . '/../includes/banco_horas.php';
$ap_mes = bh_apurar((int)$user['id'], (int)$user['empresa_id'], $mes_inicio, date('Y-m-t'));

$st = db()->prepare("SELECT
    COUNT(*) AS dias_trabalhados,
    SUM(minutos_trabalhados) AS min_total,
    SUM(minutos_extras) AS min_extras,
    SUM(minutos_ociosos) AS min_ociosos
    FROM dot_sessoes WHERE usuario_id=? AND data_ref BETWEEN ? AND ?");
$st->execute([$user['id'], $mes_inicio, $hoje]);
$resumo_mes = $st->fetch();

// Calcular minutos hoje em tempo real
$min_hoje = 0;
$intervalo_aberto = null;
if ($batidas_hoje) {
    $ts_in = null; $em_intervalo = false; $ts_int_in = null;
    foreach ($batidas_hoje as $b) {
        $ts = strtotime($b['momento']);
        switch ($b['tipo']) {
            case 'entrada': $ts_in = $ts; break;
            case 'saida_intervalo':
                if ($ts_in) $min_hoje += ($ts - $ts_in) / 60;
                $em_intervalo = true; $ts_int_in = $ts;
                break;
            case 'retorno_intervalo':
                $ts_in = $ts; $em_intervalo = false;
                break;
            case 'saida':
                if ($ts_in) $min_hoje += ($ts - $ts_in) / 60;
                $ts_in = null;
                break;
        }
    }
    // Se ainda trabalhando agora
    if ($ts_in && !$em_intervalo && !in_array('saida', $tipos_feitos)) {
        $min_hoje += (time() - $ts_in) / 60;
    }
}
$horas_hoje = floor($min_hoje / 60);
$mins_hoje = (int)($min_hoje % 60);

$min_objetivo = $folga_hoje ? 0 : (int)($escala['carga_diaria_minutos'] ?? 480);
$pct = $min_objetivo > 0 ? min(100, ($min_hoje / $min_objetivo) * 100) : 0;

// Datas em português (date('l')/('F') sairiam em inglês)
$dias_semana_pt = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
$meses_pt = [1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];

// Iniciais para o avatar (primeiro + último nome)
$partes_nome = preg_split('/\s+/', trim($user['nome_completo']));
$iniciais = mb_strtoupper(mb_substr($partes_nome[0], 0, 1) . (count($partes_nome) > 1 ? mb_substr(end($partes_nome), 0, 1) : ''));

// Ícones de navegação (SVG stroke, herdam a cor do texto)
$nav_icons = [
    'home'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    'espelho'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 11h16"/></svg>',
    'banco'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 20V10M7 10l-3 3m3-3 3 3"/><path d="M17 4v10m0 0 3-3m-3 3-3-3"/></svg>',
    'justificativa' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M9 14h6M9 17h4"/></svg>',
    'extra'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f2b46">
<title>Meu DOT-ON · <?= htmlspecialchars($user['nome_completo']) ?></title>
<style>
:root{
    --brand:#0369a1; --brand-forte:#0f2b46; --brand-claro:#eaf3fa;
    --bg:#f4f6f9; --surface:#ffffff; --borda:#e3e8ef;
    --texto:#1a2433; --texto-2:#5b6472; --texto-3:#98a1ae;
    --ok:#059669; --erro:#dc2626; --alerta:#d97706; --info:#0369a1;
    --radius:10px;
    --sombra:0 1px 2px rgba(16,24,40,.05);
}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html{font-size:16px}
body{font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;background:var(--bg);color:var(--texto);min-height:100vh}
a{color:var(--brand)}
button{font-family:inherit}
svg{width:20px;height:20px;flex-shrink:0}

/* ================= ESTRUTURA ================= */
.layout{display:flex;min-height:100vh}
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.conteudo{flex:1;width:100%;max-width:1180px;margin:0 auto;padding:20px 16px 96px}

/* ---- Sidebar (desktop) ---- */
.sidebar{display:none;width:236px;flex-shrink:0;background:var(--brand-forte);color:#cdd9e4;flex-direction:column;position:sticky;top:0;height:100vh}
.sidebar .marca{display:flex;align-items:center;gap:10px;padding:22px 20px 18px;color:#fff}
.sidebar .marca .logo{width:34px;height:34px;border-radius:8px;background:var(--brand);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.05rem;color:#fff}
.sidebar .marca b{font-size:1.02rem;letter-spacing:.03em}
.sidebar .marca small{display:block;font-weight:400;font-size:.68rem;color:#8fa3b5;margin-top:1px}
.sidebar nav{flex:1;padding:6px 10px}
.sidebar .tab{display:flex;align-items:center;gap:11px;width:100%;padding:11px 12px;border-radius:8px;color:#b7c5d2;font-size:.9rem;font-weight:500;cursor:pointer;margin-bottom:2px;border:0;background:transparent;text-align:left}
.sidebar .tab:hover{background:rgba(255,255,255,.06);color:#fff}
.sidebar .tab.active{background:var(--brand);color:#fff;font-weight:600}
.sidebar .rodape{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08)}
.sidebar .usuario{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.sidebar .usuario .nome{font-size:.84rem;color:#fff;font-weight:600;line-height:1.2}
.sidebar .usuario .sub{font-size:.7rem;color:#8fa3b5}
.sidebar .rodape a{display:block;color:#b7c5d2;text-decoration:none;font-size:.8rem;padding:5px 0}
.sidebar .rodape a:hover{color:#fff}

.avatar{width:36px;height:36px;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0}

/* ---- Barra superior (mobile) ---- */
.appbar{display:flex;align-items:center;justify-content:space-between;background:var(--brand-forte);color:#fff;padding:12px 16px;position:sticky;top:0;z-index:30}
.appbar .marca{display:flex;align-items:center;gap:9px;font-weight:700;letter-spacing:.03em}
.appbar .marca .logo{width:30px;height:30px;border-radius:7px;background:var(--brand);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem}
.appbar .marca small{display:block;font-weight:400;font-size:.65rem;color:#9fb2c2}
.appbar .avatar{cursor:pointer;border:0}

/* ---- Navegação inferior (mobile) ---- */
.bottomnav{position:fixed;left:0;right:0;bottom:0;z-index:30;display:flex;background:var(--surface);border-top:1px solid var(--borda);padding-bottom:env(safe-area-inset-bottom)}
.bottomnav .tab{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;padding:8px 2px 7px;font-size:.62rem;font-weight:600;color:var(--texto-3);cursor:pointer;border:0;background:transparent}
.bottomnav .tab svg{width:22px;height:22px}
.bottomnav .tab.active{color:var(--brand)}

/* ---- Cabeçalho da página ---- */
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin:6px 0 16px;flex-wrap:wrap}
.page-head h1{font-size:1.35rem;font-weight:700;letter-spacing:-.01em}
.page-head .sub{color:var(--texto-2);font-size:.85rem;margin-top:2px}

/* ================= COMPONENTES ================= */
.card{background:var(--surface);border:1px solid var(--borda);border-radius:var(--radius);box-shadow:var(--sombra);padding:20px;margin-bottom:16px}
.card > h2{font-size:.95rem;font-weight:700;margin-bottom:14px}
.card .desc{color:var(--texto-2);font-size:.83rem;margin:-8px 0 14px}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 18px;border-radius:8px;border:1px solid transparent;font-size:.9rem;font-weight:600;cursor:pointer;text-decoration:none;transition:filter .12s}
.btn:hover{filter:brightness(1.06)}
.btn:disabled{opacity:.55;cursor:default}
.btn-primario{background:var(--brand);color:#fff}
.btn-contorno{background:var(--surface);color:var(--texto);border-color:var(--borda)}
.btn-lg{width:100%;padding:15px 18px;font-size:1.02rem;border-radius:9px}

.progress{background:#e9edf3;border-radius:6px;height:8px;overflow:hidden;margin:14px 0 6px}
.progress-bar{height:100%;background:var(--brand);transition:width .3s}

.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:14px}
.stat{background:var(--bg);border:1px solid var(--borda);border-radius:8px;padding:12px 10px;text-align:center}
.stat .v{font-size:1.3rem;font-weight:700}
.stat .l{font-size:.68rem;color:var(--texto-2);text-transform:uppercase;letter-spacing:.05em;margin-top:3px}

/* Relógio */
.relogio-box{text-align:center;padding:6px 0 2px}
.clock{font-variant-numeric:tabular-nums;font-size:2.9rem;font-weight:700;letter-spacing:.01em;color:var(--texto)}
.clock small{display:block;font-size:.85rem;color:var(--texto-2);font-weight:500;margin-top:2px}

/* Lista de batidas */
.batidas-list{display:flex;flex-direction:column}
.batida-item{display:flex;align-items:center;gap:12px;padding:11px 2px;border-bottom:1px solid var(--borda);font-size:.88rem}
.batida-item:last-child{border-bottom:0}
.batida-item .ic-tipo{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.batida-item .det{flex:1;min-width:0}
.batida-item .nm{font-weight:600;text-transform:capitalize}
.batida-item .hr{color:var(--texto-2);font-size:.78rem}
.batida-item .nsr{font-variant-numeric:tabular-nums;color:var(--texto-3);font-size:.72rem}
.tipo-entrada{background:var(--ok)}
.tipo-saida_intervalo{background:var(--alerta)}
.tipo-retorno_intervalo{background:var(--info)}
.tipo-saida{background:var(--erro)}

/* Jornada do dia */
.jornada{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;text-align:center}
.jornada .item{background:var(--bg);border:1px solid var(--borda);border-radius:8px;padding:12px 8px}
.jornada .item .l{font-size:.68rem;color:var(--texto-2);text-transform:uppercase;letter-spacing:.05em}
.jornada .item .v{font-size:1.05rem;font-weight:700;margin-top:3px}

/* Tabela */
.tbl{width:100%;border-collapse:collapse;font-size:.85rem}
.tbl th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--texto-2);padding:8px 10px;border-bottom:1px solid var(--borda);background:var(--bg)}
.tbl td{padding:9px 10px;border-bottom:1px solid var(--borda)}
.tbl tr:last-child td{border-bottom:0}
.tbl th:not(:first-child),.tbl td:not(:first-child){text-align:center}
.tbl-wrap{overflow-x:auto}

/* Chips de resumo */
.chips{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.chip{border:1px solid var(--borda);border-radius:8px;padding:10px;text-align:center;background:var(--bg)}
.chip b{font-size:1.05rem}
.chip small{display:block;color:var(--texto-2);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}

/* Alertas */
.alert{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:.87rem;border:1px solid;display:flex;gap:10px;align-items:flex-start}
.alert.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
.alert.warn{background:#fffbeb;color:#92400e;border-color:#fde68a}
.alert.info{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}

/* Formulários */
.field{margin-bottom:14px}
.field label{display:block;font-size:.8rem;font-weight:600;color:var(--texto-2);margin-bottom:6px}
.field input,.field select,.field textarea{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;font-family:inherit;background:var(--surface);color:var(--texto)}
.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid var(--brand-claro);border-color:var(--brand)}
.field textarea{resize:vertical}
.field small{display:block;color:var(--texto-3);font-size:.72rem;margin-top:4px}
.field input[type=file]{border-style:dashed;background:var(--bg);font-size:.82rem}
.field-row{display:flex;gap:12px}
.field-row .field{flex:1}

.segmented{display:flex;background:var(--bg);border:1px solid var(--borda);border-radius:8px;padding:3px;margin-bottom:16px;gap:3px}
.segmented .seg{flex:1;padding:8px;border:0;background:transparent;border-radius:6px;font-size:.82rem;font-weight:600;color:var(--texto-2);cursor:pointer}
.segmented .seg.active{background:var(--surface);color:var(--brand);box-shadow:var(--sombra)}

/* Minhas solicitações */
.jus-item{display:flex;align-items:flex-start;gap:10px;padding:12px 2px;border-bottom:1px solid var(--borda);font-size:.85rem}
.jus-item:last-child{border-bottom:0}
.jus-item .meta{flex:1;min-width:0}
.jus-item .meta .t{font-weight:600}
.jus-item .meta .d{color:var(--texto-2);font-size:.76rem;margin-top:2px}
.jus-item .meta .m{color:var(--texto-2);font-size:.8rem;margin-top:4px}
.st{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 9px;border-radius:20px;white-space:nowrap}
.st-pendente{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.st-aprovada{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.st-rejeitada{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.jus-anexo{display:inline-block;margin-top:4px;font-size:.76rem}

/* Menu lateral da conta (mobile) */
.menu{position:fixed;top:0;right:-290px;width:290px;height:100vh;background:var(--surface);box-shadow:-6px 0 24px rgba(16,24,40,.14);transition:right .22s;z-index:110;padding:18px 0;display:flex;flex-direction:column}
.menu.open{right:0}
.menu .conta{display:flex;align-items:center;gap:12px;padding:6px 20px 16px;border-bottom:1px solid var(--borda)}
.menu .conta .nome{font-weight:700;font-size:.92rem;line-height:1.25}
.menu .conta .sub{color:var(--texto-2);font-size:.75rem}
.menu a{display:block;padding:13px 22px;color:var(--texto);text-decoration:none;font-weight:500;font-size:.9rem;border-bottom:1px solid var(--bg)}
.menu a:hover{background:var(--bg)}
.menu .close{position:absolute;top:12px;right:12px;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--texto-2)}
.overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:100;display:none}
.overlay.show{display:block}

/* Abas */
.tab-content{display:none}
.tab-content.active{display:block}

/* Rodapé */
.rodape-info{text-align:center;color:var(--texto-3);font-size:.72rem;padding:10px 0 4px}
.dot-live{display:inline-block;width:7px;height:7px;background:var(--ok);border-radius:50%;margin-right:4px;animation:pulse 1.6s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}}

/* Grades por aba */
.grid-2{display:grid;grid-template-columns:1fr;gap:0}

/* ================= RESPONSIVO ================= */
@media (min-width:640px){
    .conteudo{padding:24px 24px 96px}
    .stats{grid-template-columns:repeat(4,1fr)}
}
@media (min-width:960px){
    .appbar,.bottomnav{display:none}
    .sidebar{display:flex}
    .conteudo{padding:28px 32px 32px}
    .grid-2{grid-template-columns:minmax(0,7fr) minmax(0,5fr);gap:16px;align-items:start}
    .grid-2 > *{min-width:0}
    .clock{font-size:3.4rem}
    .page-head h1{font-size:1.5rem}
}
@media (min-width:1600px){
    html{font-size:17px}
    .conteudo{max-width:1280px}
}
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<div class="menu" id="menu">
    <button class="close" onclick="toggleMenu()" aria-label="Fechar">✕</button>
    <div class="conta">
        <span class="avatar"><?= htmlspecialchars($iniciais) ?></span>
        <div>
            <div class="nome"><?= htmlspecialchars($user['nome_completo']) ?></div>
            <div class="sub">Mat. <?= htmlspecialchars($user['matricula']) ?> · <?= htmlspecialchars($empresa['nome_fantasia'] ?: $empresa['razao_social']) ?></div>
        </div>
    </div>
    <a href="../admin/espelho_pdf.php?func=<?= $user['id'] ?>&mes=<?= substr($hoje,0,7) ?>" target="_blank">Baixar espelho em PDF</a>
    <a href="../admin/trocar_senha.php">Trocar senha</a>
    <a href="../admin/logout.php">Sair da conta</a>
</div>

<div class="layout">

<!-- Sidebar (desktop) -->
<aside class="sidebar">
    <div class="marca">
        <span class="logo">D</span>
        <div><b>DOT-ON</b><small>Portal do Funcionário</small></div>
    </div>
    <nav>
        <button class="tab active" data-tab="home"><?= $nav_icons['home'] ?>Registrar ponto</button>
        <button class="tab" data-tab="espelho"><?= $nav_icons['espelho'] ?>Espelho do mês</button>
        <button class="tab" data-tab="banco"><?= $nav_icons['banco'] ?>Banco de horas</button>
        <button class="tab" data-tab="justificativa"><?= $nav_icons['justificativa'] ?>Justificativas</button>
        <button class="tab" data-tab="extra"><?= $nav_icons['extra'] ?>Hora extra</button>
    </nav>
    <div class="rodape">
        <div class="usuario">
            <span class="avatar"><?= htmlspecialchars($iniciais) ?></span>
            <div>
                <div class="nome"><?= htmlspecialchars($partes_nome[0]) ?></div>
                <div class="sub"><?= htmlspecialchars($empresa['nome_fantasia'] ?: $empresa['razao_social']) ?></div>
            </div>
        </div>
        <a href="../admin/trocar_senha.php">Trocar senha</a>
        <a href="../admin/logout.php">Sair da conta</a>
    </div>
</aside>

<div class="main">

<!-- Barra superior (mobile) -->
<header class="appbar">
    <div class="marca">
        <span class="logo">D</span>
        <div>DOT-ON<small><?= htmlspecialchars($empresa['nome_fantasia'] ?: $empresa['razao_social']) ?></small></div>
    </div>
    <button class="avatar" onclick="toggleMenu()" aria-label="Conta"><?= htmlspecialchars($iniciais) ?></button>
</header>

<div class="conteudo">

<!-- ============ ABA: REGISTRAR PONTO ============ -->
<div class="tab-content active" id="tab-home">
    <div class="page-head">
        <div>
            <h1>Olá, <?= htmlspecialchars($partes_nome[0]) ?></h1>
            <div class="sub"><?= ucfirst($dias_semana_pt[(int)date('w')]) ?>, <?= date('d') ?> de <?= $meses_pt[(int)date('n')] ?> de <?= date('Y') ?></div>
        </div>
    </div>

    <div class="grid-2">
        <div>
            <div class="card">
                <div class="relogio-box">
                    <div class="clock" id="relog"><?= date('H:i:s') ?> <small>hora oficial do registro</small></div>
                </div>

                <div style="margin-top:16px">
                <?php if ($proxima): ?>
                    <button class="btn btn-lg" style="background:<?= $proxima['cor'] ?>;color:#fff" onclick="baterPonto('<?= $proxima['tipo'] ?>')">
                        <?= $proxima['label'] ?>
                    </button>
                    <p style="text-align:center;font-size:.74rem;color:var(--texto-3);margin-top:8px">A batida pelo navegador exige a sua localização.</p>
                <?php else: ?>
                    <div class="alert ok"><strong>Expediente concluído.</strong> Todas as batidas de hoje foram registradas.</div>
                <?php endif; ?>
                </div>

                <div class="progress"><div class="progress-bar" style="width:<?= number_format($pct,1) ?>%"></div></div>
                <div style="display:flex;justify-content:space-between;font-size:.78rem;color:var(--texto-2)">
                    <span><strong><?= $horas_hoje ?>h <?= $mins_hoje ?>min</strong> trabalhados</span>
                    <span>jornada de <?= floor($min_objetivo/60) ?>h<?= $min_objetivo%60 ? sprintf('%02d',$min_objetivo%60) : '' ?></span>
                </div>

                <div class="stats">
                    <div class="stat"><div class="v"><?= count($batidas_hoje) ?></div><div class="l">batidas hoje</div></div>
                    <div class="stat"><div class="v"><?= number_format($pct, 0) ?>%</div><div class="l">meta diária</div></div>
                    <div class="stat"><div class="v"><?= $resumo_mes['dias_trabalhados'] ?: 0 ?></div><div class="l">dias no mês</div></div>
                    <div class="stat"><div class="v" style="color:<?= $ap_mes['saldo_periodo']>=0?'var(--ok)':'var(--erro)' ?>"><?= ($ap_mes['saldo_periodo']>=0?'+':'-') . floor(abs($ap_mes['saldo_periodo'])/60) ?>h<?= sprintf('%02d', abs($ap_mes['saldo_periodo'])%60) ?></div><div class="l">saldo do mês</div></div>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <h2>Batidas de hoje</h2>
                <?php if (!$batidas_hoje): ?>
                    <p style="color:var(--texto-3);font-size:.86rem;padding:10px 0">Nenhuma batida registrada ainda hoje.</p>
                <?php else: ?>
                <div class="batidas-list">
                    <?php foreach (array_reverse($batidas_hoje) as $b): ?>
                    <div class="batida-item">
                        <span class="ic-tipo tipo-<?= $b['tipo'] ?>"></span>
                        <div class="det">
                            <div class="nm"><?= str_replace('_',' ',$b['tipo']) ?></div>
                            <div class="hr"><?= date('H:i:s', strtotime($b['momento'])) ?> · <span class="nsr">NSR <?= str_pad($b['nsr'],6,'0',STR_PAD_LEFT) ?></span></div>
                        </div>
                        <a href="../validar.php?nsr=<?= $b['nsr'] ?>&t=<?= substr($b['hash_registro'] ?? '',0,16) ?>" target="_blank" style="text-decoration:none;font-size:.78rem;font-weight:600">CRP</a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($escala): ?>
            <div class="card">
                <h2>Sua jornada de hoje</h2>
                <?php if ($folga_hoje): ?>
                    <div class="alert warn"><strong>Folga hoje.</strong> Sua escala não prevê expediente.</div>
                <?php endif; ?>
                <div class="jornada">
                    <div class="item"><div class="l">Entrada</div><div class="v"><?= $folga_hoje ? '—' : substr($escala['entrada'],0,5) ?></div></div>
                    <div class="item"><div class="l">Intervalo</div><div class="v"><?= $folga_hoje ? '—' : $almoco_hoje.' min' ?></div></div>
                    <div class="item"><div class="l">Saída</div><div class="v"><?= $folga_hoje ? '—' : substr($escala['saida'],0,5) ?></div></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============ ABA: ESPELHO ============ -->
<div class="tab-content" id="tab-espelho">
    <div class="page-head">
        <div>
            <h1>Espelho do mês</h1>
            <div class="sub"><?= ucfirst($meses_pt[(int)date('n')]) ?> de <?= date('Y') ?> · <?= $resumo_mes['dias_trabalhados'] ?: 0 ?> dias com registro</div>
        </div>
        <a href="../admin/espelho_pdf.php?func=<?= $user['id'] ?>&mes=<?= substr($hoje,0,7) ?>" target="_blank" class="btn btn-contorno">Baixar PDF</a>
    </div>
    <div class="card">
        <div id="espelho-content">
            <p style="color:var(--texto-3);text-align:center;padding:20px">Carregando espelho...</p>
        </div>
    </div>
</div>

<!-- ============ ABA: BANCO DE HORAS ============ -->
<div class="tab-content" id="tab-banco">
    <div class="page-head">
        <div>
            <h1>Banco de horas</h1>
            <div class="sub">Apuração de <?= $meses_pt[(int)date('n', strtotime($mes_inicio))] ?>, pela sua jornada por dia da semana</div>
        </div>
    </div>
    <div class="card">
        <?php
        // Saldo do mês pela apuração oficial (mesma do painel admin / espelho).
        $saldo_min = $ap_mes['saldo_periodo'];
        $saldo_h = intdiv(abs($saldo_min), 60);
        $saldo_m = abs($saldo_min) % 60;
        ?>
        <div style="text-align:center;padding:10px 0 16px">
            <div style="font-size:2.5rem;font-weight:700;font-variant-numeric:tabular-nums;color:<?= $saldo_min>=0?'var(--ok)':'var(--erro)' ?>">
                <?= $saldo_min>=0?'+':'-' ?><?= $saldo_h ?>h <?= sprintf('%02d',$saldo_m) ?>min
            </div>
            <div style="color:var(--texto-2);font-size:.82rem;margin-top:2px">saldo do mês de <?= $meses_pt[(int)date('n', strtotime($mes_inicio))] ?></div>
        </div>
        <div class="stats">
            <div class="stat"><div class="v" style="color:var(--ok)"><?= floor($ap_mes['total_positivo']/60) ?>h<?= sprintf('%02d',$ap_mes['total_positivo']%60) ?></div><div class="l">a favor</div></div>
            <div class="stat"><div class="v" style="color:var(--erro)"><?= floor(abs($ap_mes['total_negativo'])/60) ?>h<?= sprintf('%02d',abs($ap_mes['total_negativo'])%60) ?></div><div class="l">em débito</div></div>
            <div class="stat"><div class="v"><?= floor(($resumo_mes['min_total']??0)/60) ?>h</div><div class="l">trabalhadas</div></div>
            <div class="stat"><div class="v"><?= $resumo_mes['dias_trabalhados']?:0 ?></div><div class="l">dias</div></div>
        </div>
    </div>
</div>

<!-- ============ ABA: JUSTIFICATIVAS ============ -->
<div class="tab-content" id="tab-justificativa">
    <div class="page-head">
        <div>
            <h1>Justificativas</h1>
            <div class="sub">Justifique faltas e atrasos ou corrija uma batida esquecida</div>
        </div>
    </div>
    <div class="grid-2">
        <div class="card">
            <div class="segmented">
                <button type="button" class="seg active" data-cat="justificativa" onclick="setCategoria('justificativa')">Justificar falta/atraso</button>
                <button type="button" class="seg" data-cat="correcao" onclick="setCategoria('correcao')">Corrigir batida esquecida</button>
            </div>

            <form id="form-justificativa" onsubmit="enviarJustificativa(event)" enctype="multipart/form-data">
                <input type="hidden" name="categoria" id="jus-categoria" value="justificativa">

                <div class="field">
                    <label>Data</label>
                    <input type="date" name="data_ref" required value="<?= $hoje ?>" max="<?= $hoje ?>">
                </div>

                <!-- Campos da JUSTIFICATIVA -->
                <div id="campos-justificativa">
                    <div class="field">
                        <label>Tipo</label>
                        <select name="tipo">
                            <option value="atraso">Atraso</option>
                            <option value="falta">Falta</option>
                            <option value="saida_antecipada">Saída antecipada</option>
                            <option value="medico">Consulta médica</option>
                            <option value="atestado">Atestado médico</option>
                            <option value="abono">Abono</option>
                            <option value="feriado">Feriado</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                </div>

                <!-- Campos da CORREÇÃO -->
                <div id="campos-correcao" style="display:none">
                    <div class="alert info">Use quando esqueceu de registrar uma batida. A correção precisa ser aprovada pelo gestor para entrar no espelho.</div>
                    <div class="field-row">
                        <div class="field">
                            <label>Qual batida?</label>
                            <select name="batida_tipo">
                                <option value="entrada">Entrada</option>
                                <option value="saida_intervalo">Saída p/ intervalo</option>
                                <option value="retorno_intervalo">Retorno do intervalo</option>
                                <option value="saida">Saída</option>
                            </select>
                        </div>
                        <div class="field" style="flex:0 0 40%">
                            <label>Horário correto</label>
                            <input type="time" name="horario_correto">
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label>Motivo</label>
                    <textarea name="motivo" required minlength="10" rows="3" placeholder="Descreva o motivo..."></textarea>
                </div>

                <div class="field">
                    <label>Comprovação (opcional)</label>
                    <input type="file" name="anexo" accept=".pdf,.jpg,.jpeg,.png,.webp,image/*,application/pdf">
                    <small>Atestado, declaração, etc. PDF/JPG/PNG até 8 MB.</small>
                </div>

                <button class="btn btn-primario btn-lg" type="submit">Enviar para aprovação</button>
            </form>
        </div>

        <div class="card">
            <h2>Minhas solicitações</h2>
            <div id="minhas-justificativas">
                <p style="color:var(--texto-3);text-align:center;padding:14px">Carregando...</p>
            </div>
        </div>
    </div>
</div>

<!-- ============ ABA: HORA EXTRA ============ -->
<div class="tab-content" id="tab-extra">
    <div class="page-head">
        <div>
            <h1>Hora extra</h1>
            <div class="sub">Solicite aprovação do gestor antes de estender o expediente</div>
        </div>
    </div>
    <div class="card" style="max-width:560px">
        <form onsubmit="solicitarExtra(event)">
            <div class="field">
                <label>Quantidade (minutos)</label>
                <input type="number" name="minutos" required min="30" max="240" step="30" value="60">
                <small>Entre 30 e 240 minutos (4h no máximo)</small>
            </div>
            <div class="field">
                <label>Justificativa</label>
                <textarea name="justificativa" required minlength="20" rows="3" placeholder="Por que precisa fazer hora extra?"></textarea>
            </div>
            <button class="btn btn-primario btn-lg" type="submit">Solicitar aprovação</button>
        </form>
    </div>
</div>

<div class="rodape-info">
    DOT-ON v1.0 · <span class="dot-live"></span>Online · <a href="../" style="color:var(--texto-3)">dot-on.com.br/app</a>
</div>

</div><!-- /conteudo -->

<!-- Navegação inferior (mobile) -->
<nav class="bottomnav">
    <button class="tab active" data-tab="home"><?= $nav_icons['home'] ?>Início</button>
    <button class="tab" data-tab="espelho"><?= $nav_icons['espelho'] ?>Espelho</button>
    <button class="tab" data-tab="banco"><?= $nav_icons['banco'] ?>Banco</button>
    <button class="tab" data-tab="justificativa"><?= $nav_icons['justificativa'] ?>Justificar</button>
    <button class="tab" data-tab="extra"><?= $nav_icons['extra'] ?>Extra</button>
</nav>

</div><!-- /main -->
</div><!-- /layout -->

<script>
const token = <?= json_encode($user['api_token']) ?>;
const API = '../api';

function toggleMenu() {
    document.getElementById('menu').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('show');
}

function showTab(name) {
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === 'tab-' + name));
    if (name === 'espelho') carregarEspelho();
    if (name === 'justificativa') carregarMinhasJustificativas();
    window.scrollTo({top: 0});
}

function setCategoria(cat) {
    document.getElementById('jus-categoria').value = cat;
    document.querySelectorAll('.segmented .seg').forEach(b => b.classList.toggle('active', b.dataset.cat === cat));
    const ehCorrecao = cat === 'correcao';
    document.getElementById('campos-correcao').style.display = ehCorrecao ? 'block' : 'none';
    document.getElementById('campos-justificativa').style.display = ehCorrecao ? 'none' : 'block';
    // habilita/desabilita os campos exclusivos para não enviar dados do modo oculto
    document.querySelector('#campos-correcao select[name=batida_tipo]').disabled = !ehCorrecao;
    document.querySelector('#campos-correcao input[name=horario_correto]').disabled = !ehCorrecao;
    document.querySelector('#campos-correcao input[name=horario_correto]').required = ehCorrecao;
    document.querySelector('#campos-justificativa select[name=tipo]').disabled = ehCorrecao;
}

document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => showTab(t.dataset.tab));
});

// Estado inicial do seletor justificativa/correção
setCategoria('justificativa');

// Relógio em tempo real
setInterval(() => {
    const r = document.getElementById('relog');
    if (r) {
        const d = new Date();
        const t = d.toLocaleTimeString('pt-BR');
        r.childNodes[0].nodeValue = t + ' ';
    }
}, 1000);

function obterLocalizacao() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) { reject(new Error('Seu dispositivo não suporta geolocalização.')); return; }
        navigator.geolocation.getCurrentPosition(
            pos => resolve({latitude: pos.coords.latitude, longitude: pos.coords.longitude, precisao: pos.coords.accuracy}),
            err => {
                let msg = 'Não foi possível obter sua localização.';
                if (err.code === 1) msg = 'Você precisa PERMITIR o acesso à localização para bater ponto pelo navegador.';
                else if (err.code === 2) msg = 'Localização indisponível. Ligue o GPS/serviços de localização e tente de novo.';
                else if (err.code === 3) msg = 'Tempo esgotado ao obter a localização. Tente novamente.';
                reject(new Error(msg));
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    });
}

async function baterPonto(tipo) {
    if (!confirm('Confirmar batida: ' + tipo.replace('_',' ') + '?')) return;
    // Localização é obrigatória para bater ponto pelo navegador
    let loc;
    try {
        loc = await obterLocalizacao();
    } catch(e) {
        alert(e.message);
        return;
    }
    try {
        const r = await fetch(API + '/batida', {
            method: 'POST',
            headers: {'Content-Type':'application/json', 'X-Auth-Token': token},
            body: JSON.stringify({
                tipo,
                momento: new Date().toISOString().replace('T',' ').substring(0,19),
                hostname: 'web-portal', origem: 'web',
                latitude: loc.latitude, longitude: loc.longitude, precisao: loc.precisao
            })
        });
        const j = await r.json();
        if (j.ok) {
            alert('Ponto registrado! NSR #' + j.nsr);
            location.reload();
        } else {
            alert('Erro: ' + (j.erro || 'tente novamente'));
        }
    } catch(e) { alert('Erro de conexão: ' + e.message); }
}

function fmtSaldoMin(min) {
    const s = min < 0 ? '-' : '+';
    min = Math.abs(parseInt(min) || 0);
    return s + Math.floor(min/60) + 'h' + String(min%60).padStart(2,'0');
}

async function carregarEspelho() {
    const div = document.getElementById('espelho-content');
    try {
        const r = await fetch(API + '/sessao/mes', {headers:{'X-Auth-Token': token}});
        const j = await r.json();
        if (!j.ok || !j.dias) {
            div.innerHTML = '<p style="color:var(--texto-3);text-align:center;padding:20px">Sem dados disponíveis.</p>';
            return;
        }
        let html = '';
        if (j.resumo) {
            html += `<div class="chips">
                <div class="chip"><b style="color:var(--ok)">${fmtSaldoMin(j.resumo.minutos_a_favor)}</b><small>a favor</small></div>
                <div class="chip"><b style="color:var(--erro)">${fmtSaldoMin(j.resumo.minutos_em_debito)}</b><small>em débito</small></div>
                <div class="chip"><b style="color:${j.resumo.saldo_minutos>=0?'var(--ok)':'var(--erro)'}">${fmtSaldoMin(j.resumo.saldo_minutos)}</b><small>saldo</small></div>
            </div>`;
        }
        const corStatus = {falta:'var(--erro)', falta_abonada:'var(--ok)', feriado:'#0d9488'};
        html += '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Dia</th><th>Trabalhado</th><th>Saldo</th><th>Status</th></tr></thead><tbody>';
        j.dias.forEach(d => {
            const min = parseInt(d.minutos_trabalhados || 0);
            const h = Math.floor(min/60), m = min%60;
            const temSaldo = d.saldo !== null && d.saldo !== undefined;
            const saldo = parseInt(d.saldo || 0);
            const st = (d.status || '').replace('_',' ');
            html += `<tr>
                <td>${d.data_ref.substring(8,10)}/${d.data_ref.substring(5,7)}</td>
                <td>${h}h${String(m).padStart(2,'0')}</td>
                <td style="font-weight:600;color:${temSaldo?(saldo>=0?'var(--ok)':'var(--erro)'):'var(--texto-3)'}">${temSaldo?fmtSaldoMin(saldo):'—'}</td>
                <td><small style="color:${corStatus[d.status]||'var(--texto-2)'}">${st}</small></td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        div.innerHTML = html;
    } catch(e) { div.innerHTML = '<p style="color:var(--erro)">Erro: ' + e.message + '</p>'; }
}

async function enviarJustificativa(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type=submit]');
    btn.disabled = true;
    const fd = new FormData(e.target); // multipart — inclui o anexo quando houver
    try {
        const r = await fetch(API + '/justificativa', {method:'POST', headers:{'X-Auth-Token':token}, body: fd});
        const j = await r.json();
        if (j.ok) {
            alert('Enviado! Aguardando aprovação do gestor.');
            e.target.reset();
            setCategoria('justificativa');
            carregarMinhasJustificativas();
        } else alert(j.erro || 'Erro ao enviar.');
    } catch(err) { alert('Erro: ' + err.message); }
    finally { btn.disabled = false; }
}

async function carregarMinhasJustificativas() {
    const div = document.getElementById('minhas-justificativas');
    if (!div) return;
    try {
        const r = await fetch(API + '/justificativas/minhas', {headers:{'X-Auth-Token': token}});
        const j = await r.json();
        if (!j.ok || !j.itens || !j.itens.length) {
            div.innerHTML = '<p style="color:var(--texto-3);text-align:center;padding:14px">Nenhuma solicitação enviada ainda.</p>';
            return;
        }
        div.innerHTML = j.itens.map(it => {
            const dt = it.data_ref.substring(8,10)+'/'+it.data_ref.substring(5,7)+'/'+it.data_ref.substring(0,4);
            const rotulo = it.categoria === 'correcao' ? 'Correção' : 'Justificativa';
            let extra = '';
            if (it.categoria === 'correcao' && it.horario_correto) extra = ' · ' + it.tipo_label + ' ' + it.horario_correto.substring(0,5);
            const anexo = it.tem_anexo ? `<a class="jus-anexo" href="../admin/anexo.php?id=${it.id}&token=${encodeURIComponent(token)}" target="_blank">Ver comprovação</a>` : '';
            const dec = it.motivo_decisao ? `<div class="d" style="margin-top:3px"><em>Gestor: ${escapeHtml(it.motivo_decisao)}</em></div>` : '';
            return `<div class="jus-item">
                <div class="meta">
                    <div class="t">${rotulo} · ${escapeHtml(it.tipo_label)}${extra}</div>
                    <div class="d">${dt}</div>
                    <div class="m">${escapeHtml(it.motivo)}</div>
                    ${anexo}${dec}
                </div>
                <span class="st st-${it.status}">${it.status}</span>
            </div>`;
        }).join('');
    } catch(e) { div.innerHTML = '<p style="color:var(--erro)">Erro: ' + e.message + '</p>'; }
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function solicitarExtra(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const obj = {minutos: parseInt(fd.get('minutos')), justificativa: fd.get('justificativa')};
    try {
        const r = await fetch(API + '/hora-extra/solicitar', {method:'POST', headers:{'Content-Type':'application/json','X-Auth-Token':token}, body: JSON.stringify(obj)});
        const j = await r.json();
        if (j.ok) { alert('Solicitação enviada! Aguarde aprovação do gestor.'); e.target.reset(); showTab('home'); }
        else alert(j.erro || 'Erro ao enviar.');
    } catch(err) { alert('Erro: ' + err.message); }
}
</script>

</body>
</html>
