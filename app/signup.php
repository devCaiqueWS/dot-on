<?php
/**
 * Wizard de cadastro de empresa - DOT-ON SaaS
 * 5 etapas: Conta → Empresa → Jornada → Funcionários → Confirmação
 */
session_start();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DOT-ON · Criar conta grátis</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#0c4a6e,#0284c7);min-height:100vh;padding:20px;color:#1e293b}
.wizard{max-width:780px;margin:30px auto;background:white;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}
.wizard-header{background:linear-gradient(135deg,#0c4a6e,#0284c7);color:white;padding:24px 32px;text-align:center}
.wizard-header h1{font-size:1.5rem;margin-bottom:6px}
.wizard-header .sub{opacity:.9;font-size:.9rem}
.steps-nav{display:flex;background:#f1f5f9;padding:18px 0;border-bottom:1px solid #e2e8f0}
.step-dot{flex:1;text-align:center;position:relative;color:#94a3b8;font-size:.85rem;font-weight:600}
.step-dot::before{content:attr(data-num);display:block;width:34px;height:34px;margin:auto;background:#cbd5e1;color:white;border-radius:50%;line-height:34px;font-weight:800;margin-bottom:6px}
.step-dot.active{color:#0284c7}
.step-dot.active::before{background:linear-gradient(135deg,#0284c7,#38bdf8);box-shadow:0 4px 12px rgba(2,132,199,.3)}
.step-dot.done{color:#10b981}
.step-dot.done::before{background:#10b981;content:'✓'}
.wizard-body{padding:34px 38px;min-height:380px}
.wizard-body h2{font-size:1.5rem;color:#0c4a6e;margin-bottom:8px}
.wizard-body .step-desc{color:#64748b;margin-bottom:24px;font-size:.95rem}
.field{margin-bottom:16px}
.field label{display:block;font-size:.85rem;font-weight:600;color:#475569;margin-bottom:6px}
.field input,.field select,.field textarea{width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:.95rem;background:white;font-family:inherit;outline:none;transition:all .15s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.15)}
.field .hint{font-size:.78rem;color:#94a3b8;margin-top:4px}
.field .err{font-size:.82rem;color:#dc2626;margin-top:4px;display:none}
.field.has-error input{border-color:#dc2626}
.field.has-error .err{display:block}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.checkbox-group{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.checkbox-group label{flex:1;min-width:80px;background:white;border:1.5px solid #cbd5e1;border-radius:8px;padding:8px;text-align:center;cursor:pointer;font-size:.85rem;font-weight:600;transition:all .15s}
.checkbox-group input{display:none}
.checkbox-group input:checked + span{color:white}
.checkbox-group label:has(input:checked){background:linear-gradient(135deg,#0284c7,#38bdf8);border-color:#0284c7;color:white}
.box{background:#f0f9ff;border-left:4px solid #0284c7;border-radius:8px;padding:14px 18px;margin:18px 0;font-size:.9rem;color:#475569}
.box.success{background:#f0fdf4;border-color:#10b981}
.box.warn{background:#fef3c7;border-color:#f59e0b}
.btns{display:flex;gap:12px;margin-top:30px;justify-content:space-between}
.btn{padding:12px 28px;border-radius:8px;border:none;cursor:pointer;font-weight:600;font-size:.95rem;transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
.btn-primary{background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;box-shadow:0 4px 12px rgba(2,132,199,.3)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(2,132,199,.4)}
.btn-primary:disabled{opacity:.5;cursor:not-allowed}
.btn-secondary{background:#e2e8f0;color:#475569}
.btn-secondary:hover{background:#cbd5e1}
.spinner{display:none;width:18px;height:18px;border:2.5px solid white;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite}
.spinner.show{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
.upload-zone{border:2.5px dashed #cbd5e1;border-radius:12px;padding:30px;text-align:center;cursor:pointer;transition:all .2s;background:#f8fafc}
.upload-zone:hover{border-color:#0284c7;background:#f0f9ff}
.upload-zone.has-file{border-color:#10b981;background:#f0fdf4}
.upload-zone .icon{font-size:2.4rem;margin-bottom:8px;color:#0284c7}
.upload-zone .title{font-weight:600;color:#0c4a6e}
.upload-zone .hint{font-size:.83rem;color:#64748b;margin-top:6px}
.preview{margin-top:14px;max-height:200px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem}
.preview table{width:100%;border-collapse:collapse}
.preview th,.preview td{padding:6px 10px;text-align:left;border-bottom:1px solid #e2e8f0}
.preview th{background:#f1f5f9;font-weight:600;color:#475569;position:sticky;top:0}
.success-screen{text-align:center;padding:30px 0}
.success-screen .check{font-size:4rem;color:#10b981;margin-bottom:14px}
.success-screen h2{color:#10b981;font-size:1.8rem}
.success-screen .credentials{background:#f1f5f9;border-radius:10px;padding:18px;margin:24px auto;max-width:420px;text-align:left;font-family:'Courier New',monospace;font-size:.88rem}
.toggle-row{margin-top:18px;padding:16px;background:#f8fafc;border-radius:10px;display:flex;justify-content:space-between;align-items:center}
.toggle-row label{font-weight:600;color:#475569;cursor:pointer}
.switch{position:relative;display:inline-block;width:46px;height:24px}
.switch input{display:none}
.slider{position:absolute;cursor:pointer;background:#cbd5e1;inset:0;border-radius:24px;transition:.2s}
.slider::before{content:'';position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.2s}
input:checked + .slider{background:#0284c7}
input:checked + .slider::before{transform:translateX(22px)}
@media (max-width:600px){
.row,.row-3{grid-template-columns:1fr}
.wizard-body{padding:24px}
.step-dot{font-size:.72rem}
}
</style>
</head>
<body>

<div class="wizard">
<div class="wizard-header">
<h1>⏱ Criar sua conta no DOT-ON</h1>
<div class="sub">5 etapas · ~10 minutos · Sem cartão de crédito</div>
</div>

<div class="steps-nav">
<div class="step-dot active" data-num="1" id="snav-1">Conta</div>
<div class="step-dot" data-num="2" id="snav-2">Empresa</div>
<div class="step-dot" data-num="3" id="snav-3">Jornada</div>
<div class="step-dot" data-num="4" id="snav-4">Funcionários</div>
<div class="step-dot" data-num="5" id="snav-5">Pronto!</div>
</div>

<div class="wizard-body">

<!-- ETAPA 1: CONTA -->
<div class="step" id="step-1">
<h2>Quem é você?</h2>
<p class="step-desc">Você será o administrador da conta. Use seu e-mail corporativo.</p>
<div class="field"><label>Seu nome completo *</label><input type="text" id="f_nome" placeholder="Ex: Maria Silva" required></div>
<div class="field"><label>E-mail *</label><input type="email" id="f_email" placeholder="voce@suaempresa.com.br" required><div class="hint">Será seu login no painel</div></div>
<div class="row">
<div class="field"><label>Celular</label><input type="tel" id="f_celular" placeholder="(11) 99999-9999"></div>
<div class="field"><label>Senha *</label><input type="password" id="f_senha" placeholder="Mínimo 8 caracteres" minlength="8" required></div>
</div>
<div class="box">🔒 Sua senha é criptografada com bcrypt. Nunca armazenamos em texto puro.</div>
</div>

<!-- ETAPA 2: EMPRESA -->
<div class="step" id="step-2" style="display:none">
<h2>Sua Empresa</h2>
<p class="step-desc">Informe os dados da empresa. Vamos validar o CNPJ automaticamente.</p>
<div class="row">
<div class="field"><label>CNPJ *</label><input type="text" id="f_cnpj" placeholder="00.000.000/0001-00" maxlength="18" required><div class="hint">Clique fora para validar</div></div>
<div class="field"><label>Razão Social *</label><input type="text" id="f_razao" placeholder="EMPRESA LTDA"></div>
</div>
<div class="field"><label>Nome Fantasia</label><input type="text" id="f_fantasia" placeholder="Empresa"></div>
<div class="row">
<div class="field"><label>Telefone</label><input type="tel" id="f_tel" placeholder="(11) 3000-0000"></div>
<div class="field"><label>Setor</label><select id="f_setor"><option value="">Selecione...</option><option>Comércio</option><option>Indústria</option><option>Serviços</option><option>Tecnologia</option><option>Saúde</option><option>Educação</option><option>Construção</option><option>Outro</option></select></div>
</div>
<div class="row">
<div class="field"><label>CEP</label><input type="text" id="f_cep" placeholder="00000-000" maxlength="9"></div>
<div class="field"><label>Cidade / UF</label><input type="text" id="f_cidade" placeholder="São Paulo / SP"></div>
</div>
<div class="field"><label>Endereço completo</label><input type="text" id="f_endereco" placeholder="Rua, número, bairro"></div>
</div>

<!-- ETAPA 3: JORNADA -->
<div class="step" id="step-3" style="display:none">
<h2>Jornada padrão</h2>
<p class="step-desc">Horários da maioria dos funcionários. Casos especiais podem ser configurados depois.</p>
<div class="row-3">
<div class="field"><label>Entrada *</label><input type="time" id="f_entrada" value="08:00"></div>
<div class="field"><label>Saída para almoço</label><input type="time" id="f_almoco_ini" value="12:00"></div>
<div class="field"><label>Retorno do almoço</label><input type="time" id="f_almoco_fim" value="13:00"></div>
</div>
<div class="row">
<div class="field"><label>Saída final *</label><input type="time" id="f_saida" value="17:00"></div>
<div class="field"><label>Tolerância (minutos)</label><input type="number" id="f_tolerancia" value="10" min="0" max="60"></div>
</div>
<div class="field"><label>Dias de trabalho *</label>
<div class="checkbox-group">
<label><input type="checkbox" value="1" checked> <span>Seg</span></label>
<label><input type="checkbox" value="2" checked> <span>Ter</span></label>
<label><input type="checkbox" value="4" checked> <span>Qua</span></label>
<label><input type="checkbox" value="8" checked> <span>Qui</span></label>
<label><input type="checkbox" value="16" checked> <span>Sex</span></label>
<label><input type="checkbox" value="32"> <span>Sáb</span></label>
<label><input type="checkbox" value="64"> <span>Dom</span></label>
</div>
</div>
<div class="box">⏰ Carga semanal estimada: <strong id="carga_calc">40h/semana</strong> · <span id="carga_msg">Padrão CLT</span></div>
</div>

<!-- ETAPA 4: FUNCIONÁRIOS -->
<div class="step" id="step-4" style="display:none">
<h2>Adicione seus funcionários</h2>
<p class="step-desc">Faça upload de uma planilha CSV ou Excel com seus colaboradores.</p>

<div class="upload-zone" id="upload_zone" onclick="document.getElementById('f_funcs').click()">
<div class="icon">📋</div>
<div class="title">Clique aqui ou arraste sua planilha</div>
<div class="hint">Formatos aceitos: CSV, XLSX · Colunas: <strong>nome, email, cpf, matricula</strong> (cpf e matrícula opcionais)</div>
<input type="file" id="f_funcs" accept=".csv,.xlsx,.xls" style="display:none">
</div>

<div class="preview" id="preview" style="display:none"></div>

<div class="toggle-row">
<label for="f_enviar_email">Enviar e-mail de boas-vindas para cada funcionário com link e senha</label>
<label class="switch"><input type="checkbox" id="f_enviar_email" checked><span class="slider"></span></label>
</div>

<div class="box warn" style="margin-top:18px"><strong>📥 Modelo de planilha:</strong> Baixe nosso modelo para preencher corretamente: <a href="#" id="dl_modelo" style="color:#0284c7;font-weight:600">⬇ modelo_funcionarios.csv</a></div>
</div>

<!-- ETAPA 5: CONFIRMAÇÃO -->
<div class="step" id="step-5" style="display:none">
<div class="success-screen">
<div class="check">✅</div>
<h2>Empresa criada com sucesso!</h2>
<p style="color:#64748b;margin:14px 0;font-size:1rem" id="success_msg">Sua conta está pronta para usar.</p>

<div class="credentials" id="creds_box">
<strong>🔑 Seus dados de acesso:</strong><br>
<span id="creds_email"></span><br>
<span id="creds_senha"></span>
</div>

<div class="box success">📧 <strong>E-mails enviados para os funcionários</strong> com link de download do agente e senha temporária.</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:24px">
<a href="admin/login.php" class="btn btn-primary" style="justify-content:center">🚀 Entrar no Painel</a>
<a href="downloads/DOT-ON-Agent.exe" class="btn btn-secondary" style="justify-content:center">⬇ Baixar Agente</a>
</div>
</div>
</div>

<div class="btns" id="btns_nav">
<button class="btn btn-secondary" id="btn_back" onclick="goStep(currentStep-1)" style="visibility:hidden">← Voltar</button>
<button class="btn btn-primary" id="btn_next" onclick="goNext()">Próximo →</button>
</div>
</div>
</div>

<script>
let currentStep = 1;
const data = {};
const TOTAL = 5;

// Formatação de CNPJ
document.getElementById('f_cnpj').addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g,'');
    if (v.length > 14) v = v.slice(0,14);
    v = v.replace(/^(\d{2})(\d)/,'$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1/$2').replace(/(\d{4})(\d)/,'$1-$2');
    e.target.value = v;
});

// Busca CNPJ ao perder foco
document.getElementById('f_cnpj').addEventListener('blur', async e => {
    const cnpj = e.target.value.replace(/\D/g,'');
    if (cnpj.length !== 14) return;
    try {
        const r = await fetch('api/cnpj/' + cnpj);
        const j = await r.json();
        if (j.ok && j.dados) {
            if (!document.getElementById('f_razao').value) document.getElementById('f_razao').value = j.dados.razao_social || '';
            if (!document.getElementById('f_fantasia').value) document.getElementById('f_fantasia').value = j.dados.nome_fantasia || '';
            if (!document.getElementById('f_cep').value) document.getElementById('f_cep').value = j.dados.cep || '';
            if (!document.getElementById('f_endereco').value) document.getElementById('f_endereco').value = j.dados.endereco || '';
            if (!document.getElementById('f_cidade').value && j.dados.cidade) document.getElementById('f_cidade').value = j.dados.cidade + ' / ' + (j.dados.uf || '');
        }
    } catch(e){}
});

// CEP
document.getElementById('f_cep').addEventListener('input', e => {
    let v = e.target.value.replace(/\D/g,'');
    if (v.length > 8) v = v.slice(0,8);
    e.target.value = v.replace(/^(\d{5})(\d)/,'$1-$2');
});

// Carga horária
function calcCarga() {
    const ent = document.getElementById('f_entrada').value;
    const said = document.getElementById('f_saida').value;
    const ai = document.getElementById('f_almoco_ini').value;
    const af = document.getElementById('f_almoco_fim').value;
    const dias = document.querySelectorAll('.checkbox-group input:checked').length;
    if (!ent || !said) return;
    const toMin = h => h.split(':').reduce((a,b,i)=>a+parseInt(b)*(i===0?60:1),0);
    let total = toMin(said) - toMin(ent);
    if (ai && af) total -= (toMin(af) - toMin(ai));
    const semana = (total * dias) / 60;
    document.getElementById('carga_calc').textContent = semana.toFixed(0) + 'h/semana';
    document.getElementById('carga_msg').textContent = semana <= 44 ? 'Dentro do limite CLT (44h)' : '⚠ Acima do limite CLT';
}
['f_entrada','f_saida','f_almoco_ini','f_almoco_fim'].forEach(id=>document.getElementById(id).addEventListener('change',calcCarga));
document.querySelectorAll('.checkbox-group input').forEach(c=>c.addEventListener('change',calcCarga));

// Upload de funcionários
let funcs = [];
document.getElementById('f_funcs').addEventListener('change', e => {
    const file = e.target.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('arquivo', file);
    fetch('api/parse_funcionarios', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(j=>{
            if (!j.ok) { alert('Erro: ' + j.erro); return; }
            funcs = j.funcionarios;
            document.getElementById('upload_zone').classList.add('has-file');
            document.getElementById('upload_zone').querySelector('.title').textContent = '✅ ' + funcs.length + ' funcionários carregados';
            renderPreview();
        }).catch(e=>alert('Erro ao processar arquivo: ' + e));
});

function renderPreview() {
    const div = document.getElementById('preview');
    if (!funcs.length) { div.style.display='none'; return; }
    let html = '<table><thead><tr><th>Nome</th><th>E-mail</th><th>CPF</th><th>Matrícula</th></tr></thead><tbody>';
    funcs.slice(0,20).forEach(f => html += `<tr><td>${f.nome||''}</td><td>${f.email||''}</td><td>${f.cpf||''}</td><td>${f.matricula||''}</td></tr>`);
    if (funcs.length > 20) html += `<tr><td colspan=4 style='text-align:center;color:#94a3b8'>... e mais ${funcs.length-20} funcionários</td></tr>`;
    html += '</tbody></table>';
    div.innerHTML = html;
    div.style.display = 'block';
}

// Download modelo CSV
document.getElementById('dl_modelo').addEventListener('click', e => {
    e.preventDefault();
    const csv = "nome,email,cpf,matricula\nMaria Silva,maria@empresa.com.br,12345678901,001\nJoão Souza,joao@empresa.com.br,23456789012,002\n";
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'modelo_funcionarios.csv'; a.click();
});

function goStep(n) {
    if (n < 1 || n > TOTAL) return;
    document.querySelectorAll('.step').forEach(s=>s.style.display='none');
    document.getElementById('step-'+n).style.display='block';
    for (let i=1; i<=TOTAL; i++) {
        const dot = document.getElementById('snav-'+i);
        dot.classList.remove('active','done');
        if (i < n) dot.classList.add('done');
        else if (i === n) dot.classList.add('active');
    }
    currentStep = n;
    document.getElementById('btn_back').style.visibility = n > 1 && n < TOTAL ? 'visible' : 'hidden';
    document.getElementById('btn_next').textContent = n === 4 ? '✅ Criar conta' : (n === TOTAL ? '' : 'Próximo →');
    if (n === TOTAL) document.getElementById('btns_nav').style.display = 'none';
    else document.getElementById('btns_nav').style.display = 'flex';
}

function validStep(n) {
    if (n === 1) {
        const nome=document.getElementById('f_nome').value.trim();
        const email=document.getElementById('f_email').value.trim();
        const senha=document.getElementById('f_senha').value;
        if (!nome || nome.length < 3) { alert('Informe seu nome completo'); return false; }
        if (!email || !email.includes('@')) { alert('E-mail inválido'); return false; }
        if (senha.length < 8) { alert('Senha precisa ter no mínimo 8 caracteres'); return false; }
        data.gestor = {nome, email, celular: document.getElementById('f_celular').value, senha};
    }
    if (n === 2) {
        const cnpj = document.getElementById('f_cnpj').value.replace(/\D/g,'');
        const razao = document.getElementById('f_razao').value.trim();
        if (cnpj.length !== 14) { alert('CNPJ inválido (deve ter 14 dígitos)'); return false; }
        if (!razao) { alert('Informe a Razão Social'); return false; }
        data.empresa = {
            cnpj, razao_social: razao,
            nome_fantasia: document.getElementById('f_fantasia').value,
            telefone: document.getElementById('f_tel').value,
            setor: document.getElementById('f_setor').value,
            cep: document.getElementById('f_cep').value.replace(/\D/g,''),
            cidade_uf: document.getElementById('f_cidade').value,
            endereco: document.getElementById('f_endereco').value,
        };
    }
    if (n === 3) {
        const dias = Array.from(document.querySelectorAll('.checkbox-group input:checked')).reduce((a,c)=>a+parseInt(c.value),0);
        if (!dias) { alert('Selecione pelo menos 1 dia da semana'); return false; }
        data.jornada = {
            entrada: document.getElementById('f_entrada').value,
            almoco_ini: document.getElementById('f_almoco_ini').value,
            almoco_fim: document.getElementById('f_almoco_fim').value,
            saida: document.getElementById('f_saida').value,
            tolerancia: parseInt(document.getElementById('f_tolerancia').value),
            dias_semana: dias,
        };
    }
    if (n === 4) {
        if (!funcs.length) {
            if (!confirm('Você não importou funcionários. Deseja prosseguir e adicionar depois?')) return false;
        }
        data.funcionarios = funcs;
        data.enviar_email = document.getElementById('f_enviar_email').checked;
    }
    return true;
}

async function goNext() {
    if (!validStep(currentStep)) return;
    if (currentStep < 4) { goStep(currentStep+1); return; }
    // Etapa 4 → enviar tudo
    const btn = document.getElementById('btn_next');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner show"></span> Criando empresa...';
    try {
        const r = await fetch('api/signup', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)});
        const j = await r.json();
        if (!j.ok) { alert('Erro: ' + (j.erro || 'tente novamente')); btn.disabled=false; btn.textContent='✅ Criar conta'; return; }
        document.getElementById('creds_email').innerHTML = '<strong>E-mail:</strong> ' + j.gestor.email;
        document.getElementById('creds_senha').innerHTML = '<strong>Senha:</strong> ' + data.gestor.senha + ' <em>(a definida por você)</em>';
        document.getElementById('success_msg').textContent = j.mensagem || 'Sua conta foi criada. Verifique o e-mail.';
        goStep(5);
    } catch(e) {
        alert('Erro de conexão: ' + e.message);
        btn.disabled = false; btn.textContent = '✅ Criar conta';
    }
}

calcCarga();
</script>

</body>
</html>
