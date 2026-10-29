<?php
/**
 * FinLife — HTTP client para market data.
 * cURL multi (concorrência real) com fallback sequencial via streams.
 * Todas as respostas são JSON decodificado e validadas.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class MarketHttpClient
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 FinLife/1.0';

    /** GET único. Retorna ['ok'=>bool, 'data'=>array|null, 'status'=>int]. */
    public static function get(string $url, int $timeout = 8): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT => self::UA,
                CURLOPT_ACCEPT_ENCODING => '',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                return ['ok' => false, 'data' => null, 'status' => $status, 'error' => $err];
            }
            $json = json_decode((string) $raw, true);
            return ['ok' => is_array($json), 'data' => $json, 'status' => $status];
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => 'User-Agent: ' . self::UA . "\r\nAccept: application/json\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['ok' => false, 'data' => null, 'status' => 0, 'error' => 'stream failed'];
        }
        $json = json_decode((string) $raw, true);
        return ['ok' => is_array($json), 'data' => $json, 'status' => 200];
    }

    /**
     * GET concorrente (curl_multi). Retorna {url => resultado}.
     * Fallback: sequencial (usado quando cURL não existe).
     */
    public static function getMulti(array $urls, int $timeout = 8): array
    {
        if ($urls === []) {
            return [];
        }
        if (!function_exists('curl_multi_init')) {
            $out = [];
            foreach ($urls as $key => $url) {
                $out[$key] = self::get($url, $timeout);
            }
            return $out;
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT => self::UA,
                CURLOPT_ACCEPT_ENCODING => '',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.02);
            }
        } while ($running > 0);

        $out = [];
        foreach ($handles as $key => $ch) {
            $raw = curl_multi_getcontent($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $json = ($raw !== false && $raw !== null) ? json_decode((string) $raw, true) : null;
            $out[$key] = [
                'ok' => is_array($json),
                'data' => is_array($json) ? $json : null,
                'status' => $status,
                'error' => is_array($json) ? null : (string) curl_error($ch),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }
}
