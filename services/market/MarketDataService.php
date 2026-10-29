<?php
/**
 * FinLife — Orquestrador de market data.
 *
 * Frontend → APIs /api/market/* → MarketDataService → Provedores → Cache.
 *
 * Regras invioláveis:
 * - Nenhuma cotação é inventada; toda quote carrega provedor, timestamp
 *   do dado, delay declarado e flag de tempo real.
 * - Fallback em cadeia: provedor primário → secundário → cache stale
 *   (marcado is_stale, nunca apresentado como atual).
 * - Rate limit respeitoso por provedor; cache read-through com TTL por classe.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class MarketDataService
{
    /** Provedores por classe, em ordem de fallback. */
    public static function providersFor(string $symbol): array
    {
        $sym = strtoupper($symbol);
        if (BinanceProvider::supports($sym) && preg_match('~^(BTC|ETH|SOL|XRP|BNB|ADA|DOGE|USDT|USDC|LTC|AVAX|DOT|LINK|ATOM|MATIC|TRX)~', $sym) === 1) {
            return ['primary' => BinanceProvider::class, 'fallback' => CoinGeckoProvider::class];
        }
        if (AwesomeApiProvider::supports($sym) && preg_match('~^[A-Z]{3}BRL$~', $sym) === 1) {
            return ['primary' => AwesomeApiProvider::class, 'fallback' => YahooProvider::class];
        }
        return ['primary' => YahooProvider::class, 'fallback' => BrapiProvider::class];
    }

    /**
     * Cotações normalizadas (com cache, fallback e conversão para BRL).
     * Retorna {symbol => quote}.
     */
    public static function getQuotes(array $symbols, ?int $userId = null): array
    {
        $symbols = array_values(array_unique(array_map(
            static fn ($s) => MarketUniverse::normalize((string) $s),
            array_filter($symbols, static fn ($s) => Validator::text((string) $s, 30) !== '')
        )));
        if ($symbols === []) {
            return [];
        }

        $cached = MarketCacheService::getQuotes($symbols);
        $result = $cached['fresh'];
        $missing = array_values(array_diff($symbols, array_keys($result)));

        if ($missing !== []) {
            // agrupa por provedor primário (uma chamada por provedor)
            $byProvider = [];
            foreach ($missing as $sym) {
                $p = self::providersFor($sym)['primary'];
                $byProvider[$p][] = $sym;
            }
            foreach ($byProvider as $providerClass => $syms) {
                if (self::throttled($providerClass)) {
                    continue; // protege contra estouro de rate limit — cache assume
                }
                $quotes = $providerClass::getQuotes($syms);
                foreach ($quotes as $q) {
                    $q['provider'] = $providerClass::name();
                    $result[$q['symbol']] = $q;
                }
            }

            // fallback para os que continuaram faltando
            $stillMissing = array_values(array_diff($missing, array_keys($result)));
            foreach ($stillMissing as $sym) {
                $fb = self::providersFor($sym)['fallback'];
                if ($fb === null || self::throttled($fb)) {
                    continue;
                }
                $quotes = $fb::getQuotes([$sym]);
                foreach ($quotes as $q) {
                    $q['provider'] = $fb::name();
                    $q['symbol'] = $sym;
                    $result[$sym] = $q;
                }
            }

            // salva os obtidos no cache
            foreach ($result as $q) {
                if (!isset($cached['fresh'][$q['symbol']])) {
                    MarketCacheService::saveQuote($q);
                }
            }
        }

        // stale só entra quando não há fresco
        foreach ($cached['stale'] as $sym => $q) {
            if (!isset($result[$sym])) {
                $result[$sym] = $q;
            }
        }

        // enriquecimento: metadados do universo + BRL para ativos em USD
        $usdbrl = null;
        foreach ($result as $sym => $q) {
            if ($sym === 'USDBRL' && !($q['is_stale'] ?? false)) {
                $usdbrl = (float) $q['price'];
            }
        }
        // ativos em USD fora do lote (ex.: modal aberto direto no BTC):
        // usa a cotação do câmbio em CACHE (sem custo de rede) para converter
        if ($usdbrl === null) {
            foreach ($result as $q) {
                if (($q['currency'] ?? null) === 'USD') {
                    $fx = MarketCacheService::getQuotes(['USDBRL'])['fresh']['USDBRL'] ?? null;
                    if ($fx !== null) {
                        $usdbrl = (float) $fx['price'];
                    }
                    break;
                }
            }
        }
        foreach ($result as $sym => &$q) {
            $q['is_stale'] = $q['is_stale'] ?? false;
            $q['fetched_at'] = $q['fetched_at'] ?? date('Y-m-d H:i:s');
            $meta = MarketUniverse::meta($sym);
            if ($meta !== null) {
                $q['label'] = $meta['label'];
                $q['class'] = $meta['class'] !== 'index' || $q['class'] === 'index' ? $meta['class'] : $q['class'];
                $q['decimals'] = $meta['decimals'];
                if (isset($meta['sector'])) { $q['sector'] = $meta['sector']; }
                if (isset($meta['segment'])) { $q['segment'] = $meta['segment']; }
                if (isset($meta['benchmark'])) { $q['benchmark'] = $meta['benchmark']; }
                if (isset($meta['country'])) { $q['country'] = $meta['country']; }
            } else {
                $q['label'] = MarketUniverse::display($sym);
                $q['decimals'] = 2;
            }
            // conversão REAL para BRL quando a cotação é em USD (cripto/US)
            if ($q['currency'] === 'USD' && $usdbrl !== null && $sym !== 'USDBRL') {
                $q['price_original'] = $q['price'];
                $q['currency_original'] = 'USD';
                $q['price'] = round($q['price'] * $usdbrl, 2);
                $q['currency'] = 'BRL';
                $q['extra']['fx_rate'] = $usdbrl;
            }
            $q['display'] = MarketUniverse::display($sym);
            $q['live'] = (int) $q['delay_seconds'] === 0 && !$q['is_stale'];
        }
        unset($q);

        if ($userId !== null) {
            self::checkAlerts($userId, $result);
        }
        return $result;
    }

    /** Candles com cache por período. */
    public static function getHistory(string $symbol, string $range): array
    {
        $sym = MarketUniverse::normalize($symbol);
        $range = strtoupper(Validator::in($range, ['1D', '5D', '1M', '3M', '6M', '1A', '5A', 'MAX']) ?? '1M');
        $key = 'hist|' . strtolower($sym) . '|' . $range;
        $cached = MarketCacheService::getPayload($key, MarketCacheService::historyTtl($range));
        if ($cached !== null) {
            return $cached;
        }
        $providers = self::providersFor($sym);
        $candles = $providers['primary']::getHistory($sym, '', $range);
        if ($candles === [] && $providers['fallback'] !== null) {
            $candles = $providers['fallback']::getHistory($sym, '', $range);
        }
        if ($candles !== []) {
            MarketCacheService::savePayload($key, $candles);
        }
        return $candles;
    }

    /** Busca combinando universo + provedores. Cache 1h. */
    public static function search(string $query): array
    {
        $q = Validator::text($query, 40);
        if ($q === '') {
            return [];
        }
        $key = 'search|' . strtolower($q);
        $cached = MarketCacheService::getPayload($key, 3600);
        if ($cached !== null) {
            return $cached;
        }
        $out = [];
        $seen = [];

        // universo primeiro (nomes amigáveis conhecidos)
        foreach (MarketUniverse::all() as $class => $list) {
            foreach ($list as $item) {
                if (stripos($item['label'] . ' ' . $item['symbol'], $q) !== false) {
                    $seen[$item['symbol']] = true;
                    $out[] = [
                        'symbol' => $item['symbol'],
                        'name' => $item['label'],
                        'class' => $item['class'],
                        'exchange' => 'Universo FinLife',
                    ];
                }
            }
        }
        foreach (YahooProvider::search($q) as $r) {
            if (!isset($seen[$r['symbol']])) {
                $seen[$r['symbol']] = true;
                $r['class'] = self::refineClass($r['symbol'], $r['type'] ?? '');
                $out[] = $r;
            }
        }
        $out = array_slice($out, 0, 20);
        MarketCacheService::savePayload($key, $out);
        return $out;
    }

    /** Notícias reais (título, fonte, horário). Cache 30min. */
    public static function getNews(string $query): array
    {
        $q = Validator::text($query, 60);
        if ($q === '') {
            return [];
        }
        $key = 'news|' . strtolower($q);
        $cached = MarketCacheService::getPayload($key, 1800);
        if ($cached !== null) {
            return $cached;
        }
        $news = YahooProvider::getNews($q);
        if ($news !== []) {
            MarketCacheService::savePayload($key, $news);
        }
        return $news;
    }

    /** Maiores altas / quedas / volumes de um universo real. */
    public static function getMovers(string $classKey, int $limit = 10): array
    {
        $universe = MarketUniverse::all();
        if (!isset($universe[$classKey])) {
            return [];
        }
        $symbols = array_column($universe[$classKey], 'symbol');
        $quotes = self::getQuotes($symbols);
        $withPct = array_values(array_filter($quotes, static fn ($q) => $q['change_pct'] !== null && !$q['is_stale']));
        usort($withPct, static fn ($a, $b) => $b['change_pct'] <=> $a['change_pct']);

        $gainers = array_slice($withPct, 0, $limit);
        $losers = array_slice(array_reverse($withPct), 0, $limit);

        $withVol = array_values(array_filter($quotes, static fn ($q) => $q['volume_money'] !== null && $q['volume_money'] > 0));
        usort($withVol, static fn ($a, $b) => $b['volume_money'] <=> $a['volume_money']);
        $volumes = array_slice($withVol, 0, $limit);

        return [
            'class' => $classKey,
            'class_label' => MarketUniverse::CLASS_LABELS[$classKey] ?? $classKey,
            'gainers' => $gainers,
            'losers' => $losers,
            'volumes' => $volumes,
            'unavailable' => count($quotes) === 0,
        ];
    }

    /** Home do mercado: índices, câmbio e cripto — tudo com fonte/timestamp. */
    public static function getOverview(): array
    {
        $quotes = self::getQuotes(array_column(MarketUniverse::HOME, 'symbol'));
        return [
            'ok' => true,
            'quotes' => array_values(array_filter($quotes, static fn ($q) => !$q['is_stale'] || true)),
            'server_time' => time(),
            'limitations' => [
                'b3_delay' => 'Cotações da B3 com atraso de até 15 minutos (fonte pública).',
                'crypto' => 'Criptomoedas em tempo real via Binance; WebSocket disponível no navegador.',
            ],
        ];
    }

    /** @var array Mensagens de alertas disparados na requisição atual. */
    private static array $lastTriggered = [];

    /** Alertas disparados na requisição corrente (consumido pelas APIs para notificar o frontend). */
    public static function pullTriggered(): array
    {
        $t = self::$lastTriggered;
        self::$lastTriggered = [];
        return $t;
    }

    /** Dispara alertas do usuário cujo alvo foi atingido (notificação no sistema). */
    public static function checkAlerts(int $userId, array $quotes): void
    {
        $alerts = Database::run(
            "SELECT * FROM market_alerts WHERE user_id = :uid AND status = 'active'
             AND (last_notified_at IS NULL OR last_notified_at < :cutoff)",
            ['uid' => $userId, 'cutoff' => date('Y-m-d H:i:s', time() - 300)]
        )->fetchAll();
        foreach ($alerts as $a) {
            $sym = (string) $a['symbol'];
            if (!isset($quotes[$sym]) || ($quotes[$sym]['is_stale'] ?? false)) {
                continue;
            }
            $price = (float) $quotes[$sym]['price'];
            $pct = (float) ($quotes[$sym]['change_pct'] ?? 0);
            $target = (float) $a['target'];
            $hit = false;
            $dir = '';
            switch ($a['type']) {
                case 'above':     $hit = $price >= $target; $dir = 'atingiu'; break;
                case 'below':     $hit = $price <= $target; $dir = 'caiu abaixo de'; break;
                case 'pct_up':    $hit = $pct >= $target; $dir = 'subiu'; break;
                case 'pct_down':  $hit = $pct <= -$target; $dir = 'variação caiu além de'; break;
            }
            if (!$hit) {
                continue;
            }
            $display = MarketUniverse::display($sym);
            $isPct = in_array($a['type'], ['pct_up', 'pct_down'], true);
            $valueTxt = $isPct
                ? number_format(abs($target), 1, ',', '.') . '%'
                : 'R$ ' . number_format($target, 2, ',', '.');
            $message = $display . ' ' . $dir . ' ' . $valueTxt . ' — cotação atual: R$ '
                . number_format($price, 2, ',', '.') . ' (às ' . strtoupper(date('H:i', (int) $quotes[$sym]['quote_time']))
                . ', fonte: ' . $quotes[$sym]['provider'] . ').';

            Database::run(
                "UPDATE market_alerts SET status = 'triggered', triggered_at = NOW(),
                 last_notified_at = NOW(), trigger_price = :price WHERE id = :id",
                ['price' => $price, 'id' => (int) $a['id']]
            );
            Database::run(
                'INSERT INTO notifications (user_id, type, title, message)
                 VALUES (:uid, :type, :title, :msg)',
                [
                    'uid' => $userId,
                    'type' => 'market_alert',
                    'title' => 'Alerta de preço: ' . $display,
                    'msg' => $message,
                ]
            );
            self::$lastTriggered[] = $message;
        }
    }

    /** Throttle por provedor: protege os limites das APIs públicas. */
    private static function throttled(string $providerClass): bool
    {
        $limit = $providerClass === YahooProvider::class ? 80 : ($providerClass === BinanceProvider::class ? 60 : 30);
        return RateLimiter::hit('market:' . strtolower(basename(str_replace('\\', '/', $providerClass))), $limit, 60);
    }

    /** Refina a classe com heurística de ticker B3. */
    private static function refineClass(string $symbol, string $type): string
    {
        $meta = MarketUniverse::meta($symbol);
        if ($meta !== null) {
            return $meta['class'];
        }
        if ($type === 'cripto') { return 'cripto'; }
        if ($type === 'forex') { return 'forex'; }
        if ($type === 'index') { return 'index'; }
        if ($type === 'commodity') { return 'commodity'; }
        if (str_ends_with($symbol, '.SA')) {
            if (preg_match('~^[A-Z]{4}(11|12)$~', str_replace('.SA', '', $symbol)) === 1) { return 'fii'; }
            return 'br_stock';
        }
        return 'us_stock';
    }
}
