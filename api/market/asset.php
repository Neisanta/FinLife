<?php
/**
 * Mercado: detalhe completo do ativo (?symbol=PETR4) —
 * cotação (fonte/timestamp/delay), histórico, notícias e extras.
 * Uma única chamada para o modal de detalhe.
 */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $symbol = MarketUniverse::normalize((string) ($_GET['symbol'] ?? ''));
    if ($symbol === '') {
        json_out(['ok' => false, 'error' => 'Informe o ativo.'], 400);
    }
    $range = Validator::in($_GET['range'] ?? '1M', ['1D', '5D', '1M', '3M', '6M', '1A', '5A', 'MAX']) ?? '1M';

    $quotes = MarketDataService::getQuotes([$symbol], $MARKET_USER_ID);
    $quote = $quotes[$symbol] ?? null;
    if ($quote === null) {
        json_out(['ok' => false, 'error' => 'Ativo não encontrado nas fontes de dados.'], 404);
    }

    // notícias: usa o nome "limpo" (sem ticker/sufixo) — busca mais precisa
    $newsQuery = $quote['label'] ?? $symbol;
    if (MarketUniverse::meta($symbol) !== null) {
        $newsQuery = preg_replace('~\s+[A-Za-z0-9.]*\d+[A-Za-z]*$~', '', $newsQuery) ?: $symbol;
        $newsQuery = preg_replace('~\s+(PN|ON|PNA|UNT|PSA)$~i', '', $newsQuery) ?: $newsQuery;
    }
    $news = MarketDataService::getNews($newsQuery);

    // extras de cripto (market cap, ATH, ranking) quando aplicável
    $extras = null;
    if ($quote['class'] === 'cripto') {
        $e = CoinGeckoProvider::getExtras([$symbol]);
        $base = strtoupper(preg_replace('~(USDT|USDC|FDUSD|BUSD)$~', '', $symbol));
        $extras = $e[$base] ?? null;
    }

    // indicadores derivados de dados REAIS (histórico) — nada inventado
    $derived = null;
    $hist1A = MarketDataService::getHistory($symbol, '1A');
    if (count($hist1A) >= 2) {
        $closes = array_map(static fn ($c) => $c['c'], $hist1A);
        $high52 = max($closes);
        $low52 = min($closes);
        // volatilidade anualizada simples (desvio padrão dos retornos diários)
        $returns = [];
        for ($i = 1; $i < count($closes); $i++) {
            if ($closes[$i - 1] > 0) {
                $returns[] = ($closes[$i] / $closes[$i - 1]) - 1;
            }
        }
        $vol = null;
        if (count($returns) > 20) {
            $mean = array_sum($returns) / count($returns);
            $var = array_sum(array_map(static fn ($r) => ($r - $mean) ** 2, $returns)) / count($returns);
            $vol = round(sqrt($var) * sqrt(252) * 100, 2); // % anualizado
        }
        $derived = [
            'high_52w' => round($high52, 4),
            'low_52w' => round($low52, 4),
            'volatility_annual_pct' => $vol,
            'series_points' => count($hist1A),
        ];
    }

    $candles = MarketDataService::getHistory($symbol, $range);

    json_out([
        'ok' => true,
        'quote' => $quote,
        'range' => $range,
        'candles' => $candles,
        'news' => $news,
        'news_query' => $newsQuery,
        'crypto_extra' => $extras,
        'derived' => $derived,
        'fundamentals_note' => 'Indicadores fundamentalistas (P/L, P/VP, ROE, DY...) não estão disponíveis '
            . 'nas fontes públicas gratuitas atuais. Configure BRAPI_TOKEN no .env para habilitar dados B3 detalhados.',
    ]);
} catch (Throwable $e) {
    log_error('API market/asset: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro ao carregar o ativo.'], 500);
}
