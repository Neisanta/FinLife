<?php
/** Mercado: notícias reais (?q=Petrobras). Fonte e horário sempre presentes. */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $q = (string) ($_GET['q'] ?? '');
    if (Validator::text($q, 60) === '') {
        json_out(['ok' => false, 'error' => 'Informe o termo de busca.'], 400);
    }
    $news = MarketDataService::getNews($q);
    json_out([
        'ok' => true,
        'query' => $q,
        'news' => $news,
        'source' => $news !== [] ? YahooProvider::name() : null,
        'note' => $news === [] ? 'Nenhuma notícia disponível no provedor atual para este termo.' : null,
    ]);
} catch (Throwable $e) {
    log_error('API market/news: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro ao carregar notícias.'], 500);
}
