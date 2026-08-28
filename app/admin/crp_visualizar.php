<?php
/**
 * Exibe o CRP (HTML imprimível) de uma batida específica.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crp.php';
$user = requer_login();

$batida_id = (int)($_GET['id'] ?? 0);
if (!$batida_id) { echo "Batida não informada"; exit; }

// Permissão: dono da batida OU admin/RH — sempre da MESMA empresa
$stmt = db()->prepare("SELECT b.usuario_id, u.empresa_id
    FROM dot_batidas b JOIN dot_usuarios u ON u.id=b.usuario_id
    WHERE b.id=?");
$stmt->execute([$batida_id]);
$b = $stmt->fetch();
if (!$b || (int)$b['empresa_id'] !== (int)$user['empresa_id']) {
    http_response_code(404); echo "Batida não encontrada"; exit;
}
if ((int)$b['usuario_id'] !== (int)$user['id'] && !in_array($user['perfil'], ['admin','rh','gestor'])) {
    http_response_code(403); echo "Acesso negado"; exit;
}

echo crp_html($batida_id);
