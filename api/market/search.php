<?php
/** Mercado: busca de ativos (?q=). */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $results = MarketDataService::search((string) ($_GET['q'] ?? ''));
    json_out(['ok' => true, 'results' => $results]);
} catch (Throwable $e) {
    log_error('API market/search: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro na busca.'], 500);
}
