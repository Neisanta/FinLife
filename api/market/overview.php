<?php
/** Mercado: visão geral (home) — índices, câmbio, cripto + status. */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    json_out(MarketDataService::getOverview());
} catch (Throwable $e) {
    log_error('API market/overview: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro ao carregar o mercado. Tente novamente.'], 500);
}
