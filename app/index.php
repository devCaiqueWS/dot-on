<?php
/**
 * Landing comercial pública - DOT-ON SaaS
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DOT-ON · Controle de Ponto Digital · Conforme Portaria 671/2021</title>
<meta name="description" content="Sistema completo de registro de ponto eletrônico. Conformidade legal, agente Windows, painel gestor. Comece grátis em 10 minutos.">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;color:#1e293b;line-height:1.6;background:#fff}
.container{max-width:1200px;margin:auto;padding:0 24px}
nav{background:rgba(255,255,255,.95);backdrop-filter:blur(10px);position:sticky;top:0;z-index:100;border-bottom:1px solid #e2e8f0;padding:14px 0}
nav .container{display:flex;align-items:center;justify-content:space-between}
.logo{font-size:1.4rem;font-weight:800;color:#0284c7;display:flex;align-items:center;gap:8px;text-decoration:none}
.nav-links{display:flex;gap:24px;align-items:center}
.nav-links a{color:#475569;text-decoration:none;font-weight:500;font-size:.95rem}
.nav-links a:hover{color:#0284c7}
.btn{display:inline-block;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;transition:all .2s;border:none;cursor:pointer;font-size:.95rem}
.btn-primary{background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;box-shadow:0 4px 14px rgba(2,132,199,.3)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(2,132,199,.4)}
.btn-outline{border:1.5px solid #0284c7;color:#0284c7;background:transparent}
.btn-outline:hover{background:#0284c7;color:white}
.hero{background:linear-gradient(135deg,#0c4a6e,#0284c7);color:white;padding:80px 0 100px;position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M0 0h20v20H0V0zm20 20h20v20H20V20z'/%3E%3C/g%3E%3C/svg%3E")}
.hero .container{position:relative;z-index:1;display:grid;grid-template-columns:1.2fr 1fr;gap:60px;align-items:center}
.hero h1{font-size:3rem;line-height:1.15;margin-bottom:18px;font-weight:800}
.hero h1 .accent{background:linear-gradient(90deg,#38bdf8,#06b6d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero .sub{font-size:1.18rem;opacity:.92;margin-bottom:28px;max-width:540px}
.hero-cta{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px}
.hero-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:32px;padding-top:28px;border-top:1px solid rgba(255,255,255,.18)}
.hero-stats .item{text-align:center}
.hero-stats .num{font-size:1.8rem;font-weight:800;color:#38bdf8}
.hero-stats .lbl{font-size:.8rem;opacity:.85;text-transform:uppercase;letter-spacing:.06em}
.hero-img{background:rgba(255,255,255,.08);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:24px;font-family:'Courier New',monospace;font-size:.85rem;line-height:1.6}
.hero-img .line{display:block;color:rgba(255,255,255,.85)}
.hero-img .ok{color:#10b981}
.hero-img .key{color:#fbbf24}
section{padding:80px 0}
.features{background:#f8fafc}
h2.section-title{font-size:2.2rem;text-align:center;margin-bottom:14px;color:#0c4a6e;font-weight:800}
.section-sub{text-align:center;color:#64748b;font-size:1.05rem;margin-bottom:50px;max-width:600px;margin-left:auto;margin-right:auto}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px}
.feature-card{background:white;padding:30px;border-radius:14px;border:1px solid #e2e8f0;transition:all .2s}
.feature-card:hover{transform:translateY(-4px);box-shadow:0 10px 30px rgba(0,0,0,.08);border-color:#0284c7}
.feature-card .icon{font-size:2.4rem;margin-bottom:14px}
.feature-card h3{color:#0c4a6e;font-size:1.18rem;margin-bottom:10px}
.feature-card p{color:#64748b;font-size:.93rem}
.steps{background:white}
.steps-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px;counter-reset:step}
.step-card{position:relative;padding-top:50px;text-align:center}
.step-card::before{counter-increment:step;content:counter(step);position:absolute;top:0;left:50%;transform:translateX(-50%);width:42px;height:42px;background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.2rem;box-shadow:0 4px 14px rgba(2,132,199,.3)}
.step-card h3{color:#0c4a6e;margin-bottom:8px;font-size:1.05rem}
.step-card p{color:#64748b;font-size:.88rem}
.pricing{background:#f0f9ff}
.pricing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px;max-width:900px;margin:auto}
.price-card{background:white;border-radius:16px;padding:36px 28px;border:2px solid #e2e8f0;text-align:center;position:relative}
.price-card.featured{border-color:#0284c7;transform:scale(1.04);box-shadow:0 20px 40px rgba(2,132,199,.15)}
.price-card.featured::before{content:'MAIS POPULAR';position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;padding:5px 16px;border-radius:20px;font-size:.7rem;font-weight:800;letter-spacing:.1em}
.price-card h3{font-size:1.4rem;color:#0c4a6e;margin-bottom:8px}
.price-card .price{font-size:2.6rem;font-weight:800;color:#0284c7;margin:18px 0}
.price-card .price small{font-size:.85rem;color:#64748b;font-weight:500}
.price-card ul{list-style:none;margin:24px 0;text-align:left}
.price-card ul li{padding:8px 0;color:#475569;font-size:.92rem}
.price-card ul li::before{content:'✓';color:#10b981;font-weight:800;margin-right:8px}
.cta-final{background:linear-gradient(135deg,#0c4a6e,#0284c7);color:white;text-align:center}
.cta-final h2{color:white;font-size:2rem;margin-bottom:14px}
.cta-final p{font-size:1.1rem;opacity:.92;margin-bottom:24px}
footer{background:#0f172a;color:#94a3b8;padding:40px 0 20px;text-align:center}
footer a{color:#38bdf8;text-decoration:none}
.footer-links{display:flex;justify-content:center;gap:24px;margin:18px 0;flex-wrap:wrap;font-size:.9rem}
@media (max-width:768px){
.hero .container{grid-template-columns:1fr;text-align:center}
.hero h1{font-size:2.2rem}
.hero-cta{justify-content:center}
.hero-img{display:none}
.price-card.featured{transform:scale(1)}
}
</style>
</head>
<body>

<nav>
<div class="container">
<a href="/app/" class="logo">⏱ DOT-ON</a>
<div class="nav-links">
<a href="#features">Recursos</a>
<a href="#como">Como funciona</a>
<a href="#precos">Preços</a>
<a href="me/">📱 Portal Funcionário</a>
<a href="admin/login.php">Entrar</a>
<a href="signup.php" class="btn btn-primary">Começar Grátis</a>
</div>
</div>
</nav>

<section class="hero">
<div class="container">
<div>
<h1>Registro de Ponto <span class="accent">100% Digital</span> em 10 minutos</h1>
<p class="sub">Sistema completo de controle de jornada para sua empresa: agente Windows, painel gestor, espelho de ponto e exportação fiscal AFD/AEJ. <strong>Conforme Portaria 671/2021 do MTP.</strong></p>
<div class="hero-cta">
<a href="signup.php" class="btn btn-primary" style="font-size:1.05rem;padding:14px 28px">🚀 Começar Grátis Agora</a>
<a href="#features" class="btn" style="background:rgba(255,255,255,.15);color:white;backdrop-filter:blur(10px)">Ver recursos</a>
</div>
<div class="hero-stats">
<div class="item"><div class="num">10 min</div><div class="lbl">Para começar</div></div>
<div class="item"><div class="num">REP-P</div><div class="lbl">Conforme MTP</div></div>
<div class="item"><div class="num">0$</div><div class="lbl">Plano grátis</div></div>
</div>
</div>
<div class="hero-img">
<span class="line"><span class="key">$</span> DOT-ON-Agent.exe</span>
<span class="line">  <span class="ok">●</span> Conectado ao servidor</span>
<span class="line">  <span class="ok">●</span> Funcionário: João Silva</span>
<span class="line">  <span class="ok">●</span> Empresa: M4b Cosméticos</span>
<span class="line"><br></span>
<span class="line">  <span class="key">▶ ENTRADA</span> 08:02</span>
<span class="line">  <span class="key">⏸ SAÍDA INTERVALO</span> 12:00</span>
<span class="line">  <span class="key">⏯ RETORNO</span> 13:02</span>
<span class="line">  <span class="key">⏹ SAÍDA</span> 17:08</span>
<span class="line"><br></span>
<span class="line"><span class="ok">✓</span> CRP emitido · NSR #2451</span>
<span class="line"><span class="ok">✓</span> Hash blockchain · 7b36ef...</span>
</div>
</div>
</section>

<section id="features" class="features">
<div class="container">
<h2 class="section-title">Tudo que você precisa em um único sistema</h2>
<p class="section-sub">Conformidade legal, segurança e produtividade — sem instalação de servidor, sem manutenção.</p>
<div class="grid">
<div class="feature-card"><div class="icon">💻</div><h3>Agente Windows</h3><p>Aplicativo desktop em cada PC do funcionário. Registra entrada, saída e intervalos. Detecta ociosidade. Bloqueia tela após expediente.</p></div>
<div class="feature-card"><div class="icon">🌐</div><h3>Painel Web do Gestor</h3><p>Acompanhe batidas em tempo real, aprove horas extras, gere espelhos de ponto PDF e relatórios completos.</p></div>
<div class="feature-card"><div class="icon">🔒</div><h3>Hash Blockchain</h3><p>Cada batida é assinada criptograficamente e encadeada com a anterior. Impossível alterar sem deixar rastro.</p></div>
<div class="feature-card"><div class="icon">📋</div><h3>CRP Automático</h3><p>Comprovante de Registro de Ponto emitido a cada batida, com QR Code de validação pública.</p></div>
<div class="feature-card"><div class="icon">⚖️</div><h3>AFD / AEJ Fiscal</h3><p>Exportação no layout oficial Portaria 671/2021 do MTP, pronto para fiscalização.</p></div>
<div class="feature-card"><div class="icon">⏰</div><h3>Banco de Horas</h3><p>Cálculo automático de saldo. Workflow de aprovação de horas extras. Compensação flexível.</p></div>
</div>
</div>
</section>

<section id="como" class="steps">
<div class="container">
<h2 class="section-title">Como começar em 10 minutos</h2>
<p class="section-sub">Cadastro 100% online. Sem ligações comerciais, sem instalação de servidor.</p>
<div class="steps-list">
<div class="step-card"><h3>Crie sua conta</h3><p>Cadastre sua empresa em 5 etapas guiadas. Nome, CNPJ, jornada padrão e funcionários.</p></div>
<div class="step-card"><h3>Convide funcionários</h3><p>Importe a lista por planilha CSV/Excel. Cada funcionário recebe e-mail com link e senha.</p></div>
<div class="step-card"><h3>Instale o agente</h3><p>Cada funcionário baixa o DOT-ON-Agent.exe (60MB) e instala em poucos cliques no Windows.</p></div>
<div class="step-card"><h3>Comece a usar</h3><p>Funcionários batem ponto pelo agente; gestor acompanha tudo pelo painel web. Pronto!</p></div>
</div>
</div>
</section>

<section id="precos" class="pricing">
<div class="container">
<h2 class="section-title">Preço simples e justo</h2>
<p class="section-sub">Pague apenas pelo que usar. Sem fidelidade. Cancele quando quiser.</p>
<div class="pricing-grid">
<div class="price-card">
<h3>Grátis</h3>
<div class="price">R$ 0<small>/mês</small></div>
<ul>
<li>Até 5 funcionários</li>
<li>Agente Windows</li>
<li>Painel gestor</li>
<li>Espelho de ponto PDF</li>
<li>Suporte por e-mail</li>
</ul>
<a href="signup.php" class="btn btn-outline" style="display:block">Começar Grátis</a>
</div>
<div class="price-card featured">
<h3>Empresarial</h3>
<div class="price">R$ 9,90<small>/funcionário/mês</small></div>
<ul>
<li>Funcionários ilimitados</li>
<li>Tudo do plano Grátis</li>
<li>AFD/AEJ exportação fiscal</li>
<li>Banco de horas avançado</li>
<li>Multi-empresas</li>
<li>API de integração</li>
<li>Suporte prioritário</li>
</ul>
<a href="signup.php" class="btn btn-primary" style="display:block">Testar 30 dias grátis</a>
</div>
<div class="price-card">
<h3>REP-P Certificado</h3>
<div class="price">Sob<small>consulta</small></div>
<ul>
<li>Tudo do Empresarial</li>
<li>Certificado ICP-Brasil</li>
<li>Timestamp TSA</li>
<li>Auditoria fiscal completa</li>
<li>Treinamento incluso</li>
<li>SLA dedicado</li>
</ul>
<a href="mailto:robo@syscomai.com.br" class="btn btn-outline" style="display:block">Falar com vendas</a>
</div>
</div>
</div>
</section>

<section class="cta-final">
<div class="container">
<h2>Pronto para modernizar o ponto da sua empresa?</h2>
<p>Comece agora. Sem cartão de crédito. Sem instalação.</p>
<a href="signup.php" class="btn" style="background:white;color:#0284c7;font-size:1.1rem;padding:15px 36px;font-weight:800">🚀 Criar conta grátis</a>
</div>
</section>

<footer>
<div class="container">
<div class="logo" style="justify-content:center">⏱ DOT-ON</div>
<div class="footer-links">
<a href="admin/login.php">Entrar</a>
<a href="signup.php">Cadastro</a>
<a href="validar.php">Validar CRP</a>
<a href="downloads/DOT-ON-Agent.exe">Download Agente</a>
<a href="mailto:robo@syscomai.com.br">Contato</a>
</div>
<p style="font-size:.85rem">DOT-ON v1.0 · Sistema de Registro de Ponto Eletrônico · Conforme Portaria 671/2021 do MTP<br>
© 2026 SyscomAI · Hospedado em <a href="https://dot-on.com.br">dot-on.com.br</a></p>
</div>
</footer>

</body>
</html>
