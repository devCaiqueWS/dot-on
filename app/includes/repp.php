<?php
/**
 * DOT-ON · Biblioteca REP-P (Portaria MTP 671/2021)
 * ==================================================
 * Conjunto de funções para:
 *  - Encadeamento criptográfico de NSRs (blockchain de batidas)
 *  - Geração e verificação de hash SHA-256
 *  - Validação de integridade da cadeia
 *  - Carimbo de tempo (servidor + TSA freetsa.org)
 *  - Assinatura digital ICP-Brasil A1 (.pfx via openssl_pkcs7_sign)
 *  - Cifragem AES-256-GCM para a senha do certificado
 */

require_once __DIR__ . '/db.php';

// ===================================================================
// HASH ENCADEADO (BLOCKCHAIN DE BATIDAS)
// ===================================================================

/**
 * Calcula o hash canônico de uma batida (entrada para o encadeamento).
 * Inclui dados imutáveis do registro + hash anterior da cadeia.
 */
function repp_hash_batida(array $b, ?string $hash_anterior): string {
    $payload = implode('|', [
        'NSR=' . $b['nsr'],
        'EMP=' . ($b['empresa_id'] ?? ''),
        'USR=' . $b['usuario_id'],
        'CPF=' . preg_replace('/\D/','', $b['cpf_snapshot'] ?? ''),
        'PIS=' . preg_replace('/\D/','', $b['pis_snapshot'] ?? ''),
        'TIP=' . $b['tipo'],
        'MOM=' . $b['momento'],
        'PRV=' . ($hash_anterior ?? str_repeat('0', 64)),
    ]);
    return hash('sha256', $payload);
}

/**
 * Recupera o último hash da cadeia da empresa.
 */
