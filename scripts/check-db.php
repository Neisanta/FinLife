<?php
/**
 * FinLife — Verificação rápida do banco (CLI).
 * Uso: php scripts/check-db.php
 */
define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

try {
    $pdo = Database::conn();
    echo "DB OK (" . Env::get('DB_NAME', 'finlife') . ")\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "Tabelas (" . count($tables) . "): " . implode(', ', $tables) . "\n";
    foreach (['users', 'accounts', 'categories', 'transactions', 'goals', 'goal_deposits', 'ai_conversations', 'ai_messages', 'rate_limits'] as $t) {
        if (in_array($t, $tables, true)) {
            $c = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            echo "  {$t}: {$c} registro(s)\n";
        }
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
