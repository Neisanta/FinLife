<?php
/**
 * FinLife — Migração do módulo de Cartões.
 *
 * Uso:  C:\xampp\php\php.exe scripts\migrate-cards.php
 *
 * Idempotente: pode ser reexecutado sem perder dados.
 * - Remove as tabelas antigas de "fase 2" (credit_cards/credit_card_transactions),
 *   que nunca foram usadas por nenhum código do projeto (apenas declaradas).
 * - Cria as 8 tabelas do módulo de cartões (schema de database.sql).
 * - Verifica a estrutura final.
 */
declare(strict_types=1);

define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execute este script apenas via CLI.');
}

echo "=== FinLife — migração do módulo de Cartões ===\n";

try {
    // 1) Tabelas antigas (nunca usadas): remove com segurança
    $old = Database::run("SHOW TABLES LIKE 'credit_cards'")->fetch();
    if ($old !== false) {
        $count = (int) (Database::run('SELECT COUNT(*) AS c FROM credit_cards')->fetch()['c'] ?? 0);
        if ($count > 0) {
            fwrite(STDERR, "[aviso] credit_cards possui {$count} registro(s) legado(s). Tabela preservada (não usada pelo app).\n");
        } else {
            Database::run('SET FOREIGN_KEY_CHECKS = 0');
            Database::run('DROP TABLE IF EXISTS credit_card_transactions, credit_cards');
            Database::run('SET FOREIGN_KEY_CHECKS = 1');
            echo "[ok] Tabelas antigas (vazias) removidas: credit_cards, credit_card_transactions\n";
        }
    } else {
        echo "[ok] Tabelas antigas não existem\n";
    }

    // 2) Executa o schema atual de cartões (statement por statement)
    $sql = (string) file_get_contents(APP_ROOT . '/database.sql');
    $sql = preg_replace('~^\s*(--|#).*$~m', '', $sql) ?? $sql;
    $statements = array_filter(
        array_map('trim', explode(";\n", $sql . "\n")),
        static fn (string $s): bool => $s !== '' && !preg_match('~^(USE\b|/\*)~i', $s)
    );

    $done = 0;
    foreach ($statements as $stmt) {
        if (!preg_match('~CREATE TABLE~i', $stmt)) {
            continue; // migração de cartões: apenas CREATE TABLE IF NOT EXISTS
        }
        try {
            Database::run($stmt);
            $done++;
        } catch (Throwable $e) {
            if ((string) $e->getCode() === '42S01') {
                continue; // já existe
            }
            throw $e;
        }
    }
    echo "[ok] {$done} CREATE TABLE verificados\n";

    // 3) Verificação final
    $tables = Database::run('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $required = ['cards', 'card_balances', 'card_benefit_credits', 'card_purchases',
                 'card_invoices', 'card_installments', 'card_invoice_payments', 'card_recurring'];
    $missing = array_diff($required, $tables);
    if ($missing !== []) {
        fwrite(STDERR, '[erro] Tabelas ausentes: ' . implode(', ', $missing) . "\n");
        exit(1);
    }
    echo '[ok] Módulo de cartões pronto — 8 tabelas verificadas (' . count($tables) . " tabelas no total)\n";
    echo "\n=== Migração concluída ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[erro] ' . $e->getMessage() . "\n");
    log_error('migrate-cards: falha', ['msg' => $e->getMessage()]);
    exit(1);
}
