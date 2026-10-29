<?php
/**
 * FinLife — Provedor Brapi (opcional).
 * Habilitado somente com BRAPI_TOKEN no .env (token gratuito em brapi.in).
 * Sem token, available() = false e o serviço o ignora.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class BrapiProvider implements MarketProviderInterface
{
    private const BASE = 'https://api.brapi.dev';

    public static function name(): string
    {
        return 'Brapi (B3)';
    }

    /** Dados B3 públicos com atraso (~15 min) — assumido igual ao mercado à vista. */
    public static function delaySeconds(): int
    {
        return 900;
    }

    public static function available(): bool
    {
        return Env::get('BRAPI_TOKEN', '') !== '';
    }

    public static function supports(string $symbol): bool
    {
        return self::available() && str_ends_with(strtoupper($symbol), '.SA');
    }

    public static function getQuotes(array $symbols): array
    {
        if (!self::available()) {
            return [];
        }
        $list = implode(',', array_map(static fn ($s) => str_replace('.SA', '', strtoupper((string) $s)), $symbols));
        $token = rawurlencode(Env::get('BRAPI_TOKEN', ''));
        $res = MarketHttpClient::get(self::BASE . '/v2/quote/' . $list . '?token=' . $token, 8);
        if (!$res['ok'] || empty($res['data']['quotes'])) {
            // tentativa v1 (formato legado)
            $res = MarketHttpClient::get(self::BASE . '/v1/quote/' . $list . '?token=' . $token, 8);
            if (!$res['ok'] || empty($res['data']['results'])) {
                return [];
            }
        }
        $items = $res['data']['quotes'] ?? ($res['data']['results'] ?? []);
        $out = [];
        foreach ($items as $q) {
            $base = (string) ($q['stock'] ?? ($q['symbol'] ?? ''));
            if ($base === '') {
                continue;
            }
            $sym = $base . '.SA';
            $price = $q['regularMarketPrice'] ?? null;
            if ($price === null) {
                continue;
            }
            $out[$sym] = [
                'symbol' => $sym,
                'class' => 'br',
                'price' => (float) $price,
                'change_pct' => isset($q['regularMarketChangePercent']) ? (float) $q['regularMarketChangePercent'] : null,
                'day_change' => isset($q['regularMarketChange']) ? (float) $q['regularMarketChange'] : null,
                'day_high' => isset($q['regularMarketDayHigh']) ? (float) $q['regularMarketDayHigh'] : null,
                'day_low' => isset($q['regularMarketDayLow']) ? (float) $q['regularMarketDayLow'] : null,
                'day_open' => isset($q['regularMarketOpen']) ? (float) $q['regularMarketOpen'] : null,
                'prev_close' => isset($q['regularMarketPreviousClose']) ? (float) $q['regularMarketPreviousClose'] : null,
                'volume' => isset($q['regularMarketVolume']) ? (float) $q['regularMarketVolume'] : null,
                'volume_money' => null,
                'currency' => 'BRL',
                'market' => 'B3 (via Brapi)',
                'delay_seconds' => self::delaySeconds(),
                'quote_time' => isset($q['regularMarketTime']) ? (int) $q['regularMarketTime'] : time(),
                'extra' => null,
            ];
        }
        return $out;
    }

    public static function getHistory(string $symbol, string $interval, string $range): array
    {
        return []; // histórico continua vindo do provedor primário
    }

    public static function search(string $query): array
    {
        return [];
    }

    public static function getNews(string $query): array
    {
        return [];
    }
}
