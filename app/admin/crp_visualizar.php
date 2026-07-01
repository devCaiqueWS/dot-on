<?php
/**
 * Exibe o CRP (HTML imprimível) de uma batida específica.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crp.php';
$user = requer_login();

$batida_id = (int)($_GET['id'] ?? 0);
if (!$batida_id) { echo "Batida não informada"; exit; }

// Permissão: dono da batida OU admin/RH
$stmt = db()->prepare("SELECT usuario_id FROM dot_batidas WHERE id=?");
$stmt->execute([$batida_id]);
$dono = $stmt->fetchColumn();
if ($dono != $user['id'] && !in_array($user['perfil'], ['admin','rh','gestor'])) {
    http_response_code(403); echo "Acesso negado"; exit;
}

echo crp_html($batida_id);
