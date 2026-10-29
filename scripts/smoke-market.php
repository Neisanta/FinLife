<?php
/** Smoke test da camada de market data (dados reais dos provedores). */
declare(strict_types=1);
define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

echo "=== QUOTES (multi-classe, multi-provedor) ===\n";
$q = MarketDataService::getQuotes(['PETR4', 'HGLG11', 'IVVB11', 'RZAG11', 'BTC', 'USD-BRL', '^BVSP', 'GC=F', 'AAPL']);
foreach ($q as $sym => $x) {
    echo str_pad(MarketUniverse::display($sym), 12)
        . ' price=' . str_pad((string) $x['price'], 12)
        . ' pct=' . str_pad((string) $x['change_pct'], 8)
        . ' prov=' . str_pad($x['provider'], 14)
        . ' delay=' . str_pad((string) $x['delay_seconds'], 5)
        . ' live=' . ($x['live'] ? 'SIM' : 'nao')
        . ' ts=' . date('H:i:s', $x['quote_time'])
        . ' cur=' . $x['currency']
        . (isset($x['price_original']) ? ' (orig USD ' . $x['price_original'] . ')' : '')
        . "\n";
}

echo "\n=== MOVERS FIIs ===\n";
$m = MarketDataService::getMovers('fii', 3);
echo 'gainers: ' . implode(', ', array_map(static fn ($g) => $g['display'] . ' ' . $g['change_pct'] . '%', $m['gainers'])) . "\n";
echo 'volumes: ' . implode(', ', array_map(static fn ($g) => $g['display'] . ' R$' . number_format($g['volume_money'] / 1000000, 1) . 'M', $m['volumes'])) . "\n";

echo "\n=== HISTORY PETR4 1M ===\n";
$h = MarketDataService::getHistory('PETR4', '1M');
echo 'candles: ' . count($h) . ' | ultima: ' . json_encode(end($h) ?: null) . "\n";

echo "\n=== HISTORY BTC 1D (Binance) ===\n";
$h2 = MarketDataService::getHistory('BTCUSDT', '1D');
echo 'candles: ' . count($h2) . ' | ultima: ' . json_encode(end($h2) ?: null) . "\n";

echo "\n=== SEARCH PETR4 ===\n";
$s = MarketDataService::search('PETR4');
echo 'resultados: ' . count($s) . ' | primeiro: ' . json_encode($s[0] ?? null) . "\n";

echo "\n=== NEWS Petrobras ===\n";
$n = MarketDataService::getNews('Petrobras');
echo 'noticias: ' . count($n) . ' | primeira: ' . json_encode(array_slice($n[0] ?? ['nada' => true], 0, 3), JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== COINGECKO EXTRAS BTC ===\n";
$e = CoinGeckoProvider::getExtras(['BTCUSDT']);
echo 'BTC: ' . json_encode(array_slice($e['BTC'] ?? [], 0, 4), JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== CACHE (segunda chamada deve usar cache) ===\n";
$t0 = microtime(true);
$q2 = MarketDataService::getQuotes(['PETR4']);
$ms = (int) round((microtime(true) - $t0) * 1000);
echo '2a chamada PETR4: ' . $ms . "ms | provider=" . $q2['PETR4.SA']['provider'] . ' fetched_at=' . $q2['PETR4.SA']['fetched_at'] . "\n";

echo "\n=== FX CACHE-FIRST: BTC sozinho deve vir em BRL ===\n";
Database::run('DELETE FROM rate_limits');
MarketDataService::getQuotes(['USDBRL']); // popula cache do câmbio
$btc = MarketDataService::getQuotes(['BTCUSDT'])['BTCUSDT'] ?? null;
echo $btc !== null
    ? 'BTC price=' . $btc['price'] . ' cur=' . $btc['currency'] . ' orig=' . ($btc['price_original'] ?? 'nulo') . ' fx=' . ($btc['extra']['fx_rate'] ?? 'nulo') . "\n"
    : "BTC não retornado\n";

