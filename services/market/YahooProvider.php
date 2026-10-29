<?php
/**
 * FinLife — Provedor Yahoo Finance.
 * Cobertura real testada: ações B3 (.SA), FIIs, FIAGROs, ETFs, BDRs listados,
 * índices (^BVSP, ^GSPC...), câmbio (USDBRL=X) e commodities futuras (=F).
 * Atraso declarado: dados de bolsa/índices com até 15 min (nunca "ao vivo").
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class YahooProvider implements MarketProviderInterface
{
    private const BASE = 'https://query1.finance.yahoo.com';

    public static function name(): string
    {
        return 'Yahoo Finance';
    }

    /** Ações e índices de bolsa chegam com atraso de até 15 minutos. */
    public static function delaySeconds(): int
    {
        return 900;
    }

    public static function available(): bool
    {
        return true;
    }

    public static function supports(string $symbol): bool
    {
        return str_ends_with($symbol, '.SA')
            || str_starts_with($symbol, '^')
            || str_ends_with($symbol, '=X')
            || str_ends_with($symbol, '=F')
            || preg_match('~^[A-Z]{2,6}$~', $symbol) === 1;
    }

    /** Classe de ativo derivada do símbolo (roteamento fino fica no universo). */
    public static function classify(string $symbol): string
    {
        if (str_starts_with($symbol, '^')) { return 'index'; }
        if (str_ends_with($symbol, '=X')) { return 'forex'; }
        if (str_ends_with($symbol, '=F')) { return 'commodity'; }
        if (str_ends_with($symbol, '.SA')) { return 'br'; }
        return 'us_stock';
    }

    /**
     * Cotações em lote (uma chamada chart por símbolo, concorrentes via curl_multi).
     * Usa 5m×1d: retorna preço, OHLC do dia e volume financeiro REAL (Σ close×vol).
     */
    public static function getQuotes(array $symbols): array
    {
        $symbols = array_values(array_unique(array_map('strtoupper', $symbols)));
        if ($symbols === []) {
            return [];
        }
        $urls = [];
        foreach ($symbols as $i => $sym) {
            $urls[$i] = self::BASE . '/v8/finance/chart/' . rawurlencode($sym) . '?interval=5m&range=1d&includePrePost=false';
        }
        $responses = MarketHttpClient::getMulti($urls, 8);

        $out = [];
        foreach ($symbols as $i => $sym) {
            $res = $responses[$i] ?? null;
            if ($res === null || !$res['ok'] || empty($res['data']['chart']['result'][0])) {
                continue;
            }
            $r = $res['data']['chart']['result'][0];
            $meta = $r['meta'] ?? [];
            $q = ($r['indicators']['quote'][0] ?? []);
            $ts = $r['timestamp'] ?? [];

            $price = isset($meta['regularMarketPrice']) ? (float) $meta['regularMarketPrice'] : null;
            if ($price === null && !empty($q['close'])) {
                foreach (array_reverse($q['close']) as $c) {
                    if ($c !== null) { $price = (float) $c; break; }
                }
            }
            if ($price === null) {
                continue;
            }
            $prevClose = isset($meta['chartPreviousClose']) ? (float) $meta['chartPreviousClose'] : null;

            // OHLC do dia a partir das candles intraday (reais)
            $dayHigh = isset($meta['regularMarketDayHigh']) ? (float) $meta['regularMarketDayHigh'] : null;
            $dayLow = isset($meta['regularMarketDayLow']) ? (float) $meta['regularMarketDayLow'] : null;
            $dayOpen = null;
            $volume = isset($meta['regularMarketVolume']) ? (float) $meta['regularMarketVolume'] : null;
            $volumeMoney = 0.0;
            $candleCount = 0;
            if (!empty($ts)) {
                foreach ($ts as $k => $t) {
                    $c = $q['close'][$k] ?? null;
                    $h = $q['high'][$k] ?? null;
                    $l = $q['low'][$k] ?? null;
                    $o = $q['open'][$k] ?? null;
                    $v = $q['volume'][$k] ?? null;
                    if ($c === null) { continue; }
                    $candleCount++;
                    if ($h !== null) { $dayHigh = ($dayHigh !== null) ? max($dayHigh, (float) $h) : (float) $h; }
                    if ($l !== null) { $dayLow = ($dayLow !== null) ? min($dayLow, (float) $l) : (float) $l; }
                    if ($o !== null && $dayOpen === null) { $dayOpen = (float) $o; }
                    if ($v !== null && $v > 0) { $volumeMoney += ((float) $c) * ((float) $v); }
                    if ($v !== null) { $volume = ($volume ?? 0) + 0; /* meta prevalece */ }
                }
            }

            $change = ($prevClose !== null && $prevClose > 0) ? $price - $prevClose : null;
            $pct = ($change !== null) ? ($change / $prevClose) * 100 : null;

            $out[$sym] = [
                'symbol' => $sym,
                'class' => self::classify($sym),
                'price' => $price,
                'change_pct' => $pct !== null ? round($pct, 4) : null,
                'day_change' => $change !== null ? round($change, 6) : null,
                'day_high' => $dayHigh,
                'day_low' => $dayLow,
                'day_open' => $dayOpen,
                'prev_close' => $prevClose,
                'volume' => $volume,
                'volume_money' => $candleCount > 0 && $volumeMoney > 0 ? round($volumeMoney, 2) : null,
                'currency' => $meta['currency'] ?? 'USD',
                'market' => $meta['fullExchangeName'] ?? ($meta['exchangeName'] ?? null),
                'delay_seconds' => self::delaySeconds(),
                'quote_time' => isset($meta['regularMarketTime']) ? (int) $meta['regularMarketTime'] : time(),
                'extra' => [
                    'instrument_type' => $meta['instrumentType'] ?? null,
                    'gmtoffset' => $meta['gmtoffset'] ?? null,
                ],
            ];
        }
        return $out;
    }

    public static function getHistory(string $symbol, string $interval, string $range): array
    {
        // períodos → parâmetros Yahoo
        $map = [
            '1D' => ['5m', '1d'], '5D' => ['15m', '5d'], '1M' => ['1h', '1mo'],
            '3M' => ['1d', '3mo'], '6M' => ['1d', '6mo'], '1A' => ['1d', '1y'],
            '5A' => ['1wk', '5y'], 'MAX' => ['1mo', 'max'],
        ];
        $key = strtoupper($range);
        if (!isset($map[$key])) {
            $key = '1M';
        }
        [$yInterval, $yRange] = $map[$key];

        $res = MarketHttpClient::get(self::BASE . '/v8/finance/chart/' . rawurlencode(strtoupper($symbol))
            . '?interval=' . $yInterval . '&range=' . $yRange . '&includePrePost=false', 10);
        if (!$res['ok'] || empty($res['data']['chart']['result'][0])) {
            return [];
        }
        $r = $res['data']['chart']['result'][0];
        $ts = $r['timestamp'] ?? [];
        $q = $r['indicators']['quote'][0] ?? [];
        $candles = [];
        foreach ($ts as $k => $t) {
            if (!isset($q['close'][$k]) || $q['close'][$k] === null) {
                continue;
            }
            $candles[] = [
                't' => (int) $t,
                'o' => (float) $q['open'][$k],
                'h' => (float) $q['high'][$k],
                'l' => (float) $q['low'][$k],
                'c' => (float) $q['close'][$k],
                'v' => ($q['volume'][$k] ?? null) !== null ? (float) $q['volume'][$k] : 0,
            ];
        }
        return $candles;
    }

    public static function search(string $query): array
    {
        $q = Validator::text($query, 40);
        if ($q === '') {
            return [];
        }
        $res = MarketHttpClient::get(self::BASE . '/v1/finance/search?q=' . rawurlencode($q)
            . '&quotesCount=12&newsCount=0', 8);
        if (!$res['ok'] || empty($res['data']['quotes'])) {
            return [];
        }
        $out = [];
        foreach ($res['data']['quotes'] as $item) {
            $sym = (string) ($item['symbol'] ?? '');
            if ($sym === '') { continue; }
            $type = (string) ($item['quoteType'] ?? '');
            $out[] = [
                'symbol' => $sym,
                'name' => $item['longname'] ?? ($item['shortname'] ?? $sym),
                'exchange' => $item['exchDisp'] ?? ($item['exchange'] ?? null),
                'type' => self::mapType($type),
            ];
        }
        return $out;
    }

    public static function getNews(string $query): array
    {
        $q = Validator::text($query, 60);
        if ($q === '') {
            return [];
        }
        $res = MarketHttpClient::get(self::BASE . '/v1/finance/search?q=' . rawurlencode($q)
            . '&quotesCount=0&newsCount=8', 8);
        if (!$res['ok'] || empty($res['data']['news'])) {
            return [];
        }
        $out = [];
        foreach ($res['data']['news'] as $n) {
            if (empty($n['title'])) { continue; }
            $out[] = [
                'title' => Validator::text($n['title'], 300),
                'publisher' => Validator::text($n['publisher'] ?? '', 80) ?: null,
                'link' => $n['link'] ?? null,
                'published_at' => isset($n['providerPublishTime']) ? (int) $n['providerPublishTime'] : null,
            ];
        }
        return $out;
    }

    private static function mapType(string $type): string
    {
        return [
            'EQUITY' => 'acao', 'ETF' => 'etf', 'CRYPTOCURRENCY' => 'cripto',
            'CURRENCY' => 'forex', 'INDEX' => 'index', 'FUTURE' => 'commodity',
            'MUTUALFUND' => 'fundo',
        ][strtoupper($type)] ?? 'outro';
    }
}
