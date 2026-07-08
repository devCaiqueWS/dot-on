<?php
$titulo = 'Assinatura'; $pagina = 'assinatura';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/billing.php';

// Só admin/rh mexem em cobrança
if (!in_array($user['perfil'] ?? '', ['admin','rh'], true)) {
    echo '<div class="panel"><p>Apenas administradores podem gerenciar a assinatura.</p></div></main></body></html>';
    exit;
}

$empresa_id = (int)$user['empresa_id'];
$cfg = billing_config();
$msg = ''; $msg_tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'assinar') {
        if (!billing_configurado()) {
            $msg = 'O pagamento online ainda não está disponível. Fale com o suporte para ativar sua assinatura.';
            $msg_tipo = 'error';
        } else {
            try {
                $forma = in_array($_POST['forma'] ?? '', ['UNDEFINED','PIX','BOLETO','CREDIT_CARD']) ? $_POST['forma'] : 'UNDEFINED';
                billing_assinar_empresa($empresa_id, $forma);
                auditar($user['id'], 'assinatura_criada', 'faturamento', $empresa_id, ['forma' => $forma]);
                $msg = 'Assinatura criada! Use o link de pagamento abaixo para concluir.';
                $msg_tipo = 'success';
            } catch (Throwable $e) {
                error_log('DOT-ON assinar empresa ' . $empresa_id . ': ' . $e->getMessage());
                $msg = 'Não foi possível criar a assinatura: ' . $e->getMessage();
                $msg_tipo = 'error';
            }
        }
    }

    if ($acao === 'cancelar') {
        billing_cancelar_assinatura($empresa_id);
        auditar($user['id'], 'assinatura_cancelada', 'faturamento', $empresa_id);
        $msg = 'Assinatura cancelada. Você mantém o acesso até o fim do período já pago.';
        $msg_tipo = 'success';
    }
}

$assinatura = billing_assinatura($empresa_id);
$pagamentos = billing_pagamentos($empresa_id);
$emp = tenant_empresa();
$status = $emp['assinatura_status'] ?? 'trial';
$valor = billing_valor_plano();
$brl = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');

// Cobrança em aberto mais recente (para exibir link de pagamento)
$em_aberto = null;
foreach ($pagamentos as $p) {
    if (in_array($p['status'], ['PENDING','OVERDUE','AWAITING_RISK_ANALYSIS'])) { $em_aberto = $p; break; }
}

$dias_trial = null;
if ($status === 'trial' && !empty($emp['trial_expira'])) {
    $dias_trial = max(0, ceil((strtotime($emp['trial_expira']) - time()) / 86400));
}

