<?php
/** Validação PHP dos endpoints reais (runtime que o app usará). */
declare(strict_types=1);
define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

function httpJson(string $url): array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 10,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) FinLife/1.0\r\nAccept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['error' => 'fetch failed'];
    }
    $j = json_decode($raw, true);
    return $j ?? ['error' => 'invalid json', 'raw' => substr($raw, 0, 200)];
}

echo "=== SPARK LOTE (Yahoo) ===\n";
$sp = httpJson('https://query1.finance.yahoo.com/v7/finance/spark?symbols=PETR4.SA,VALE3.SA,HGLG11.SA&range=1d&interval=1d');
if (isset($sp['spark'])) {
    foreach ($sp['spark'] as $sym => $d) {
        echo $sym . ': close=' . json_encode(array_slice((array) $d['close'], -2))
            . ' prevClose=' . ($d['chartPreviousClose'] ?? ($d['previousClose'] ?? '?'))
            . ' chartPrev=' . ($d['chartPreviousClose'] ?? '?') . "\n";
    }
    echo 'campos: ' . implode(',', array_keys(array_values($sp['spark'])[0])) . "\n";
} else {
    echo 'spark erro: ' . json_encode($sp) . "\n";
}

echo "\n=== QUOTE SUMMARY (fundamentalistas PETR4.SA) ===\n";
$fs = httpJson('https://query1.finance.yahoo.com/v10/finance/quoteSummary/PETR4.SA?modules=price,summaryDetail,defaultKeyStatistics,financialData');
if (isset($fs['quoteSummary']['result'][0])) {
    $r = $fs['quoteSummary']['result'][0];
    $sd = $r['summaryDetail'] ?? [];
    $kd = $r['defaultKeyStatistics'] ?? [];
    $fd = $r['financialData'] ?? [];
    echo 'trailingPE=' . ($sd['trailingPE']['raw'] ?? '-') . ' priceToBook=' . ($kd['priceToBook']['raw'] ?? '-')
        . ' divYield=' . ($sd['dividendYield']['raw'] ?? '-')
        . ' roe=' . ($fd['returnOnEquity']['raw'] ?? '-')
        . ' margemLiquida=' . ($fd['profitMargins']['raw'] ?? '-')
        . ' mcap=' . ($kd['marketCap']['raw'] ?? ($r['price']['marketCap']['raw'] ?? '-')) . "\n";
    echo 'campos price: ' . implode(',', array_keys($r['price'] ?? [])) . "\n";
} else {
    echo 'quoteSummary erro: ' . json_encode($fs['error'] ?? $fs) . "\n";
}

echo "\n=== BCB SGS 12 (CDI diário) ===\n";
$bcb = @file_get_contents('https://api.bcb.gov.br/dados/serie/bcdata.sgs.12/dados/ultimos/5?formato=json');
if ($bcb !== false) {
    $cdi = json_decode($bcb, true);
    echo 'ultimos: ' . json_encode(array_slice($cdi, -2)) . "\n";
} else {
    echo "bcb falhou\n";
}

echo "\n=== SPARK cripto Binance batch ===\n";
$bn = httpJson('https://api.binance.com/api/v3/ticker/24hr?symbols=%5B%22BTCUSDT%22,%22ETHUSDT%22%5D');
if (is_array($bn) && isset($bn[0]['symbol'])) {
    echo 'BTC lastPrice=' . $bn[0]['lastPrice'] . ' pct=' . $bn[0]['priceChangePercent']
        . ' | ETH lastPrice=' . $bn[1]['lastPrice'] . ' pct=' . $bn[1]['priceChangePercent'] . "\n";
} else {
    echo 'binance batch erro: ' . json_encode($bn) . "\n";
}

echo "\n=== AwesomeAPI multi ===\n";
$aw = httpJson('https://economia.awesomeapi.com.br/last/USD-BRL,EUR-BRL,GBP-BRL,JPY-BRL');
foreach (['USDBRL', 'EURBRL'] as $k) {
    echo $k . ': bid=' . ($aw[$k]['bid'] ?? '?') . ' ts=' . ($aw[$k]['timestamp'] ?? '?') . "\n";
}