function repp_ultimo_hash(int $empresa_id): ?string {
    $stmt = db()->prepare("SELECT hash_registro FROM dot_batidas
        WHERE empresa_id = ? ORDER BY nsr DESC LIMIT 1");
    $stmt->execute([$empresa_id]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * Valida integralmente a cadeia de batidas da empresa.
 * Retorna ['ok'=>bool, 'verificadas'=>int, 'divergencias'=>[]]
 */
function repp_validar_cadeia(int $empresa_id, ?int $nsr_inicial = null, ?int $nsr_final = null): array {
    $sql = "SELECT * FROM dot_batidas WHERE empresa_id = ?";
    $params = [$empresa_id];
    if ($nsr_inicial !== null) { $sql .= " AND nsr >= ?"; $params[] = $nsr_inicial; }
    if ($nsr_final !== null)   { $sql .= " AND nsr <= ?"; $params[] = $nsr_final;   }
    $sql .= " ORDER BY nsr ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $batidas = $stmt->fetchAll();

    $divergencias = [];
    $hash_anterior_esperado = null;

    foreach ($batidas as $b) {
        // 1. hash_anterior deve bater com o esperado
        if ($hash_anterior_esperado !== null && $b['hash_anterior'] !== $hash_anterior_esperado) {
            $divergencias[] = [
                'nsr' => $b['nsr'],
                'problema' => 'hash_anterior diverge',
                'esperado' => $hash_anterior_esperado,
                'encontrado' => $b['hash_anterior'],
            ];
        }
        // 2. hash_registro deve ser recalculável
        $recalculado = repp_hash_batida($b, $b['hash_anterior']);
        if ($recalculado !== $b['hash_registro']) {
            $divergencias[] = [
                'nsr' => $b['nsr'],
                'problema' => 'hash_registro adulterado',
                'esperado' => $recalculado,
                'encontrado' => $b['hash_registro'],
            ];
        }
        $hash_anterior_esperado = $b['hash_registro'];
    }

    return [
        'ok' => empty($divergencias),
        'verificadas' => count($batidas),
        'divergencias' => $divergencias,
        'ultimo_hash' => $hash_anterior_esperado,
    ];
}

// ===================================================================
// CIFRAGEM / DECIFRAGEM AES-256-GCM
// (usado para senha do certificado A1)
// ===================================================================

function repp_cifrar(string $plain, string $chave_mestra): string {
    $key = hash('sha256', $chave_mestra, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function repp_decifrar(string $blob, string $chave_mestra): ?string {
    $key = hash('sha256', $chave_mestra, true);
    $raw = base64_decode($blob);
    if (strlen($raw) < 28) return null;
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $r = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $r === false ? null : $r;
}

function repp_chave_mestra(): string {
    $cfg = require __DIR__ . '/../config/app.php';
    return $cfg['jwt_secret'];
}

// ===================================================================
// CERTIFICADO ICP-BRASIL A1 (.pfx)
// ===================================================================

/**
 * Lê um .pfx e retorna [cert, pkey, extracerts] ou null.
 */
function repp_ler_pfx(string $pfx_path, string $senha): ?array {
    if (!file_exists($pfx_path)) return null;
    $pkcs12 = file_get_contents($pfx_path);
    $certs = [];
    if (!openssl_pkcs12_read($pkcs12, $certs, $senha)) return null;
    return $certs;
}

/**
 * Extrai informações do certificado (subject, validade, CNPJ se presente).
 */
function repp_info_certificado(string $cert_pem): array {
    $info = openssl_x509_parse($cert_pem);
    $subject = $info['subject'] ?? [];
    $cnpj = null;
    // CNPJ no CN do certificado ICP-Brasil (formato: NOME:CNPJ)
    if (!empty($subject['CN']) && preg_match('/:(\d{14})/', $subject['CN'], $m)) {
        $cnpj = $m[1];
    }
    return [
        'subject_cn' => $subject['CN'] ?? '',
        'subject_o'  => $subject['O']  ?? '',
        'issuer_cn'  => $info['issuer']['CN'] ?? '',
        'serial'     => $info['serialNumberHex'] ?? '',
        'validade_de'  => $info['validFrom_time_t']  ? date('Y-m-d', $info['validFrom_time_t'])  : null,
        'validade_ate' => $info['validTo_time_t']    ? date('Y-m-d', $info['validTo_time_t'])    : null,
        'cnpj'       => $cnpj,
    ];
}

/**
 * Assina dados (string ou arquivo) com PKCS#7 detached usando o certificado da empresa.
 * Retorna string base64 da assinatura, ou null em falha.
 */
function repp_assinar_pkcs7(int $empresa_id, string $dados): ?string {
    $stmt = db()->prepare("SELECT cert_arquivo, cert_senha_cifrada FROM dot_empresas WHERE id=?");
    $stmt->execute([$empresa_id]);
    $row = $stmt->fetch();
    if (!$row || !$row['cert_arquivo']) return null;

    $cert_path = __DIR__ . '/../config/' . $row['cert_arquivo'];
    if (!file_exists($cert_path)) return null;

    $senha = repp_decifrar($row['cert_senha_cifrada'], repp_chave_mestra());
    if ($senha === null) return null;

    $certs = repp_ler_pfx($cert_path, $senha);
    if (!$certs) return null;

    $in  = tempnam(sys_get_temp_dir(), 'rpp_in_');
    $out = tempnam(sys_get_temp_dir(), 'rpp_out_');
    file_put_contents($in, $dados);

    $ok = openssl_pkcs7_sign($in, $out, $certs['cert'], $certs['pkey'], [], PKCS7_DETACHED | PKCS7_BINARY);
    $sig = $ok ? file_get_contents($out) : null;
    @unlink($in); @unlink($out);
    return $sig;
}

// ===================================================================
// CARIMBO DE TEMPO (TSA - RFC 3161)
// Usa freetsa.org gratuito via openssl ts (se disponível) ou
// timestamp do servidor.
// ===================================================================

/**
 * Gera carimbo de tempo. Tenta TSA externa; se falhar usa servidor.
 * Retorna ['fonte'=>'tsa|servidor', 'token'=>bytes, 'momento'=>datetime]
 */
function repp_carimbo_tempo(string $dados): array {
    $sha = hash('sha256', $dados);
    $momento = date('Y-m-d H:i:s');

    // Tenta freetsa.org (POST application/timestamp-query)
    // Implementação completa exige geração de TimeStampReq ASN.1 (binário).
    // Aqui usamos modo "simplificado": grava o hash + chamamos endpoint público.
    // Se openssl ts não estiver disponível, mantém apenas timestamp do servidor.
    $tsa_disponivel = false;
    $tsa_token = null;

    if (function_exists('exec')) {
        $exec_out = []; $exec_code = 0;
        @exec('which openssl 2>&1', $exec_out, $exec_code);
        if ($exec_code === 0) {
            $tmp_data = tempnam(sys_get_temp_dir(), 'tsa_data_');
            $tmp_req  = tempnam(sys_get_temp_dir(), 'tsa_req_');
            $tmp_resp = tempnam(sys_get_temp_dir(), 'tsa_resp_');
            file_put_contents($tmp_data, $dados);

            // Cria requisição TimeStamp
            $cmd_req = "openssl ts -query -data " . escapeshellarg($tmp_data) . " -sha256 -no_nonce -out " . escapeshellarg($tmp_req) . " 2>&1";
            @exec($cmd_req, $out_req, $code_req);

            if ($code_req === 0 && filesize($tmp_req) > 0) {
                // Envia para freetsa.org
                $req_data = file_get_contents($tmp_req);
                $ch = curl_init('https://freetsa.org/tsr');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $req_data,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/timestamp-query'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $tsr = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http === 200 && $tsr && strlen($tsr) > 100) {
                    $tsa_token = $tsr;
                    $tsa_disponivel = true;
                }
            }
            @unlink($tmp_data); @unlink($tmp_req); @unlink($tmp_resp);
        }
    }

    return [
        'fonte'   => $tsa_disponivel ? 'tsa_freetsa' : 'servidor',
        'sha256'  => $sha,
        'token'   => $tsa_token,
        'momento' => $momento,
    ];
}

// ===================================================================
// QR CODE TOKEN (para validação pública via /validar.php)
// ===================================================================

function repp_qr_token(int $batida_id, int $nsr): string {
    return hash('sha256', "$batida_id|$nsr|" . repp_chave_mestra());
}

function repp_url_validacao(int $nsr, string $qr_token): string {
    $cfg = require __DIR__ . '/../config/app.php';
    return rtrim($cfg['base_url'], '/') . "/validar.php?nsr=$nsr&t=$qr_token";
}
