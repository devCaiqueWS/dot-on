<?php
/**
 * Redirecionador: /me/login.php → /admin/login.php (login único do sistema)
 */
$back = $_GET['back'] ?? '/app/me/';
$qs = http_build_query(['back' => $back]);
header("Location: ../admin/login.php?$qs");
exit;
