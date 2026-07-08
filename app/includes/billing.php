<?php
/**
 * DOT-ON · Camada de faturamento SaaS (regra de negócio)
 * ------------------------------------------------------
 * Orquestra a integração com o Asaas: assinatura de uma empresa,
 * sincronização de status a partir dos webhooks e liberação de acesso.
 *
 * A chave da API e o valor do plano ficam em dot_billing_config.
 * O schema é criado sob demanda por billing_ensure_schema().
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/asaas.php';

/* ============================================================
 * Schema (migração preguiçosa e idempotente)
 * ============================================================ */

/**
 * Garante que as tabelas/colunas de faturamento existam.
 * Idempotente — seguro chamar em toda página do painel.
 */
function billing_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS dot_billing_config (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ambiente ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
        api_key TEXT NULL,
        webhook_token VARCHAR(80) NULL,
        valor_plano DECIMAL(10,2) NOT NULL DEFAULT 49.90,
        plano_nome VARCHAR(60) NOT NULL DEFAULT 'DOT-ON Profissional',
        ciclo VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',
        dias_trial INT NOT NULL DEFAULT 30,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dot_assinaturas (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT UNSIGNED NOT NULL,
        asaas_customer_id VARCHAR(64) NULL,
        asaas_subscription_id VARCHAR(64) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        valor DECIMAL(10,2) NOT NULL DEFAULT 0,
        ciclo VARCHAR(20) NOT NULL DEFAULT 'MONTHLY',
        forma_pagamento VARCHAR(20) NOT NULL DEFAULT 'UNDEFINED',
        proximo_vencimento DATE NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_empresa (empresa_id),
        KEY idx_sub (asaas_subscription_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dot_pagamentos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT UNSIGNED NOT NULL,
        assinatura_id INT UNSIGNED NULL,
        asaas_payment_id VARCHAR(64) NOT NULL,
        valor DECIMAL(10,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
        forma VARCHAR(20) NULL,
        vencimento DATE NULL,
        pago_em DATETIME NULL,
        url_fatura TEXT NULL,
        url_boleto TEXT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_payment (asaas_payment_id),
        KEY idx_empresa (empresa_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Coluna denormalizada em dot_empresas (ADD COLUMN não é IF NOT EXISTS no MySQL).
    try {
        $col = $pdo->query("SHOW COLUMNS FROM dot_empresas LIKE 'assinatura_status'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE dot_empresas ADD COLUMN assinatura_status VARCHAR(20) NOT NULL DEFAULT 'trial'");
        }
    } catch (Throwable $e) { /* silencioso */ }

    // Semente da config
    try {
        $tem = $pdo->query("SELECT 1 FROM dot_billing_config LIMIT 1")->fetch();
        if (!$tem) {
            $pdo->exec("INSERT INTO dot_billing_config (ambiente, valor_plano, plano_nome, ciclo, dias_trial, ativo)
                        VALUES ('sandbox', 49.90, 'DOT-ON Profissional', 'MONTHLY', 30, 1)");
        }
    } catch (Throwable $e) { /* silencioso */ }

    $done = true;
}

/* ============================================================
 * Configuração
 * ============================================================ */

/** Config global de faturamento (linha única). Cacheado no request. */
function billing_config(bool $forcarReleitura = false): array {
    static $cache = null;
    if ($cache !== null && !$forcarReleitura) return $cache;
    billing_ensure_schema();
    $row = db()->query("SELECT * FROM dot_billing_config ORDER BY id LIMIT 1")->fetch();
    $cache = $row ?: [
        'ambiente' => 'sandbox', 'api_key' => '', 'webhook_token' => '',
        'valor_plano' => 49.90, 'plano_nome' => 'DOT-ON Profissional',
        'ciclo' => 'MONTHLY', 'dias_trial' => 30, 'ativo' => 1,
    ];
    return $cache;
}

/** Grava a config (upsert na linha única). Limpa o cache. */
function billing_salvar_config(array $campos): void {
    billing_ensure_schema();
    $pdo = db();
    $row = $pdo->query("SELECT id FROM dot_billing_config ORDER BY id LIMIT 1")->fetch();

    $cols = ['ambiente','api_key','webhook_token','valor_plano','plano_nome','ciclo','dias_trial','ativo'];
    $set  = []; $vals = [];
    foreach ($cols as $c) {
        if (array_key_exists($c, $campos)) { $set[] = "$c = ?"; $vals[] = $campos[$c]; }
    }
    if (!$set) return;

    if ($row) {
        $vals[] = $row['id'];
        $pdo->prepare("UPDATE dot_billing_config SET " . implode(',', $set) . " WHERE id = ?")->execute($vals);
    } else {
        $keys = array_keys(array_intersect_key($campos, array_flip($cols)));
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $pdo->prepare("INSERT INTO dot_billing_config (" . implode(',', $keys) . ") VALUES ($ph)")
            ->execute(array_values(array_intersect_key($campos, array_flip($cols))));
    }
    // invalida o cache estático de billing_config()
    billing_config(true);
}

function billing_valor_plano(): float {
    return (float)(billing_config()['valor_plano'] ?? 49.90);
}

function billing_configurado(): bool {
    $c = billing_config();
    return !empty($c['api_key']);
}

/* ============================================================
 * Assinatura de uma empresa
 * ============================================================ */

/** Assinatura da empresa (ou null se ainda não assinou). */
function billing_assinatura(int $empresaId): ?array {
    billing_ensure_schema();
    $st = db()->prepare("SELECT * FROM dot_assinaturas WHERE empresa_id = ? LIMIT 1");
    $st->execute([$empresaId]);
    return $st->fetch() ?: null;
}

/** Últimas cobranças da empresa. */
function billing_pagamentos(int $empresaId, int $limite = 12): array {
    billing_ensure_schema();
    $st = db()->prepare("SELECT * FROM dot_pagamentos WHERE empresa_id = ? ORDER BY vencimento DESC, id DESC LIMIT ?");
    $st->bindValue(1, $empresaId, PDO::PARAM_INT);
    $st->bindValue(2, $limite, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Assina a empresa: cria (ou reaproveita) customer + subscription no Asaas
 * e persiste localmente. Idempotente por empresa.
 *
 * @param string $forma UNDEFINED (cliente escolhe) | BOLETO | PIX | CREDIT_CARD
 * @return array a assinatura persistida
 * @throws AsaasException
 */
function billing_assinar_empresa(int $empresaId, string $forma = 'UNDEFINED'): array {
    billing_ensure_schema();
    $pdo = db();
    $cfg = billing_config();

    $cliente = AsaasClient::fromConfig();
    if (!$cliente) throw new AsaasException('Gateway de pagamento não configurado. Fale com o suporte.', 0);

    $emp = $pdo->prepare("SELECT * FROM dot_empresas WHERE id = ?");
    $emp->execute([$empresaId]);
    $emp = $emp->fetch();
    if (!$emp) throw new AsaasException('Empresa não encontrada.', 0);

    $assinatura = billing_assinatura($empresaId);

    // Já existe assinatura no Asaas (ativa ou aguardando pagamento)? Não recria —
    // só ressincroniza as cobranças e devolve. Evita subscription duplicada no retry.
    if ($assinatura && $assinatura['asaas_subscription_id'] && $assinatura['status'] !== 'canceled') {
        try { billing_sincronizar_pagamentos($empresaId, $assinatura['asaas_subscription_id']); } catch (Throwable $e) {}
        return billing_assinatura($empresaId);
    }

    // 1) Customer no Asaas (reaproveita se já existir na assinatura local)
    $customerId = $assinatura['asaas_customer_id'] ?? null;
    if (!$customerId) {
        $c = $cliente->criarCliente([
            'name'        => $emp['razao_social'] ?: $emp['nome_fantasia'],
            'cpfCnpj'     => preg_replace('/\D/', '', (string)$emp['cnpj']),
            'email'       => $emp['email_contato'] ?: null,
            'mobilePhone' => preg_replace('/\D/', '', (string)($emp['telefone'] ?? '')) ?: null,
            'externalReference' => 'empresa:' . $empresaId,
        ]);
        $customerId = $c['id'] ?? null;
        if (!$customerId) throw new AsaasException('Asaas não retornou o ID do cliente.', 0);
    }

    // 2) Subscription mensal com valor fixo
    $valor      = billing_valor_plano();
    $vencimento = date('Y-m-d', strtotime('+3 days')); // primeiro vencimento
    $sub = $cliente->criarAssinatura([
        'customer'    => $customerId,
        'billingType' => $forma,
        'value'       => $valor,
        'nextDueDate' => $vencimento,
        'cycle'       => $cfg['ciclo'] ?? 'MONTHLY',
        'description' => ($cfg['plano_nome'] ?? 'DOT-ON') . ' — mensalidade',
        'externalReference' => 'empresa:' . $empresaId,
    ]);
    $subId = $sub['id'] ?? null;
    if (!$subId) throw new AsaasException('Asaas não retornou o ID da assinatura.', 0);

    // 3) Persiste (upsert)
    $pdo->prepare("INSERT INTO dot_assinaturas
            (empresa_id, asaas_customer_id, asaas_subscription_id, status, valor, ciclo, forma_pagamento, proximo_vencimento)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            asaas_customer_id = VALUES(asaas_customer_id),
            asaas_subscription_id = VALUES(asaas_subscription_id),
            status = VALUES(status),
            valor = VALUES(valor),
            forma_pagamento = VALUES(forma_pagamento),
            proximo_vencimento = VALUES(proximo_vencimento)")
        ->execute([$empresaId, $customerId, $subId, 'pending', $valor, $cfg['ciclo'] ?? 'MONTHLY', $forma, $vencimento]);

    // 4) Puxa a(s) cobrança(s) já geradas pela assinatura
    try { billing_sincronizar_pagamentos($empresaId, $subId, $customerId); } catch (Throwable $e) {}

    return billing_assinatura($empresaId);
}

/** Cancela a assinatura no Asaas e localmente. */
function billing_cancelar_assinatura(int $empresaId): void {
    $a = billing_assinatura($empresaId);
    if (!$a || !$a['asaas_subscription_id']) return;
    $cli = AsaasClient::fromConfig();
    if ($cli) { try { $cli->cancelarAssinatura($a['asaas_subscription_id']); } catch (Throwable $e) {} }
    db()->prepare("UPDATE dot_assinaturas SET status='canceled' WHERE empresa_id = ?")->execute([$empresaId]);
    billing_set_status_empresa($empresaId, 'canceled');
}

/** Busca no Asaas as cobranças da assinatura e faz upsert local. */
function billing_sincronizar_pagamentos(int $empresaId, string $subId, ?string $customerId = null): void {
    $cli = AsaasClient::fromConfig();
    if (!$cli) return;
    $resp = $cli->pagamentosDaAssinatura($subId);
    foreach (($resp['data'] ?? []) as $p) {
        billing_upsert_pagamento($empresaId, $p);
    }
}

/** Upsert de uma cobrança (payload do Asaas) na dot_pagamentos. */
function billing_upsert_pagamento(int $empresaId, array $p): void {
    billing_ensure_schema();
    $pid = $p['id'] ?? null;
    if (!$pid) return;

    $assinatura = billing_assinatura($empresaId);
    $pagoEm = null;
    if (!empty($p['paymentDate']))      $pagoEm = $p['paymentDate'];
    elseif (!empty($p['confirmedDate'])) $pagoEm = $p['confirmedDate'];

    db()->prepare("INSERT INTO dot_pagamentos
            (empresa_id, assinatura_id, asaas_payment_id, valor, status, forma, vencimento, pago_em, url_fatura, url_boleto)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            valor = VALUES(valor),
            status = VALUES(status),
            forma = VALUES(forma),
            vencimento = VALUES(vencimento),
            pago_em = VALUES(pago_em),
            url_fatura = VALUES(url_fatura),
            url_boleto = VALUES(url_boleto)")
        ->execute([
            $empresaId,
            $assinatura['id'] ?? null,
            $pid,
            $p['value'] ?? 0,
            $p['status'] ?? 'PENDING',
            $p['billingType'] ?? null,
            $p['dueDate'] ?? null,
            $pagoEm,
            $p['invoiceUrl'] ?? null,
            $p['bankSlipUrl'] ?? null,
        ]);
}

/* ============================================================
 * Status / gating de acesso
 * ============================================================ */

/** Atualiza o status denormalizado na empresa e na assinatura. */
function billing_set_status_empresa(int $empresaId, string $status): void {
    billing_ensure_schema();
    db()->prepare("UPDATE dot_empresas SET assinatura_status = ? WHERE id = ?")->execute([$status, $empresaId]);
    db()->prepare("UPDATE dot_assinaturas SET status = ? WHERE empresa_id = ?")->execute([$status, $empresaId]);
}

/**
 * A empresa tem acesso liberado agora?
 * - trial válido → true
 * - assinatura active → true
 * - overdue além da carência → false
 */
function billing_acesso_liberado(array $empresa): bool {
    $status = $empresa['assinatura_status'] ?? 'trial';
    if ($status === 'active') return true;
    if ($status === 'canceled') return false;

    if ($status === 'overdue') {
        // carência de 5 dias após vencer antes de bloquear
        $a = billing_assinatura((int)$empresa['id']);
        if ($a && $a['proximo_vencimento']) {
            return strtotime($a['proximo_vencimento']) > strtotime('-5 days');
        }
        return false;
    }

    // trial
    if (!empty($empresa['trial_expira'])) {
        return strtotime($empresa['trial_expira']) >= strtotime('today');
    }
    return true; // sem data de trial definida → não bloqueia
}
