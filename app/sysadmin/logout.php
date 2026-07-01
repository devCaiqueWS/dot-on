<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!empty($_SESSION['dot_user']['id'])) {
    try {
        db()->prepare("INSERT INTO dot_sysadmin_log (super_admin_id, acao, ip) VALUES (?, 'logout', ?)")
            ->execute([$_SESSION['dot_user']['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {}
}
logout();
header('Location: login.php');
exit;
