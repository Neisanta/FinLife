<?php
/**
 * FinLife — Bootstrap da aplicação.
 * Todo ponto de entrada (public/index.php, api/*.php, scripts CLI)
 * define FINLIFE e requer este arquivo. Acesso direto a ele é proibido.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

define('APP_ROOT', dirname(__DIR__));
define('CONFIG_PATH', APP_ROOT . '/config');
define('LOG_PATH', APP_ROOT . '/logs');

require APP_ROOT . '/includes/Env.php';

// Carrega .env (cria a partir do exemplo se ainda não existir).
// Fallback env.txt: hospedagens compartilhadas ocultam arquivos que começam
// com ponto (o File Manager e o zip não os sobem) — aceitamos o mesmo
// conteúdo em "env.txt", que é um arquivo normal e sobe em qualquer lugar.
// O .htaccess da raiz bloqueia acesso público a qualquer "env.*".
if (!is_file(APP_ROOT . '/.env') && is_file(APP_ROOT . '/.env.example')) {
    @copy(APP_ROOT . '/.env.example', APP_ROOT . '/.env');
}
if (!Env::load(APP_ROOT . '/.env')) {
    Env::load(APP_ROOT . '/env.txt');
}

// Relatório de erros: debug em dev, silêncio em produção (vai para o log)
if (Env::bool('APP_DEBUG', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/app.log');

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

// Autoload das classes da aplicação (core, models, services, controllers, services/market)
spl_autoload_register(static function (string $class): void {
    foreach (['core', 'models', 'services/market', 'services', 'controllers'] as $dir) {
        $file = APP_ROOT . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

require APP_ROOT . '/includes/helpers.php';

// Sessão (somente contexto web — scripts CLI não precisam)
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $lifetime = Env::int('SESSION_LIFETIME', 86400);
    session_name(Env::get('SESSION_NAME', 'finlife_session'));
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);
    session_start();

    // Expiração por inatividade
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $lifetime) {
        Auth::logout();
    }
    $_SESSION['last_activity'] = $now;
}

// Cabeçalhos de segurança para respostas web
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
