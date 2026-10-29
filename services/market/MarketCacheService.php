<?php
/**
 * FinLife — Cache read-through de market data (MySQL).
 * TTL por classe: cripto 10s · câmbio 60s · bolsa/índices 120s ·
 * commodities 300s · histórico intraday 15min · diário 6h · notícias 30min.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class MarketCacheService
{
    /** TTL de cotações por classe (segundos). */
    public static function quoteTtl(string $class): int
    {
        return [
            'cripto' => 10,
            'forex' => 60,
            'br' => 120, 'br_stock' => 120, 'fii' => 120, 'fiagro' => 120, 'etf' => 120, 'bdr' => 120,
            'index' => 120, 'us_stock' => 120, 'commodity' => 300,
        ][$class] ?? 120;
    }

    /** TTL de histórico por período. */
    public static function historyTtl(string $range): int
    {
        return match (strtoupper($range)) {
            '1D', '5D' => 900,     // intraday
            '1M' => 3600,
            '3M', '6M', '1A' => 6 * 3600,
            default => 24 * 3600, // 5A / MAX
        };
    }

    /** Cotações em cache separadas em frescas e velhas (stale). */
    public static function getQuotes(array $symbols): array
    {
        $symbols = array_values(array_filter(array_map('strval', $symbols), static fn ($s) => $s !== ''));
        if ($symbols === []) {
            return ['fresh' => [], 'stale' => []];
        }
        $ph = [];
        $params = [];
        foreach ($symbols as $i => $s) {
            $ph[] = ':s' . $i;
            $params['s' . $i] = $s;
        }
        $rows = Database::run(
            'SELECT * FROM market_quotes WHERE symbol IN (' . implode(',', $ph) . ')',
            $params
        )->fetchAll();
        $fresh = [];
        $stale = [];
        foreach ($rows as $r) {
            $age = time() - strtotime((string) $r['fetched_at']);
            $q = self::hydrateQuote($r);
            if (!$q['is_stale'] && $age <= self::quoteTtl((string) $r['class'])) {
                $fresh[(string) $r['symbol']] = $q;
            } else {
                $q['is_stale'] = true;
                $stale[(string) $r['symbol']] = $q;
            }
        }
        return ['fresh' => $fresh, 'stale' => $stale];
    }

    public static function saveQuote(array $q): void
    {
        Database::run(
            'INSERT INTO market_quotes (symbol, class, provider, price, change_pct, day_change, day_high, day_low,
                                        day_open, prev_close, volume, volume_money, currency, market,
                                        delay_seconds, quote_time, fetched_at, is_stale, extra)
             VALUES (:symbol, :class, :provider, :price, :pct, :chg, :high, :low, :open, :prev, :vol, :volm,
                     :cur, :mkt, :delay, :qtime, NOW(), 0, :extra)
             ON DUPLICATE KEY UPDATE
                class = VALUES(class), provider = VALUES(provider), price = VALUES(price),
                change_pct = VALUES(change_pct), day_change = VALUES(day_change), day_high = VALUES(day_high),
                day_low = VALUES(day_low), day_open = VALUES(day_open), prev_close = VALUES(prev_close),
                volume = VALUES(volume), volume_money = VALUES(volume_money), currency = VALUES(currency),
                market = VALUES(market), delay_seconds = VALUES(delay_seconds), quote_time = VALUES(quote_time),
                fetched_at = NOW(), is_stale = 0, extra = VALUES(extra)',
            [
                'symbol' => $q['symbol'],
                'class' => $q['class'],
                'provider' => $q['provider'],
                'price' => (float) $q['price'],
                'pct' => $q['change_pct'] !== null ? (float) $q['change_pct'] : 0,
                'chg' => $q['day_change'] !== null ? (float) $q['day_change'] : 0,
                'high' => $q['day_high'],
                'low' => $q['day_low'],
                'open' => $q['day_open'],
                'prev' => $q['prev_close'],
                'vol' => $q['volume'],
                'volm' => $q['volume_money'],
                'cur' => $q['currency'],
                'mkt' => $q['market'],
                'delay' => (int) ($q['delay_seconds'] ?? 0),
                'qtime' => (int) ($q['quote_time'] ?? time()),
                'extra' => isset($q['extra']) ? json_encode($q['extra'], JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }

    /** Payload genérico (histórico, notícias, busca) com TTL. */
    public static function getPayload(string $key, int $ttl): ?array
    {
        $row = Database::run(
            'SELECT payload, fetched_at FROM market_history_cache WHERE cache_key = :k LIMIT 1',
            ['k' => $key]
        )->fetch();
        if (!$row) {
            return null;
        }
        if (time() - strtotime((string) $row['fetched_at']) > $ttl) {
            return null;
        }
        $json = json_decode((string) $row['payload'], true);
        return is_array($json) ? $json : null;
    }

    public static function savePayload(string $key, array $payload): void
    {
        Database::run(
            'INSERT INTO market_history_cache (cache_key, payload, fetched_at)
             VALUES (:k, :p, NOW())
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()',
            ['k' => $key, 'p' => json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** Converte linha do banco no formato normalizado de quote. */
    private static function hydrateQuote(array $r): array
    {
        return [
            'symbol' => (string) $r['symbol'],
            'class' => (string) $r['class'],
            'provider' => (string) $r['provider'],
            'price' => (float) $r['price'],
            'change_pct' => $r['change_pct'] !== null ? (float) $r['change_pct'] : null,
            'day_change' => $r['day_change'] !== null ? (float) $r['day_change'] : null,
            'day_high' => $r['day_high'] !== null ? (float) $r['day_high'] : null,
            'day_low' => $r['day_low'] !== null ? (float) $r['day_low'] : null,
            'day_open' => $r['day_open'] !== null ? (float) $r['day_open'] : null,
            'prev_close' => $r['prev_close'] !== null ? (float) $r['prev_close'] : null,
            'volume' => $r['volume'] !== null ? (float) $r['volume'] : null,
            'volume_money' => $r['volume_money'] !== null ? (float) $r['volume_money'] : null,
            'currency' => (string) $r['currency'],
            'market' => $r['market'] !== null ? (string) $r['market'] : null,
            'delay_seconds' => (int) $r['delay_seconds'],
            'quote_time' => (int) $r['quote_time'],
            'fetched_at' => (string) $r['fetched_at'],
            'is_stale' => (int) $r['is_stale'] === 1,
            'live' => (int) $r['delay_seconds'] === 0,
            'extra' => $r['extra'] !== null ? json_decode((string) $r['extra'], true) : null,
        ];
    }
}
