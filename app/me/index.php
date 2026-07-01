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
if (!in_array('entrada', $tipos_feitos)) $proxima = ['tipo'=>'entrada', 'label'=>'Entrada', 'icon'=>'▶', 'cor'=>'#10b981'];
elseif (!in_array('saida_intervalo', $tipos_feitos)) $proxima = ['tipo'=>'saida_intervalo', 'label'=>'Saída para intervalo', 'icon'=>'⏸', 'cor'=>'#f59e0b'];
elseif (!in_array('retorno_intervalo', $tipos_feitos)) $proxima = ['tipo'=>'retorno_intervalo', 'label'=>'Retorno do intervalo', 'icon'=>'⏯', 'cor'=>'#0284c7'];
elseif (!in_array('saida', $tipos_feitos)) $proxima = ['tipo'=>'saida', 'label'=>'Saída', 'icon'=>'⏹', 'cor'=>'#ef4444'];

// Mês atual - resumo
$mes_inicio = date('Y-m-01');
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">
<meta name="theme-color" content="#0284c7">
<title>Meu DOT-ON · <?= htmlspecialchars($user['nome_completo']) ?></title>
<link rel="apple-touch-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='80' font-size='80'>⏱</text></svg>">
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:#f1f5f9;color:#1e293b;min-height:100vh}
.app{max-width:520px;margin:auto;background:white;min-height:100vh;display:flex;flex-direction:column}
.topbar{background:linear-gradient(135deg,#0c4a6e,#0284c7);color:white;padding:16px 20px;position:sticky;top:0;z-index:10;display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 12px rgba(0,0,0,.1)}
.topbar .who{font-size:.95rem;font-weight:600}
.topbar .who small{display:block;opacity:.75;font-size:.72rem;font-weight:400;margin-top:2px}
.topbar .menu-btn{background:rgba(255,255,255,.15);border:none;color:white;font-size:1.2rem;width:36px;height:36px;border-radius:50%;cursor:pointer}
.tabs{display:flex;background:white;border-bottom:1px solid #e2e8f0;position:sticky;top:64px;z-index:9}
.tab{flex:1;padding:13px 8px;text-align:center;font-size:.85rem;color:#94a3b8;cursor:pointer;border-bottom:3px solid transparent;font-weight:600;transition:all .15s}
.tab.active{color:#0284c7;border-bottom-color:#0284c7}
.tab .ic{display:block;font-size:1.4rem;margin-bottom:2px}
.content{flex:1;padding:18px;padding-bottom:30px}
.relog{text-align:center;font-family:'Courier New',monospace;font-size:2.8rem;font-weight:800;color:#0c4a6e;letter-spacing:.04em;margin:10px 0}
.relog small{display:block;font-size:.9rem;color:#64748b;font-family:'Segoe UI',sans-serif;font-weight:500;margin-top:4px}
.card{background:white;border-radius:14px;padding:18px;margin-bottom:14px;box-shadow:0 2px 12px rgba(0,0,0,.05);border:1px solid #e2e8f0}
.card h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:10px;font-weight:700}
.batida-btn{display:flex;align-items:center;justify-content:center;gap:12px;width:100%;padding:22px;border:none;border-radius:14px;font-size:1.2rem;font-weight:700;color:white;cursor:pointer;margin-bottom:10px;transition:all .15s;box-shadow:0 6px 18px rgba(0,0,0,.15)}
.batida-btn:active{transform:scale(.97)}
.batida-btn .ic{font-size:1.6rem}
.progress{background:#f1f5f9;border-radius:8px;height:8px;overflow:hidden;margin:10px 0 4px}
.progress-bar{height:100%;background:linear-gradient(90deg,#0284c7,#38bdf8);transition:width .3s}
.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:10px}
.stat{background:#f1f5f9;border-radius:10px;padding:12px;text-align:center}
.stat .v{font-size:1.4rem;font-weight:800;color:#0c4a6e}
.stat .l{font-size:.72rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-top:2px}
.batidas-list{display:flex;flex-direction:column;gap:8px}
.batida-item{display:flex;align-items:center;gap:12px;padding:10px;background:#f8fafc;border-radius:10px;font-size:.9rem}
.batida-item .ic-tipo{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;color:white;flex-shrink:0}
.batida-item .det{flex:1}
.batida-item .nm{font-weight:600;color:#0c4a6e;text-transform:capitalize}
.batida-item .hr{color:#64748b;font-size:.82rem}
.batida-item .nsr{font-family:'Courier New',monospace;color:#94a3b8;font-size:.72rem}
.tipo-entrada{background:#10b981}
.tipo-saida_intervalo{background:#f59e0b}
.tipo-retorno_intervalo{background:#0284c7}
.tipo-saida{background:#ef4444}
.tab-content{display:none}
.tab-content.active{display:block}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:.88rem;display:flex;gap:10px;align-items:flex-start}
.alert.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.alert.warn{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}
.alert.info{background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd}
.menu{position:fixed;top:0;right:-280px;width:280px;height:100vh;background:white;box-shadow:-4px 0 20px rgba(0,0,0,.15);transition:right .25s;z-index:100;padding:60px 0 20px}
.menu.open{right:0}
.menu a{display:flex;align-items:center;gap:12px;padding:14px 22px;color:#1e293b;text-decoration:none;font-weight:500;border-bottom:1px solid #f1f5f9}
.menu a:hover{background:#f8fafc;color:#0284c7}
.menu .close{position:absolute;top:14px;right:14px;background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99;display:none}
.overlay.show{display:block}
.bottom-info{text-align:center;color:#94a3b8;font-size:.75rem;padding:14px;margin-top:auto}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.dot-live{display:inline-block;width:8px;height:8px;background:#10b981;border-radius:50%;animation:pulse 1.5s infinite}
.segmented{display:flex;background:#f1f5f9;border-radius:10px;padding:4px;margin-bottom:14px;gap:4px}
.segmented .seg{flex:1;padding:9px 8px;border:none;background:transparent;border-radius:8px;font-size:.82rem;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s}
.segmented .seg.active{background:white;color:#0284c7;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.jus-item{display:flex;align-items:flex-start;gap:10px;padding:11px;background:#f8fafc;border-radius:10px;font-size:.85rem;margin-bottom:8px}
.jus-item .meta{flex:1}
.jus-item .meta .t{font-weight:600;color:#0c4a6e}
.jus-item .meta .d{color:#64748b;font-size:.78rem;margin-top:2px}
.jus-item .meta .m{color:#475569;font-size:.8rem;margin-top:4px}
.st{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 8px;border-radius:20px;white-space:nowrap}
.st-pendente{background:#fef3c7;color:#92400e}
.st-aprovada{background:#d1fae5;color:#065f46}
.st-rejeitada{background:#fee2e2;color:#991b1b}
.jus-anexo{display:inline-block;margin-top:4px;color:#0284c7;text-decoration:none;font-size:.78rem}
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<div class="menu" id="menu">
<button class="close" onclick="toggleMenu()">✕</button>
<a href="#" onclick="showTab('home');toggleMenu();return false">🏠 Início</a>
<a href="#" onclick="showTab('espelho');toggleMenu();return false">📋 Espelho do mês</a>
<a href="#" onclick="showTab('banco');toggleMenu();return false">⚖ Banco de horas</a>
<a href="#" onclick="showTab('justificativa');toggleMenu();return false">📝 Justificar / Corrigir ponto</a>
<a href="#" onclick="showTab('extra');toggleMenu();return false">⏰ Solicitar hora extra</a>
<hr style="border:none;border-top:1px solid #e2e8f0;margin:8px 0">
<a href="../admin/trocar_senha.php">🔐 Trocar senha</a>
<a href="../admin/logout.php">🚪 Sair</a>
</div>

<div class="app">

<div class="topbar">
<div class="who">
<?= htmlspecialchars(explode(' ', $user['nome_completo'])[0]) ?> 👋
<small>🏢 <?= htmlspecialchars($empresa['nome_fantasia'] ?: $empresa['razao_social']) ?></small>
</div>
<button class="menu-btn" onclick="toggleMenu()">☰</button>
</div>

<div class="tabs">
<div class="tab active" data-tab="home"><span class="ic">⏱</span>Bater Ponto</div>
<div class="tab" data-tab="espelho"><span class="ic">📋</span>Espelho</div>
<div class="tab" data-tab="banco"><span class="ic">⚖</span>Banco</div>
</div>

<div class="content">

<!-- TAB HOME (Bater ponto) -->
<div class="tab-content active" id="tab-home">

<div class="relog" id="relog">
<?= date('H:i:s') ?>
<small><?= htmlspecialchars(date('l, d \d\e F \d\e Y', strtotime($hoje))) ?></small>
</div>

<div class="card">
<h3>Hoje</h3>

<?php if ($proxima): ?>
<button class="batida-btn" style="background:linear-gradient(135deg,<?= $proxima['cor'] ?>,<?= $proxima['cor'] ?>dd)" onclick="baterPonto('<?= $proxima['tipo'] ?>')">
<span class="ic"><?= $proxima['icon'] ?></span>
<?= $proxima['label'] ?>
</button>
<?php else: ?>
<div class="alert ok">
✅ <strong>Expediente concluído!</strong> Todas as batidas registradas.
</div>
<?php endif; ?>

<div class="progress"><div class="progress-bar" style="width:<?= number_format($pct,1) ?>%"></div></div>
<div style="display:flex;justify-content:space-between;font-size:.78rem;color:#64748b;margin-top:4px">
<span><?= $horas_hoje ?>h <?= $mins_hoje ?>min trabalhados</span>
<span><?= floor($min_objetivo/60) ?>h diárias</span>
</div>

<div class="stats">
<div class="stat">
<div class="v"><?= count($batidas_hoje) ?></div>
<div class="l">batidas hoje</div>
</div>
<div class="stat">
<div class="v"><?= number_format($pct, 0) ?>%</div>
<div class="l">meta diária</div>
</div>
</div>
</div>

<div class="card">
<h3>Batidas de hoje</h3>
<?php if (!$batidas_hoje): ?>
<p style="color:#94a3b8;text-align:center;padding:14px">Nenhuma batida registrada ainda hoje.</p>
<?php else: ?>
<div class="batidas-list">
<?php foreach (array_reverse($batidas_hoje) as $b): 
    $ic = ['entrada'=>'▶','saida_intervalo'=>'⏸','retorno_intervalo'=>'⏯','saida'=>'⏹'][$b['tipo']] ?? '·';
?>
<div class="batida-item">
<div class="ic-tipo tipo-<?= $b['tipo'] ?>"><?= $ic ?></div>
<div class="det">
<div class="nm"><?= str_replace('_',' ',$b['tipo']) ?></div>
<div class="hr"><?= date('H:i:s', strtotime($b['momento'])) ?> · <span class="nsr">NSR <?= str_pad($b['nsr'],6,'0',STR_PAD_LEFT) ?></span></div>
</div>
<a href="../validar.php?nsr=<?= $b['nsr'] ?>&t=<?= substr($b['hash_registro'] ?? '',0,16) ?>" target="_blank" style="color:#0284c7;text-decoration:none;font-size:.78rem">CRP →</a>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<?php if ($escala): ?>
<div class="card">
<h3>Sua jornada</h3>
<div style="display:flex;justify-content:space-around;text-align:center;gap:10px">
<div><div style="font-size:1.4rem;font-weight:800;color:#10b981">▶</div><small style="color:#64748b">Entrada<br><strong style="color:#0c4a6e"><?= $folga_hoje ? '—' : substr($escala['entrada'],0,5) ?></strong></small></div>
<div><div style="font-size:1.4rem;font-weight:800;color:#f59e0b">🍽</div><small style="color:#64748b">Almoço<br><strong style="color:#0c4a6e"><?= $folga_hoje ? '—' : $almoco_hoje.'min' ?></strong></small></div>
<div><div style="font-size:1.4rem;font-weight:800;color:#ef4444">⏹</div><small style="color:#64748b">Saída<br><strong style="color:#0c4a6e"><?= $folga_hoje ? '—' : substr($escala['saida'],0,5) ?></strong></small></div>
</div>
<?php if ($folga_hoje): ?><p style="text-align:center;color:#f59e0b;font-weight:600;margin-top:8px">🌴 Folga hoje</p><?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- TAB ESPELHO -->
<div class="tab-content" id="tab-espelho">
<div class="card">
<h3>📅 Mês atual</h3>
<p style="color:#94a3b8;margin-bottom:12px">Resumo: <strong><?= $resumo_mes['dias_trabalhados'] ?: 0 ?> dias</strong> trabalhados</p>
<div id="espelho-content">
<p style="color:#94a3b8;text-align:center;padding:20px">Carregando espelho...</p>
</div>
<a href="../admin/espelho_pdf.php?func=<?= $user['id'] ?>&mes=<?= substr($hoje,0,7) ?>" target="_blank" class="batida-btn" style="background:linear-gradient(135deg,#0284c7,#38bdf8);margin-top:10px;font-size:.95rem;padding:14px">📥 Baixar Espelho em PDF</a>
</div>
</div>

<!-- TAB BANCO -->
<div class="tab-content" id="tab-banco">
<div class="card">
<h3>⚖ Banco de horas</h3>
<?php 
$saldo_min = ($resumo_mes['min_total'] ?? 0) + ($resumo_mes['min_extras'] ?? 0) - ($resumo_mes['dias_trabalhados'] ?: 0) * $min_objetivo;
$saldo_h = intdiv(abs($saldo_min), 60);
$saldo_m = abs($saldo_min) % 60;
?>
<div style="text-align:center;padding:14px">
<div style="font-size:2.4rem;font-weight:800;color:<?= $saldo_min>=0?'#10b981':'#ef4444' ?>">
<?= $saldo_min>=0?'+':'-' ?><?= $saldo_h ?>h <?= $saldo_m ?>min
</div>
<small style="color:#64748b">Saldo do mês de <?= date('F', strtotime($mes_inicio)) ?></small>
</div>
<div class="stats">
<div class="stat"><div class="v"><?= floor(($resumo_mes['min_total']??0)/60) ?>h</div><div class="l">trabalhadas</div></div>
<div class="stat"><div class="v"><?= floor(($resumo_mes['min_extras']??0)/60) ?>h</div><div class="l">extras aprovadas</div></div>
<div class="stat"><div class="v"><?= floor(($resumo_mes['min_ociosos']??0)/60) ?>h</div><div class="l">ociosos</div></div>
<div class="stat"><div class="v"><?= $resumo_mes['dias_trabalhados']?:0 ?></div><div class="l">dias</div></div>
</div>
</div>
</div>

<!-- TAB JUSTIFICATIVA / CORREÇÃO -->
<div class="tab-content" id="tab-justificativa">
<div class="card">
<h3>📝 Justificar ou corrigir ponto</h3>

<div class="segmented">
<button type="button" class="seg active" data-cat="justificativa" onclick="setCategoria('justificativa')">Justificar falta/atraso</button>
<button type="button" class="seg" data-cat="correcao" onclick="setCategoria('correcao')">Corrigir batida esquecida</button>
</div>

<form id="form-justificativa" onsubmit="enviarJustificativa(event)" enctype="multipart/form-data">
<input type="hidden" name="categoria" id="jus-categoria" value="justificativa">

<div style="margin-bottom:12px">
<label style="display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px">Data</label>
<input type="date" name="data_ref" required value="<?= $hoje ?>" max="<?= $hoje ?>" style="width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px">
</div>

<!-- Campos da JUSTIFICATIVA -->
<div id="campos-justificativa">
<div style="margin-bottom:12px">
<label style="display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px">Tipo</label>
<select name="tipo" style="width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px">
<option value="atraso">Atraso</option>
<option value="falta">Falta</option>
<option value="saida_antecipada">Saída antecipada</option>
<option value="medico">Consulta médica</option>
<option value="atestado">Atestado médico</option>
<option value="abono">Abono</option>
<option value="outro">Outro</option>
</select>
</div>
</div>

<!-- Campos da CORREÇÃO -->
<div id="campos-correcao" style="display:none">
<div class="alert info" style="margin-bottom:12px">ℹ️ Use quando esqueceu de registrar uma batida. A correção precisa ser aprovada pelo gestor para entrar no espelho.</div>
<div style="display:flex;gap:10px;margin-bottom:12px">
<div style="flex:1">
<label style="display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px">Qual batida?</label>
<select name="batida_tipo" style="width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px">
<option value="entrada">Entrada</option>
<option value="saida_intervalo">Saída p/ intervalo</option>
<option value="retorno_intervalo">Retorno do intervalo</option>
<option value="saida">Saída</option>
</select>
</div>
<div style="width:42%">
<label style="display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px">Horário correto</label>
<input type="time" name="horario_correto" style="width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px">
</div>
</div>
</div>

<div style="margin-bottom:12px">
<label style="display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px">Motivo</label>
<textarea name="motivo" required minlength="10" rows="3" style="width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-family:inherit;resize:vertical" placeholder="Descreva o motivo..."></textarea>
</div>

<div style="margin-bottom:14px">
<label style="display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px">Comprovação (opcional)</label>
<input type="file" name="anexo" accept=".pdf,.jpg,.jpeg,.png,.webp,image/*,application/pdf" style="width:100%;padding:9px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#f8fafc;font-size:.85rem">
<small style="color:#94a3b8;font-size:.72rem">Atestado, declaração, etc. PDF/JPG/PNG até 8 MB.</small>
</div>

<button class="batida-btn" type="submit" style="background:linear-gradient(135deg,#0284c7,#38bdf8)">📨 Enviar para aprovação</button>
</form>
</div>

<div class="card">
<h3>📌 Minhas solicitações</h3>
<div id="minhas-justificativas">
<p style="color:#94a3b8;text-align:center;padding:14px">Carregando...</p>
</div>
</div>
</div>

<!-- TAB EXTRA -->
<div class="tab-content" id="tab-extra">
<div class="card">
<h3>⏰ Solicitar hora extra</h3>
<form onsubmit="solicitarExtra(event)">
<div style="margin-bottom:12px">
<label style="display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px">Quantidade (minutos)</label>
<input type="number" name="minutos" required min="30" max="240" step="30" value="60" style="width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px">
<small style="color:#94a3b8;font-size:.75rem">Entre 30 e 240 minutos (4h máximo)</small>
</div>
<div style="margin-bottom:14px">
<label style="display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:6px">Justificativa</label>
<textarea name="justificativa" required minlength="20" rows="3" style="width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-family:inherit" placeholder="Por que precisa fazer hora extra?"></textarea>
</div>
<button class="batida-btn" type="submit" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">⏰ Solicitar Aprovação</button>
</form>
</div>
</div>

</div>

<div class="bottom-info">
DOT-ON v1.0 · <span class="dot-live"></span> Online<br>
<a href="../" style="color:#94a3b8">dot-on.com.br/app</a>
</div>

</div>

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

async function baterPonto(tipo) {
    if (!confirm('Confirmar batida: ' + tipo.replace('_',' ') + '?')) return;
    try {
        const r = await fetch(API + '/batida', {
            method: 'POST',
            headers: {'Content-Type':'application/json', 'X-Auth-Token': token},
            body: JSON.stringify({tipo, momento: new Date().toISOString().replace('T',' ').substring(0,19), hostname: 'web-portal'})
        });
        const j = await r.json();
        if (j.ok) {
            alert('✅ Ponto registrado! NSR #' + j.nsr);
            location.reload();
        } else {
            alert('❌ Erro: ' + (j.erro || 'tente novamente'));
        }
    } catch(e) { alert('Erro de conexão: ' + e.message); }
}

async function carregarEspelho() {
    const div = document.getElementById('espelho-content');
    try {
        const r = await fetch(API + '/sessao/mes', {headers:{'X-Auth-Token': token}});
        const j = await r.json();
        if (!j.ok || !j.dias) {
            div.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:20px">Sem dados disponíveis.</p>';
            return;
        }
        let html = '<table style="width:100%;font-size:.82rem;border-collapse:collapse"><thead><tr style="background:#f1f5f9"><th style="padding:8px;text-align:left">Dia</th><th>Trab.</th><th>Extra</th><th>Status</th></tr></thead><tbody>';
        j.dias.forEach(d => {
            const min = parseInt(d.minutos_trabalhados || 0);
            const h = Math.floor(min/60), m = min%60;
            const ext = parseInt(d.minutos_extras || 0);
            const eh = Math.floor(ext/60), em = ext%60;
            html += `<tr><td style="padding:6px 8px;border-bottom:1px solid #f1f5f9">${d.data_ref.substring(8,10)}/${d.data_ref.substring(5,7)}</td><td style="text-align:center">${h}h${String(m).padStart(2,'0')}</td><td style="text-align:center;color:${ext>0?'#f59e0b':'#94a3b8'}">${ext>0?eh+'h'+String(em).padStart(2,'0'):'—'}</td><td style="text-align:center"><small>${d.status}</small></td></tr>`;
        });
        html += '</tbody></table>';
        div.innerHTML = html;
    } catch(e) { div.innerHTML = '<p style="color:#ef4444">Erro: ' + e.message + '</p>'; }
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
            alert('✅ Enviado! Aguardando aprovação do gestor.');
            e.target.reset();
            setCategoria('justificativa');
            carregarMinhasJustificativas();
        } else alert('❌ ' + (j.erro || 'erro'));
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
            div.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:14px">Nenhuma solicitação enviada ainda.</p>';
            return;
        }
        div.innerHTML = j.itens.map(it => {
            const dt = it.data_ref.substring(8,10)+'/'+it.data_ref.substring(5,7)+'/'+it.data_ref.substring(0,4);
            const icone = it.categoria === 'correcao' ? '🛠' : '📝';
            let extra = '';
            if (it.categoria === 'correcao' && it.horario_correto) extra = ' · ' + it.tipo_label + ' ' + it.horario_correto.substring(0,5);
            const anexo = it.tem_anexo ? `<a class="jus-anexo" href="../admin/anexo.php?id=${it.id}&token=${encodeURIComponent(token)}" target="_blank">📎 ver comprovação</a>` : '';
            const dec = it.motivo_decisao ? `<div class="d" style="margin-top:3px"><em>Gestor: ${escapeHtml(it.motivo_decisao)}</em></div>` : '';
            return `<div class="jus-item">
                <div class="meta">
                    <div class="t">${icone} ${escapeHtml(it.tipo_label)}${extra}</div>
                    <div class="d">${dt}</div>
                    <div class="m">${escapeHtml(it.motivo)}</div>
                    ${anexo}${dec}
                </div>
                <span class="st st-${it.status}">${it.status}</span>
            </div>`;
        }).join('');
    } catch(e) { div.innerHTML = '<p style="color:#ef4444">Erro: ' + e.message + '</p>'; }
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
        if (j.ok) { alert('⏰ Solicitação enviada! Aguarde aprovação do gestor.'); e.target.reset(); showTab('home'); }
        else alert('❌ ' + (j.erro || 'erro'));
    } catch(err) { alert('Erro: ' + err.message); }
}
</script>

</body>
</html>
