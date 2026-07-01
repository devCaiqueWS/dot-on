<?php
/**
 * DOT-ON - Configuracoes Gerais
 */
// Segredo mestre (HMAC de QR tokens + chave AES da senha do certificado A1).
// PRODUÇÃO: defina a variável de ambiente DOTON_JWT_SECRET (>= 32 chars aleatórios)
// no painel da hospedagem. O fallback derivado existe só para não quebrar
// instalações antigas — NÃO confie nele em produção.
$jwt_secret = getenv('DOTON_JWT_SECRET') ?: ('troque-este-segredo-em-producao-' . hash('sha256', __DIR__));

return [
    'app_name'   => 'DOT-ON Registro de Ponto',
    'version'    => '1.0.0',
    'base_url'   => getenv('DOTON_BASE_URL') ?: 'https://dot-on.com.br/app',
    'timezone'   => 'America/Sao_Paulo',
    'jwt_secret' => $jwt_secret,
    'session_lifetime_min' => 480,
    'environment' => getenv('DOTON_ENV') ?: 'production',
];
