<?php
/**
 * FinLife — Instalador do banco de dados.
 *
 * Uso:  C:\xampp\php\php.exe scripts\install.php
 *
 * - Cria o banco (se não existir) e todas as tabelas de database.sql
 * - Idempotente: pode ser reexecutado sem perder dados (CREATE TABLE IF NOT EXISTS)
 */
declare(strict_types=1);

define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execute este script apenas via CLI.');
}

echo "=== FinLife — instalação do banco de dados ===\n";

try {
    $host = Env::get('DB_HOST', '127.0.0.1');
    $port = Env::int('DB_PORT', 3306);
    $name = Env::get('DB_NAME', 'finlife');
    $user = Env::get('DB_USER', 'root');
    $pass = Env::get('DB_PASS', '');

    // 1) Conecta sem selecionar banco e cria o database se necessário
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '', $name)
    ));
    $pdo->exec(sprintf('USE `%s`', str_replace('`', '', $name)));
    echo "[ok] Banco '{$name}' disponível\n";

    // 2) Executa o schema, statement por statement
    $sqlFile = APP_ROOT . '/database.sql';
    if (!is_file($sqlFile)) {
        fwrite(STDERR, "[erro] database.sql não encontrado em {$sqlFile}\n");
        exit(1);
    }
    $sql = (string) file_get_contents($sqlFile);

    // remove comentários de linha (-- e #)
    $sql = preg_replace('~^\s*(--|#).*$~m', '', $sql) ?? $sql;

    $statements = array_filter(
        array_map('trim', explode(";\n", $sql . "\n")),
        static fn (string $s): bool => $s !== '' && !preg_match('~^(USE\b|/\*)~i', $s)
    );

    $done = 0;
    foreach ($statements as $stmt) {
        if (trim($stmt) === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
            $done++;
        } catch (PDOException $e) {
            // tabelas já existentes são aceitáveis (schema idempotente)
            if ((string) $e->getCode() === '42S01') {
                continue;
            }
            throw $e;
        }
    }
    echo "[ok] {$done} instruções do schema executadas\n";

    // 3) Verificação
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $expected = ['users', 'accounts', 'categories', 'transactions', 'goals', 'goal_deposits', 'ai_conversations', 'ai_messages', 'rate_limits'];
    $missing = array_diff($expected, $tables);
    if ($missing !== []) {
        fwrite(STDERR, '[erro] Tabelas ausentes: ' . implode(', ', $missing) . "\n");
        exit(1);
    }
    echo '[ok] Tabelas essenciais verificadas (' . count($tables) . " no total)\n";
    echo "\n=== Instalação concluída com sucesso ===\n";
    echo "Acesse a aplicação e crie sua conta em /cadastro.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[erro] ' . $e->getMessage() . "\n");
    log_error('install: falha', ['msg' => $e->getMessage()]);
    exit(1);
}
