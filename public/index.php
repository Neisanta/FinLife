<?php
/**
 * FinLife — Front controller.
 * Rotas amigáveis: /login, /dashboard, /transacoes, /contas,
 * /categorias, /metas, /ai
 */
declare(strict_types=1);

define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

// ------------------------------------------------------------
// Cálculo da rota (funciona com Apache/XAMPP e servidor embutido)
// ------------------------------------------------------------
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = base_path();
$path = $uri;

if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
// remove segmento "public" (acesso direto /FinLife/public/...)
$path = preg_replace('~/public(?=/|$)~', '', $path) ?? $path;
$route = trim($path, '/');
if (str_contains($route, '/')) {
    // rotas aninhadas não existem no MVP
    $route = strtok($route, '/') ?: '';
}

// ------------------------------------------------------------
// Mapa de rotas
// ------------------------------------------------------------
$routes = [
    ''           => [PageController::class, 'home'],
    'login'      => [PageController::class, 'login'],
    'cadastro'   => [PageController::class, 'register'],
    'register'   => [PageController::class, 'register'],
    'logout'     => [PageController::class, 'logout'],
    'dashboard'  => [PageController::class, 'dashboard'],
    'transacoes' => [PageController::class, 'transactions'],
    'contas'     => [PageController::class, 'accounts'],
    'cartoes'    => [PageController::class, 'cards'],
    'mercados'   => [PageController::class, 'markets'],
    'categorias' => [PageController::class, 'categories'],
    'metas'      => [PageController::class, 'goals'],
    'compromissos' => [PageController::class, 'commitments'],
    'ai'         => [PageController::class, 'aiChat'],
];

if (isset($routes[$route])) {
    [$class, $method] = $routes[$route];
    $class::$method();
    exit;
}

PageController::notFound();
