<?php
/**
 * DOT-ON · Cliente HTTP do gateway Asaas (https://asaas.com)
 * -----------------------------------------------------------
 * Wrapper fino sobre a API v3. Não conhece regra de negócio —
 * só fala HTTP. A lógica de assinatura fica em billing.php.
 *
 * Autenticação: header `access_token: <chave>`.
 * Sandbox:    https://sandbox.asaas.com/api/v3
 * Produção:   https://api.asaas.com/v3
 *
 * Docs: https://docs.asaas.com/reference
 */

class AsaasException extends RuntimeException {
    public array $body;
    public function __construct(string $message, int $code = 0, array $body = []) {
        parent::__construct($message, $code);
        $this->body = $body;
    }
}

class AsaasClient {
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $apiKey, string $ambiente = 'sandbox') {
        $this->apiKey  = trim($apiKey);
        $this->baseUrl = $ambiente === 'production'
            ? 'https://api.asaas.com/v3'
            : 'https://sandbox.asaas.com/api/v3';
    }

    /** Retorna o cliente já configurado a partir do dot_billing_config, ou null. */
    public static function fromConfig(): ?AsaasClient {
        require_once __DIR__ . '/billing.php';
        $cfg = billing_config();
        if (empty($cfg['api_key'])) return null;
        return new self($cfg['api_key'], $cfg['ambiente'] ?? 'sandbox');
    }

    // -------- Clientes (customers) --------

    /**
     * Cria (ou reaproveita) um customer no Asaas.
     * @param array $dados name, cpfCnpj, email, mobilePhone, ...
     */
    public function criarCliente(array $dados): array {
        return $this->request('POST', '/customers', $dados);
    }

    public function buscarCliente(string $customerId): array {
        return $this->request('GET', "/customers/{$customerId}");
    }

    /**
     * Valida a credencial sem criar nada (GET leve). Lança AsaasException se a
     * chave for inválida/ambiente errado; retorna true se responder 2xx.
     */
    public function validarCredencial(): bool {
        $this->request('GET', '/customers?limit=1');
        return true;
    }

    // -------- Assinaturas (subscriptions) --------

    /**
     * Cria assinatura recorrente.
     * @param array $dados customer, billingType, value, nextDueDate, cycle, description
     */
    public function criarAssinatura(array $dados): array {
        return $this->request('POST', '/subscriptions', $dados);
    }

    public function buscarAssinatura(string $subId): array {
        return $this->request('GET', "/subscriptions/{$subId}");
    }

    /** Cobranças geradas por uma assinatura. */
    public function pagamentosDaAssinatura(string $subId): array {
        return $this->request('GET', "/subscriptions/{$subId}/payments");
    }

    public function cancelarAssinatura(string $subId): array {
        return $this->request('DELETE', "/subscriptions/{$subId}");
    }

    // -------- Cobranças (payments) --------

    public function buscarPagamento(string $paymentId): array {
        return $this->request('GET', "/payments/{$paymentId}");
    }

    // -------- HTTP --------

    private function request(string $method, string $path, ?array $payload = null): array {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'access_token: ' . $this->apiKey,
            'User-Agent: DOT-ON/1.0 (+https://dot-on.com.br)',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($payload !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new AsaasException("Falha de conexão com o Asaas: $err", 0);
        }

        $data = json_decode($raw, true) ?: [];

        if ($status < 200 || $status >= 300) {
            $msg = $data['errors'][0]['description']
                ?? ($data['message'] ?? "Erro HTTP $status no Asaas");
            throw new AsaasException($msg, $status, $data);
        }

        return $data;
    }
}
