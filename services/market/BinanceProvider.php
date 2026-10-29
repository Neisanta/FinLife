<?php
/**
 * FinLife — Provedor Binance (cripto, tempo real).
 * API pública de market data — sem chave, sem autenticação.
 * Pares USDT. O frontend conecta o WebSocket público da Binance para
 * atualização contínua; este provider é a fonte REST/fallback.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class BinanceProvider implements MarketProviderInterface
{
    private const BASE = 'https://api.binance.com';
    /** Moedas-mercado aceitas (par cripto). */
    private const QUOTES = ['USDT', 'USDC', 'FDUSD', 'BUSD'];

    public static function name(): string
    {
        return 'Binance';
    }

    /** Dados de mercado públicos da Binance são tempo real. */
    public static function delaySeconds(): int
    {
        return 0;
    }

    public static function available(): bool
    {
        return true;
    }

    public static function supports(string $symbol): bool
    {
        $symbol = strtoupper($symbol);
        foreach (self::QUOTES as $q) {
            if (str_ends_with($symbol, $q) && strlen($symbol) > strlen($q)) {
                return true;
            }
        }
        return false;
    }

    /** Converte "BTC" → "BTCUSDT" quando o usuário digita só a base. */
    public static function normalize(string $symbol): string
    {
        $symbol = strtoupper($symbol);
        if (self::supports($symbol)) {
            return $symbol;
        }
        if (preg_match('~^[A-Z0-9]{2,10}$~', $symbol) === 1) {
            return $symbol . 'USDT';
        }
        return $symbol;
    }

    public static function getQuotes(array $symbols): array
    {
        $pairs = array_values(array_unique(array_map(static fn ($s) => self::normalize((string) $s), $symbols)));
        if ($pairs === []) {
            return [];
        }
        $query = rawurlencode(json_encode($pairs));
        $res = MarketHttpClient::get(self::BASE . '/api/v3/ticker/24hr?symbols=' . $query, 8);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [];
        }
        $out = [];
        foreach ($res['data'] as $t) {
            $sym = (string) ($t['symbol'] ?? '');
            if ($sym === '') { continue; }
            $prevClose = isset($t['prevClosePrice']) ? (float) $t['prevClosePrice'] : null;
            $price = (float) $t['lastPrice'];
            $change = isset($t['priceChange']) ? (float) $t['priceChange'] : null;
            $pct = isset($t['priceChangePercent']) ? (float) $t['priceChangePercent'] : null;
            $out[$sym] = [
                'symbol' => $sym,
                'class' => 'cripto',
                'price' => $price,
                'change_pct' => $pct,
                'day_change' => $change,
                'day_high' => isset($t['highPrice']) ? (float) $t['highPrice'] : null,
                'day_low' => isset($t['lowPrice']) ? (float) $t['lowPrice'] : null,
                'day_open' => isset($t['openPrice']) ? (float) $t['openPrice'] : null,
                'prev_close' => $prevClose,
                'volume' => isset($t['volume']) ? (float) $t['volume'] : null,
                'volume_money' => isset($t['quoteVolume']) ? (float) $t['quoteVolume'] : null,
                'currency' => self::quoteOf($sym),
                'market' => 'Binance',
                'delay_seconds' => 0,
                'quote_time' => isset($t['closeTime']) ? intdiv((int) $t['closeTime'], 1000) : time(),
                'extra' => [
                    'bid' => isset($t['bidPrice']) ? (float) $t['bidPrice'] : null,
                    'ask' => isset($t['askPrice']) ? (float) $t['askPrice'] : null,
                    'trades' => isset($t['count']) ? (int) $t['count'] : null,
                ],
            ];
        }
        return $out;
    }

    public static function getHistory(string $symbol, string $interval, string $range): array
    {
        $pair = self::normalize($symbol);
        $map = [
            '1D' => ['5m', 288], '5D' => ['30m', 240], '1M' => ['4h', 180],
            '3M' => ['4h', 540], '6M' => ['12h', 360], '1A' => ['1d', 365],
            '5A' => ['1wk', 260], 'MAX' => ['1w', 1000],
        ];
        $key = strtoupper($range);
        if (!isset($map[$key])) {
            $key = '1M';
        }
        [$bi, $limit] = $map[$key];
        $res = MarketHttpClient::get(self::BASE . '/api/v3/klines?symbol=' . $pair
            . '&interval=' . $bi . '&limit=' . $limit, 12);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [];
        }
        $candles = [];
        foreach ($res['data'] as $k) {
            $candles[] = [
                't' => intdiv((int) $k[0], 1000),
                'o' => (float) $k[1],
                'h' => (float) $k[2],
                'l' => (float) $k[3],
                'c' => (float) $k[4],
                'v' => (float) $k[5],
            ];
        }
        return $candles;
    }

    public static function search(string $query): array
    {
        // busca local por pares conhecidos (a Binance não tem endpoint de busca pública textual)
        $known = ['BTCUSDT' => 'Bitcoin', 'ETHUSDT' => 'Ethereum', 'SOLUSDT' => 'Solana', 'XRPUSDT' => 'XRP',
            'BNBUSDT' => 'BNB', 'ADAUSDT' => 'Cardano', 'DOGEUSDT' => 'Dogecoin', 'USDTUSDT' => 'Tether',
            'USDCUSDT' => 'USD Coin', 'LTCUSDT' => 'Litecoin', 'AVAXUSDT' => 'Avalanche', 'DOTUSDT' => 'Polkadot',
            'LINKUSDT' => 'Chainlink', 'MATICUSDT' => 'Polygon', 'ATOMUSDT' => 'Cosmos'];
        $q = strtoupper(Validator::text($query, 12));
        $out = [];
        foreach ($known as $sym => $name) {
            if ($q === '' || str_contains($sym, $q) || str_contains(strtoupper($name), $q)) {
                $out[] = ['symbol' => $sym, 'name' => $name, 'exchange' => 'Binance', 'type' => 'cripto'];
            }
        }
        return array_slice($out, 0, 12);
    }

    public static function getNews(string $query): array
    {
        return []; // sem endpoint público de notícias — o serviço usa o Yahoo
    }

    private static function quoteOf(string $pair): string
    {
        foreach (self::QUOTES as $q) {
            if (str_ends_with($pair, $q)) {
                return $q === 'USDT' || $q === 'USDC' || $q === 'BUSD' ? 'USD' : $q;
            }
        }
        return 'USD';
    }
}
