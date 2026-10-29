<?php
/**
 * FinLife — Roteador para o servidor embutido do PHP (desenvolvimento).
 *
 * Uso:  php -S 127.0.0.1:8090 -t public router.php
 *
 * - Estáticos (/assets/...) são servidos a partir de public/
 * - Endpoints REST (/api/*.php) executam os arquivos da raiz
 * - Qualquer outra rota cai no front controller public/index.php
 */
declare(strict_types=1);

$root = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Bloqueia arquivos sensíveis em qualquer cenário de servidor
if (preg_match('#^/(\.(?!well-known).*)$#', $path)
    || preg_match('#\.(env|sql|md|log|ini|lock|json)$#', $path)
    || preg_match('#^/(config|includes|core|models|services|controllers|views|logs|scripts)(/|$)#', $path)
) {
    http_response_code(404);
    exit('Não encontrado');
}

// Endpoints REST: /api/<nome>.php e /api/market/<nome>.php
if (preg_match('#^/api/market/([A-Za-z_]+)\.php$#', $path, $m)) {
    $file = $root . '/api/market/' . $m[1] . '.php';
    if (!is_file($file)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Endpoint nao encontrado']);
        exit;
    }
    $_SERVER['SCRIPT_NAME'] = $path;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    require $file;
    return true;
}
if (preg_match('#^/api/([A-Za-z_]+)\.php$#', $path, $m)) {
    $file = $root . '/api/' . $m[1] . '.php';
    if (!is_file($file)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Endpoint nao encontrado']);
        exit;
    }
    $_SERVER['SCRIPT_NAME'] = $path;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    require $file;
    return true;
}

// Arquivos estáticos existentes dentro de /public
if ($path !== '/' && is_file($root . '/public' . $path)) {
    // deixa o servidor embutido servir o arquivo a partir de -t public
    return false;
}

// Front controller
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
require $root . '/public/index.php';
return true;
