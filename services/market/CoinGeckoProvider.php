<?php
/**
 * FinLife — Provedor CoinGecko (dados agregados de cripto em BRL).
 * Complementa a Binance com market cap, ATH/ATL, supply e ranking —
 * e serve de fallback de cotação quando a Binance falhar.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class CoinGeckoProvider implements MarketProviderInterface
{
    private const BASE = 'https://api.coingecko.com/api/v3';

    /** Par USDT (Binance) → id CoinGecko. */
    private const IDS = [
        'BTC' => 'bitcoin', 'ETH' => 'ethereum', 'SOL' => 'solana', 'XRP' => 'ripple',
        'BNB' => 'binancecoin', 'ADA' => 'cardano', 'DOGE' => 'dogecoin', 'LTC' => 'litecoin',
        'AVAX' => 'avalanche-2', 'DOT' => 'polkadot', 'LINK' => 'chainlink', 'ATOM' => 'cosmos',
        'USDT' => 'tether', 'USDC' => 'usd-coin', 'MATIC' => 'matic-network', 'TRX' => 'tron',
    ];

    public static function name(): string
    {
        return 'CoinGecko';
    }

    /** Agregador: dados consolidados com pequeno atraso (~1 min). */
    public static function delaySeconds(): int
    {
        return 60;
    }

    public static function available(): bool
    {
        return true;
    }

    public static function supports(string $symbol): bool
    {
        return isset(self::IDS[self::base(strtoupper($symbol))]);
    }

    /** "BTCUSDT" → "BTC". */
    private static function base(string $pair): string
    {
        foreach (['USDT', 'USDC', 'FDUSD', 'BUSD'] as $q) {
            if (str_ends_with($pair, $q) && strlen($pair) > strlen($q)) {
                return substr($pair, 0, -strlen($q));
            }
        }
        return $pair;
    }

    /** Dados extras (market cap, ATH, ranking) em BRL para uma lista de bases. */
    public static function getExtras(array $symbols): array
    {
        $ids = [];
        foreach ($symbols as $s) {
            $b = self::base(strtoupper((string) $s));
            if (isset(self::IDS[$b])) {
                $ids[] = self::IDS[$b];
            }
        }
        if ($ids === []) {
            return [];
        }
        $res = MarketHttpClient::get(self::BASE . '/coins/markets?vs_currency=brl&ids='
            . rawurlencode(implode(',', $ids)) . '&per_page=50', 10);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [];
        }
        $out = [];
        foreach ($res['data'] as $c) {
            $id = (string) ($c['id'] ?? '');
            $base = array_search($id, self::IDS, true);
            if ($base === false) {
                continue;
            }
            $out[(string) $base] = [
                'name' => $c['name'] ?? null,
                'price_brl' => isset($c['current_price']) ? (float) $c['current_price'] : null,
                'market_cap' => isset($c['market_cap']) ? (float) $c['market_cap'] : null,
                'market_cap_rank' => isset($c['market_cap_rank']) ? (int) $c['market_cap_rank'] : null,
                'ath' => isset($c['ath']) ? (float) $c['ath'] : null,
                'atl' => isset($c['atl']) ? (float) $c['atl'] : null,
                'circulating_supply' => isset($c['circulating_supply']) ? (float) $c['circulating_supply'] : null,
                'total_supply' => isset($c['total_supply']) ? (float) $c['total_supply'] : null,
                'pct_1h' => isset($c['price_change_percentage_1h_in_currency']) ? (float) $c['price_change_percentage_1h_in_currency'] : null,
                'pct_24h' => isset($c['price_change_percentage_24h']) ? (float) $c['price_change_percentage_24h'] : null,
                'pct_7d' => isset($c['price_change_percentage_7d_in_currency']) ? (float) $c['price_change_percentage_7d_in_currency'] : null,
            ];
        }
        return $out;
    }

    public static function getQuotes(array $symbols): array
    {
        $extras = self::getExtras($symbols);
        $out = [];
        foreach ($symbols as $s) {
            $sym = strtoupper((string) $s);
            $b = self::base($sym);
            if (!isset($extras[$b])) {
                continue;
            }
            $e = $extras[$b];
            $price = $e['price_brl'];
            if ($price === null) {
                continue;
            }
            $out[$b . 'BRL'] = [
                'symbol' => $b,
                'class' => 'cripto',
                'price' => $price,
                'change_pct' => $e['pct_24h'],
                'day_change' => null,
                'day_high' => null,
                'day_low' => null,
                'day_open' => null,
                'prev_close' => null,
                'volume' => null,
                'volume_money' => null,
                'currency' => 'BRL',
                'market' => 'CoinGecko (agregado)',
                'delay_seconds' => self::delaySeconds(),
                'quote_time' => time(),
                'extra' => $e,
            ];
        }
        return $out;
    }

    public static function getHistory(string $symbol, string $interval, string $range): array
    {
        $b = self::base(strtoupper($symbol));
        if (!isset(self::IDS[$b])) {
            return [];
        }
        $days = ['1D' => 1, '5D' => 7, '1M' => 30, '3M' => 90, '6M' => 180, '1A' => 365, '5A' => 1825, 'MAX' => 3650][strtoupper($range)] ?? 30;
        $res = MarketHttpClient::get(self::BASE . '/coins/' . self::IDS[$b] . '/market_chart?vs_currency=brl&days=' . $days, 12);
        if (!$res['ok'] || empty($res['data']['prices'])) {
            return [];
        }
        $candles = [];
        foreach ($res['data']['prices'] as $p) {
            $candles[] = ['t' => intdiv((int) $p[0], 1000), 'o' => (float) $p[1], 'h' => (float) $p[1], 'l' => (float) $p[1], 'c' => (float) $p[1], 'v' => 0];
        }
        return $candles;
    }

    public static function search(string $query): array
    {
        $q = Validator::text($query, 30);
        if ($q === '') {
            return [];
        }
        $res = MarketHttpClient::get(self::BASE . '/search?query=' . rawurlencode($q), 8);
        if (!$res['ok'] || empty($res['data']['coins'])) {
            return [];
        }
        $out = [];
        foreach (array_slice($res['data']['coins'], 0, 12) as $c) {
            $out[] = [
                'symbol' => strtoupper($c['symbol'] ?? '') . 'USDT',
                'name' => $c['name'] ?? null,
                'exchange' => 'CoinGecko',
                'type' => 'cripto',
            ];
        }
        return $out;
    }

    public static function getNews(string $query): array
    {
        return [];
    }
}
