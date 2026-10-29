<?php
/** Mercado: candles OHLCV (?symbol=PETR4&range=1M). */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $symbol = MarketUniverse::normalize((string) ($_GET['symbol'] ?? ''));
    if ($symbol === '') {
        json_out(['ok' => false, 'error' => 'Informe o ativo.'], 400);
    }
    $range = Validator::in($_GET['range'] ?? '1M', ['1D', '5D', '1M', '3M', '6M', '1A', '5A', 'MAX']) ?? '1M';
    $candles = MarketDataService::getHistory($symbol, $range);
    json_out([
        'ok' => true,
        'symbol' => $symbol,
        'range' => $range,
        'candles' => $candles,
    ]);
} catch (Throwable $e) {
    log_error('API market/history: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro ao carregar o histórico.'], 500);
}
