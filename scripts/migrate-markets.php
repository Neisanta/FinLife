<?php
/**
 * FinLife — Migração do módulo de Mercados e Investimentos.
 *
 * Uso:  C:\xampp\php\php.exe scripts/migrate-markets.php
 *
 * Idempotente:
 * - Amplia o ENUM asset_class de investments (tabela ainda não usada).
 * - Cria 7 tabelas: market_quotes, market_history_cache, market_watchlists,
 *   market_watchlist_items, market_alerts, portfolio_transactions, portfolio_dividends.
 */
declare(strict_types=1);

define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execute este script apenas via CLI.');
}

echo "=== FinLife — migração do módulo de Mercados ===\n";

try {
    // 1) ENUM de investments ampliado (FIAGRO e BDR)
    Database::run(
        "ALTER TABLE investments
         MODIFY asset_class ENUM('renda_fixa','acoes','fii','fiagro','etf','bdr','cripto','outro')
         NOT NULL DEFAULT 'outro'"
    );
    echo "[ok] investments.asset_class ampliado (fiagro, bdr)\n";

    // 1b) Colunas de alerta adicionadas em versões anteriores da tabela
    foreach ([
        "ALTER TABLE market_alerts ADD COLUMN trigger_price DECIMAL(18,8) NULL" => 'trigger_price',
        "ALTER TABLE market_alerts ADD COLUMN last_notified_at DATETIME NULL" => 'last_notified_at',
    ] as $sql => $col) {
        try {
            Database::run($sql);
            echo "[ok] market_alerts.{$col} adicionada\n";
        } catch (Throwable $e) {
            if ((string) $e->getCode() === '42S21') {
                echo "[ok] market_alerts.{$col} já existe\n";
            } else {
                throw $e;
            }
        }
    }

    // 2) Executa CREATE TABLE IF NOT EXISTS do schema atual
    $sql = (string) file_get_contents(APP_ROOT . '/database.sql');
    $sql = preg_replace('~^\s*(--|#).*$~m', '', $sql) ?? $sql;
    $statements = array_filter(
        array_map('trim', explode(";\n", $sql . "\n")),
        static fn (string $s): bool => $s !== '' && !preg_match('~^(USE\b|/\*)~i', $s)
    );
    $done = 0;
    foreach ($statements as $stmt) {
        if (!preg_match('~CREATE TABLE~i', $stmt)) {
            continue;
        }
        try {
            Database::run($stmt);
            $done++;
        } catch (Throwable $e) {
            if ((string) $e->getCode() === '42S01') {
                continue;
            }
            throw $e;
        }
    }
    echo "[ok] {$done} CREATE TABLE verificados\n";

    // 3) Verificação
    $tables = Database::run('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $required = ['market_quotes', 'market_history_cache', 'market_watchlists',
                 'market_watchlist_items', 'market_alerts', 'portfolio_transactions', 'portfolio_dividends'];
    $missing = array_diff($required, $tables);
    if ($missing !== []) {
        fwrite(STDERR, '[erro] Tabelas ausentes: ' . implode(', ', $missing) . "\n");
        exit(1);
    }
    echo '[ok] Módulo de mercados pronto — 7 tabelas verificadas (' . count($tables) . " no total)\n";
    echo "\n=== Migração concluída ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[erro] ' . $e->getMessage() . "\n");
    log_error('migrate-markets: falha', ['msg' => $e->getMessage()]);
    exit(1);
}
