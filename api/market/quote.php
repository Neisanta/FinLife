<?php
/** Mercado: cotações em lote (?symbols=PETR4,BTC,USDBRL). Avalia alertas em trânsito. */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $raw = (string) ($_GET['symbols'] ?? '');
    $symbols = array_slice(array_filter(array_map('trim', explode(',', $raw))), 0, 30);
    $quotes = MarketDataService::getQuotes($symbols, $MARKET_USER_ID);
    // preserva a ordem pedida pelo cliente
    $ordered = [];
    foreach ($symbols as $s) {
        $sym = MarketUniverse::normalize($s);
        if (isset($quotes[$sym])) {
            $ordered[] = $quotes[$sym];
        }
    }
    json_out([
        'ok' => true,
        'quotes' => $ordered,
        'triggered_alerts' => MarketDataService::pullTriggered(),
        'server_time' => time(),
    ]);
} catch (Throwable $e) {
    log_error('API market/quote: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro ao buscar cotações.'], 500);
}
