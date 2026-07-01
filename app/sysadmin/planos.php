<?php
$pagina = 'planos'; $titulo = 'Planos & Preços';
require_once __DIR__ . '/_layout.php';
?>

<h1>💳 Planos & Preços (configuração)</h1>

<div class="alert info">
    💡 Esta página é a base para futura cobrança. Hoje todas as empresas começam no plano Free com 30 dias de trial.
    A integração com gateway de pagamento (Stripe / Mercado Pago) será a Fase 6.
</div>

<div class="cards">
    <div class="card" style="border-left:4px solid #64748b;">
        <div class="label">🆓 FREE</div>
        <div class="value">R$ 0</div>
        <div class="sub">Até 10 funcionários · 30 dias de trial</div>
        <hr style="border:none; border-top:1px solid #334155; margin:14px 0;">
        <div style="color:#94a3b8; font-size:13px; line-height:1.7;">
            ✓ Até 10 funcionários<br>
            ✓ Painel gestor completo<br>
            ✓ Agente Windows<br>
            ✓ AFD básico<br>
            ✗ Certificado ICP-Brasil<br>
            ✗ Suporte prioritário
        </div>
    </div>

    <div class="card" style="border-left:4px solid #3b82f6;">
        <div class="label">🚀 BASIC</div>
        <div class="value">R$ 99<span style="font-size:14px; color:#94a3b8;">/mês</span></div>
        <div class="sub">Até 30 funcionários</div>
        <hr style="border:none; border-top:1px solid #334155; margin:14px 0;">
        <div style="color:#94a3b8; font-size:13px; line-height:1.7;">
            ✓ Tudo do Free<br>
            ✓ Até 30 funcionários<br>
            ✓ AFD/AEJ completos<br>
            ✓ Histórico ilimitado<br>
            ✓ Exportação PDF<br>
            ✗ Certificado digital
        </div>
    </div>

    <div class="card" style="border-left:4px solid #8b5cf6;">
        <div class="label">⭐ PRO</div>
        <div class="value">R$ 249<span style="font-size:14px; color:#94a3b8;">/mês</span></div>
        <div class="sub">Até 100 funcionários</div>
        <hr style="border:none; border-top:1px solid #334155; margin:14px 0;">
        <div style="color:#94a3b8; font-size:13px; line-height:1.7;">
            ✓ Tudo do Basic<br>
            ✓ Até 100 funcionários<br>
            ✓ Certificado ICP-Brasil<br>
            ✓ REP-P homologado<br>
            ✓ Hash blockchain<br>
            ✓ Suporte prioritário
        </div>
    </div>

    <div class="card" style="border-left:4px solid #fbbf24;">
        <div class="label">🏢 ENTERPRISE</div>
        <div class="value">Sob consulta</div>
        <div class="sub">+100 funcionários · multi-filial</div>
        <hr style="border:none; border-top:1px solid #334155; margin:14px 0;">
        <div style="color:#94a3b8; font-size:13px; line-height:1.7;">
            ✓ Tudo do Pro<br>
            ✓ Funcionários ilimitados<br>
            ✓ Multi-filial / multi-empresa<br>
            ✓ API dedicada<br>
            ✓ White-label<br>
            ✓ SLA + suporte dedicado
        </div>
    </div>
</div>

<div class="panel">
    <h2 style="margin-top:0;">📝 Próximos passos (Fase 6)</h2>
    <ul style="color:#cbd5e1; margin-left:24px; line-height:2;">
        <li>Integrar gateway de pagamento (Stripe Brasil ou Mercado Pago)</li>
        <li>Tabela <code>dot_assinaturas</code> com plano, valor, dia_vencimento, status</li>
        <li>Webhook de confirmação de pagamento → ativa/desativa empresa</li>
        <li>Régua de cobrança automatizada por e-mail</li>
        <li>Painel financeiro com MRR, churn, LTV</li>
        <li>Geração de nota fiscal (NFS-e) integrada com prefeitura</li>
    </ul>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
