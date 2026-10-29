<?php
/**
 * FinLife — Configuração geral da aplicação.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

return [
    'name'        => Env::get('APP_NAME', 'FinLife'),
    'env'         => Env::get('APP_ENV', 'production'),
    'debug'       => Env::bool('APP_DEBUG', false),
    'currency'    => 'BRL',
    'locale'      => 'pt_BR',
    'timezone'    => 'America/Sao_Paulo',
    'db'          => [
        'host'    => Env::get('DB_HOST', '127.0.0.1'),
        'port'    => Env::int('DB_PORT', 3306),
        'name'    => Env::get('DB_NAME', 'finlife'),
        'user'    => Env::get('DB_USER', 'root'),
        'pass'    => Env::get('DB_PASS', ''),
    ],
];
