<?php
/**
 * FinLife — Provedor AwesomeAPI (câmbio comercial BRL, tempo real).
 * Fonte brasileira gratuita: USD-BRL, EUR-BRL, GBP-BRL, JPY-BRL etc.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class AwesomeApiProvider implements MarketProviderInterface
{
    private const BASE = 'https://economia.awesomeapi.com.br';
    private const PAIRS = ['USD-BRL', 'EUR-BRL', 'GBP-BRL', 'JPY-BRL', 'ARS-BRL', 'CAD-BRL', 'AUD-BRL', 'CHF-BRL', 'CNY-BRL'];

    public static function name(): string
    {
        return 'AwesomeAPI';
    }

    /** Cotação comercial com atualização contínua. */
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
        $s = strtoupper($symbol);
        if (preg_match('~^[A-Z]{3}-[A-Z]{3}$~', $s) === 1) {
            return true;
        }
        return in_array($s, ['USDBRL', 'EURBRL', 'GBPBRL', 'JPYBRL'], true);
    }

    /** "USDBRL" (Yahoo) → "USD-BRL". */
    public static function normalize(string $symbol): ?string
    {
        $s = strtoupper($symbol);
        if (preg_match('~^[A-Z]{3}-[A-Z]{3}$~', $s) === 1) {
            return $s;
        }
        if (preg_match('~^([A-Z]{3})BRL$~', $s, $m) === 1) {
            return $m[1] . '-BRL';
        }
        return null;
    }

    public static function getQuotes(array $symbols): array
    {
        $pairs = [];
        foreach ($symbols as $s) {
            $p = self::normalize((string) $s);
            if ($p !== null) {
                $pairs[$p] = true;
            }
        }
        if ($pairs === []) {
            return [];
        }
        $list = implode(',', array_keys($pairs));
        $res = MarketHttpClient::get(self::BASE . '/last/' . $list, 8);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [];
        }
        $out = [];
        foreach ($res['data'] as $key => $d) {
            $price = isset($d['bid']) ? (float) $d['bid'] : null;
            if ($price === null) {
                continue;
            }
            $out[$key] = [
                'symbol' => $key, // ex.: USDBRL
                'class' => 'forex',
                'price' => $price,
                'change_pct' => isset($d['pctChange']) ? (float) $d['pctChange'] : null,
                'day_change' => null,
                'day_high' => isset($d['high']) ? (float) $d['high'] : null,
                'day_low' => isset($d['low']) ? (float) $d['low'] : null,
                'day_open' => isset($d['open']) ? (float) $d['open'] : null,
                'prev_close' => null,
                'volume' => null,
                'volume_money' => null,
                'currency' => 'BRL',
                'market' => 'Câmbio comercial',
                'delay_seconds' => 0,
                'quote_time' => isset($d['timestamp']) ? (int) $d['timestamp'] : time(),
                'extra' => [
                    'pair' => str_replace('BRL', '', $key) . '/BRL',
                    'name' => $d['name'] ?? null,
                ],
            ];
        }
        return $out;
    }

    /** Histórico diário real dos últimos N dias (endpoint público). */
    public static function getHistory(string $symbol, string $interval, string $range): array
    {
        $pair = self::normalize($symbol);
        if ($pair === null) {
            return [];
        }
        $days = ['1D' => 5, '5D' => 10, '1M' => 30, '3M' => 90, '6M' => 180, '1A' => 365, '5A' => 1825, 'MAX' => 3650][strtoupper($range)] ?? 30;
        $res = MarketHttpClient::get(self::BASE . '/json/daily/' . $pair . '/' . min(365, $days), 10);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [];
        }
        $candles = [];
        foreach ($res['data'] as $d) {
            if (!isset($d['timestamp'])) {
                continue;
            }
            $candles[] = [
                't' => (int) $d['timestamp'],
                'o' => (float) $d['open'],
                'h' => (float) $d['high'],
                'l' => (float) $d['low'],
                'c' => (float) $d['close'],
                'v' => (float) ($d['volume'] ?? 0),
            ];
        }
        return $candles;
    }

    public static function search(string $query): array
    {
        $q = strtoupper(Validator::text($query, 12));
        $out = [];
        foreach (self::PAIRS as $p) {
            if ($q === '' || str_contains($p, $q)) {
                $out[] = ['symbol' => $p, 'name' => str_replace('-', '/', $p), 'exchange' => 'AwesomeAPI', 'type' => 'forex'];
            }
        }
        return array_slice($out, 0, 12);
    }

    public static function getNews(string $query): array
    {
        return [];
    }
}