$labels = [
    'trial'    => ['Período de teste', '#f59e0b'],
    'active'   => ['Assinatura ativa', '#10b981'],
    'overdue'  => ['Pagamento em atraso', '#ef4444'],
    'pending'  => ['Aguardando pagamento', '#f59e0b'],
    'canceled' => ['Cancelada', '#64748b'],
];
[$statusLabel, $statusCor] = $labels[$status] ?? ['—', '#64748b'];
?>
<?php if ($msg): ?><div class="alert <?= $msg_tipo==='success'?'success':'error' ?>" style="padding:12px 16px;border-radius:8px;margin-bottom:16px;background:<?= $msg_tipo==='success'?'#dcfce7':'#fee2e2' ?>;color:<?= $msg_tipo==='success'?'#166534':'#991b1b' ?>;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="panel">
    <h2><?= icon('config', 18) ?>Sua assinatura</h2>
    <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:center;margin-top:8px;">
        <div>
            <div style="font-size:13px;color:#6b7280;">Status</div>
            <div style="font-size:20px;font-weight:700;color:<?= $statusCor ?>;">● <?= $statusLabel ?></div>
            <?php if ($dias_trial !== null): ?>
                <div style="font-size:13px;color:#6b7280;margin-top:4px;"><?= $dias_trial ?> dia(s) restante(s) de teste grátis</div>
            <?php endif; ?>
        </div>
        <?php if ($assinatura && $assinatura['proximo_vencimento']): ?>
        <div>
            <div style="font-size:13px;color:#6b7280;">Próximo vencimento</div>
            <div style="font-size:20px;font-weight:700;"><?= date('d/m/Y', strtotime($assinatura['proximo_vencimento'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($em_aberto): ?>
<div class="panel" style="border-left:4px solid #f59e0b;">
    <h2><?= icon('config', 18) ?>Fatura em aberto — <?= $brl($em_aberto['valor']) ?></h2>
    <p>Vencimento: <strong><?= $em_aberto['vencimento'] ? date('d/m/Y', strtotime($em_aberto['vencimento'])) : '—' ?></strong></p>
    <p style="margin-top:12px;">
        <?php if ($em_aberto['url_fatura']): ?>
            <a href="<?= htmlspecialchars($em_aberto['url_fatura']) ?>" target="_blank" rel="noopener" class="btn btn-primary">💳 Pagar agora (Pix / Boleto / Cartão)</a>
        <?php endif; ?>
        <?php if ($em_aberto['url_boleto']): ?>
            <a href="<?= htmlspecialchars($em_aberto['url_boleto']) ?>" target="_blank" rel="noopener" class="btn btn-outline">📄 Boleto</a>
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<div class="panel">
    <h2><?= icon('config', 18) ?>Plano <?= htmlspecialchars($cfg['plano_nome']) ?></h2>
    <div style="display:flex;align-items:baseline;gap:6px;margin:8px 0 16px;">
        <span style="font-size:38px;font-weight:800;"><?= $brl($valor) ?></span>
        <span style="color:#6b7280;">/mês · valor único, sem surpresa</span>
    </div>
    <ul style="line-height:2;color:#374151;margin-left:20px;">
        <li>✓ Funcionários ilimitados</li>
        <li>✓ Registro de ponto pelo Agente Windows + navegador (com geolocalização)</li>
        <li>✓ Comprovante de Registro de Ponto (CRP) por e-mail em toda batida</li>
        <li>✓ Banco de horas, horas extras, faltas e abonos</li>
        <li>✓ Espelho de ponto, AFD e AEJ para a fiscalização</li>
        <li>✓ Certificado ICP-Brasil (A1) e assinatura dos arquivos</li>
        <li>✓ Painel do gestor completo + auditoria fiscal</li>
    </ul>

    <?php if ($status === 'active'): ?>
        <div class="alert" style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;">Sua assinatura está ativa. Obrigado! 💚</div>
        <form method="post" onsubmit="return confirm('Cancelar a assinatura? Você mantém o acesso até o fim do período pago.');" style="margin-top:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="cancelar">
            <button class="btn btn-outline" type="submit">Cancelar assinatura</button>
        </form>
    <?php else: ?>
        <form method="post" style="margin-top:8px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="assinar">
            <label>Forma de pagamento:
                <select name="forma">
                    <option value="UNDEFINED">Deixar o cliente escolher (Pix, Boleto ou Cartão)</option>
                    <option value="PIX">Pix</option>
                    <option value="BOLETO">Boleto</option>
                    <option value="CREDIT_CARD">Cartão de crédito</option>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">🚀 Assinar agora — <?= $brl($valor) ?>/mês</button>
        </form>
        <?php if (!billing_configurado()): ?>
            <p class="muted" style="margin-top:8px;color:#6b7280;font-size:13px;">O pagamento online está sendo ativado. Se precisar, fale com o suporte.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="panel">
    <h2><?= icon('relatorios', 18) ?>Histórico de pagamentos</h2>
    <table class="tbl">
        <thead><tr><th>Vencimento</th><th>Valor</th><th>Forma</th><th>Status</th><th>Pago em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pagamentos as $p):
            $pago = in_array($p['status'], ['CONFIRMED','RECEIVED','RECEIVED_IN_CASH']); ?>
            <tr>
                <td><?= $p['vencimento'] ? date('d/m/Y', strtotime($p['vencimento'])) : '—' ?></td>
                <td><?= $brl($p['valor']) ?></td>
                <td><?= htmlspecialchars($p['forma'] ?: '—') ?></td>
                <td style="color:<?= $pago ? '#10b981' : ($p['status']==='OVERDUE' ? '#ef4444' : '#6b7280') ?>;font-weight:600;"><?= htmlspecialchars($p['status']) ?></td>
                <td><?= $p['pago_em'] ? date('d/m/Y', strtotime($p['pago_em'])) : '—' ?></td>
                <td><?php if ($p['url_fatura'] && !$pago): ?><a href="<?= htmlspecialchars($p['url_fatura']) ?>" target="_blank" rel="noopener">pagar</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pagamentos): ?>
            <tr><td colspan="6" style="text-align:center;padding:24px;color:#9ca3af;">Nenhuma cobrança gerada ainda.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</main></body></html>
