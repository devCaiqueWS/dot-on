<?php
/**
 * DOT-ON - Helpers genéricos
 */

function json_response($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Cabeçalhos CORS já são definidos por requisição no roteador (allowlist).
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $_POST;
}

// bearer_token() removida — já definida em auth.php

function proximo_nsr(int $empresa_id): int {
    $pdo = db();
    // Upsert atômico: cria a linha da empresa se não existir e incrementa.
    // Evita o bug em que um UPDATE sem linha-semente deixava o NSR preso em 1.
    $gerencia_tx = !$pdo->inTransaction();
    if ($gerencia_tx) $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO dot_nsr_sequencia (empresa_id, ultimo_nsr) VALUES (?, 1)
                       ON DUPLICATE KEY UPDATE ultimo_nsr = ultimo_nsr + 1")
            ->execute([$empresa_id]);
        $stmt = $pdo->prepare("SELECT ultimo_nsr FROM dot_nsr_sequencia WHERE empresa_id = ?");
        $stmt->execute([$empresa_id]);
        $proximo = (int)$stmt->fetchColumn();
        if ($gerencia_tx) $pdo->commit();
        return $proximo;
    } catch (Throwable $e) {
        if ($gerencia_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function hash_batida(int $nsr, int $usuario_id, string $tipo, string $momento, ?string $hash_anterior): string {
    return hash('sha256', "$nsr|$usuario_id|$tipo|$momento|" . ($hash_anterior ?? ''));
}

function fmt_minutos(int $min): string {
    $sinal = $min < 0 ? '-' : '';
    $min = abs($min);
    return sprintf("%s%02d:%02d", $sinal, intdiv($min, 60), $min % 60);
}

function get_config(int $empresa_id, string $chave, $default = null) {
    static $cache = [];
    if (!isset($cache[$empresa_id])) {
        $cache[$empresa_id] = [];
        $stmt = db()->prepare("SELECT chave, valor FROM dot_config WHERE empresa_id = ?");
        $stmt->execute([$empresa_id]);
        foreach ($stmt->fetchAll() as $r) $cache[$empresa_id][$r['chave']] = $r['valor'];
    }
    return $cache[$empresa_id][$chave] ?? $default;
}
