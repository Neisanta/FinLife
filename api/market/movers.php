<?php
/** Mercado: maiores altas/quedas/volumes de um universo (?class=br_stock|fii|etf|bdr|cripto|fiagro). */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $class = Validator::in($_GET['class'] ?? 'br_stock', array_keys(MarketUniverse::all())) ?? 'br_stock';
    $limit = min(15, max(3, (int) ($_GET['limit'] ?? 10)));
    json_out(['ok' => true] + MarketDataService::getMovers($class, $limit));
} catch (Throwable $e) {
    log_error('API market/movers: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro ao carregar altas e quedas.'], 500);
}
