<?php
/**
 * DOT-ON · Webhook do Asaas
 * -------------------------
 * Recebe eventos de cobrança (PAYMENT_*) e sincroniza o status
 * da assinatura da empresa. Idempotente: reprocessar o mesmo
 * evento não causa efeito colateral.
 *
 * Configure no painel do Asaas apontando para:
 *   https://dot-on.com.br/app/api/asaas_webhook.php
 * com o "Token de autenticação" igual ao webhook_token salvo
 * em Faturamento (o Asaas o envia no header `asaas-access-token`).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/billing.php';

header('Content-Type: application/json; charset=utf-8');

// Log de depuração do webhook (app/logs é protegido por .htaccess). Ajuda a ver
// exatamente o que o Asaas envia durante os testes. Nunca loga o token.
function wh_log(string $msg): void {
    $arq = __DIR__ . '/../logs/asaas_webhook.log';
    @file_put_contents($arq, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// Só aceita POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido']);
    exit;
}

$cfg = billing_config();

// Valida token do webhook (se configurado)
$tokenEsperado = trim((string)($cfg['webhook_token'] ?? ''));
if ($tokenEsperado !== '') {
    $tokenRecebido = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';
    if (!hash_equals($tokenEsperado, (string)$tokenRecebido)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'erro' => 'Token inválido']);
        wh_log('401 token inválido (header ' . ($tokenRecebido === '' ? 'ausente' : 'diferente do salvo') . ')');
        error_log('DOT-ON asaas_webhook: token inválido');
        exit;
    }
}

$raw = file_get_contents('php://input');
$evt = json_decode($raw, true);

if (!$evt || empty($evt['event'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Payload inválido']);
    wh_log('400 payload inválido: ' . substr((string)$raw, 0, 300));
    exit;
}

$evento    = $evt['event'];                 // ex.: PAYMENT_CONFIRMED
$pagamento = $evt['payment'] ?? [];         // objeto payment do Asaas
$paymentId = $pagamento['id'] ?? null;

try {
    // Descobre a empresa: por externalReference "empresa:<id>" ou pelo customer.
    $empresaId = 0;
    $ref = $pagamento['externalReference'] ?? '';
    if (preg_match('/empresa:(\d+)/', (string)$ref, $m)) {
        $empresaId = (int)$m[1];
    }
    if (!$empresaId && !empty($pagamento['customer'])) {
        $st = db()->prepare("SELECT empresa_id FROM dot_assinaturas WHERE asaas_customer_id = ? LIMIT 1");
        $st->execute([$pagamento['customer']]);
        $empresaId = (int)($st->fetchColumn() ?: 0);
    }
    if (!$empresaId && !empty($pagamento['subscription'])) {
        $st = db()->prepare("SELECT empresa_id FROM dot_assinaturas WHERE asaas_subscription_id = ? LIMIT 1");
        $st->execute([$pagamento['subscription']]);
        $empresaId = (int)($st->fetchColumn() ?: 0);
    }

    if ($empresaId && $paymentId) {
        // Atualiza/insere a cobrança
        billing_upsert_pagamento($empresaId, $pagamento);

        // Mapeia evento → status da empresa
        switch ($evento) {
            case 'PAYMENT_CONFIRMED':
            case 'PAYMENT_RECEIVED':
            case 'PAYMENT_RECEIVED_IN_CASH':
                billing_set_status_empresa($empresaId, 'active');
                // avança o próximo vencimento se veio na payload
                if (!empty($pagamento['dueDate'])) {
                    db()->prepare("UPDATE dot_assinaturas SET proximo_vencimento = DATE_ADD(?, INTERVAL 1 MONTH) WHERE empresa_id = ?")
                        ->execute([$pagamento['dueDate'], $empresaId]);
                }
                break;

            case 'PAYMENT_OVERDUE':
                billing_set_status_empresa($empresaId, 'overdue');
                break;

            case 'PAYMENT_DELETED':
            case 'PAYMENT_REFUNDED':
            case 'PAYMENT_CHARGEBACK_REQUESTED':
                billing_set_status_empresa($empresaId, 'overdue');
                break;

            // PAYMENT_CREATED, PAYMENT_UPDATED etc.: só registra a cobrança.
        }

        auditar(null, 'asaas_webhook', 'faturamento', $empresaId, ['evento' => $evento, 'payment' => $paymentId, 'status' => $pagamento['status'] ?? null]);
        wh_log("OK evento=$evento payment=$paymentId empresa=$empresaId status=" . ($pagamento['status'] ?? '-'));
    } else {
        wh_log("IGNORADO empresa não identificada: evento=$evento payment=$paymentId ref=" . ($pagamento['externalReference'] ?? '-') . " customer=" . ($pagamento['customer'] ?? '-'));
        error_log("DOT-ON asaas_webhook: empresa não identificada (evento $evento, payment $paymentId)");
    }

    // Sempre 200 para o Asaas não reenfileirar indefinidamente.
    echo json_encode(['ok' => true, 'evento' => $evento]);

} catch (Throwable $e) {
    wh_log('ERRO ' . $e->getMessage());
    error_log('DOT-ON asaas_webhook erro: ' . $e->getMessage());
    // 200 mesmo em erro lógico evita retries infinitos; o log guarda o problema.
    http_response_code(200);
    echo json_encode(['ok' => false, 'erro' => 'processado com ressalvas']);
}
