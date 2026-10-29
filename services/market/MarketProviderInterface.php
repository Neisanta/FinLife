<?php
/**
 * FinLife — Contrato de provedores de market data (item 34).
 * Trocar de API = implementar esta interface; o resto do sistema
 * (cache, fallback, API, frontend) permanece intacto.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

interface MarketProviderInterface
{
    /** Nome curto exibido ao usuário (fonte do dado). */
    public static function name(): string;

    /** Atraso declarado dos dados, em segundos (0 = tempo real). */
    public static function delaySeconds(): int;

    /** Se o provedor está habilitado (chave configurada, etc.). */
    public static function available(): bool;

    /** Este provedor sabe responder por este símbolo? */
    public static function supports(string $symbol): bool;

    /**
     * Cotações normalizadas.
     * Retorna {symbol => [
     *   symbol, class, price, change_pct, day_change, day_high, day_low,
     *   day_open, prev_close, volume, volume_money, currency, market,
     *   delay_seconds, quote_time (epoch s), extra => array|null
     * ]}
     */
    public static function getQuotes(array $symbols): array;

    /**
     * Candles OHLCV normalizados: [['t' => epoch, 'o', 'h', 'l', 'c', 'v'], ...].
     * $interval: 5m|15m|30m|1h|1d|1wk  |  $range: 1d|5d|1mo|3mo|6mo|1y|5y|max
     */
    public static function getHistory(string $symbol, string $interval, string $range): array;

    /** Busca de ativos: [['symbol', 'name', 'exchange', 'type'], ...] */
    public static function search(string $query): array;

    /** Notícias: [['title', 'publisher', 'link', 'published_at'], ...] */
    public static function getNews(string $query): array;
}
