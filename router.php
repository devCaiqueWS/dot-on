<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

// Instalador personalizado por empresa (espelha as regras do .htaccess)
if (preg_match('#^/app/install/([a-z0-9-]+)/download/?$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    $_GET['download'] = 1;
    require __DIR__ . '/app/install.php';
    return true;
}
if (preg_match('#^/app/install/([a-z0-9-]+)/?$#', $path, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/app/install.php';
    return true;
}

if (preg_match('#^/app/api/(signup|cnpj/\d+|parse_funcionarios)/?$#', $path)) {
    require __DIR__ . '/app/api/signup.php';
    return true;
}

if (preg_match('#^/app/api(?:/.*)?$#', $path)) {
    require __DIR__ . '/app/api/index.php';
    return true;
}

return false;
