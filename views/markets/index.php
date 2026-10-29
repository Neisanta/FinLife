<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
$icoPlus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
?>
<script>
window.FL_MARKET_UNIVERSE = <?= json_encode(MarketUniverse::all(), JSON_UNESCAPED_UNICODE) ?>;
</script>
<div class="page-head">
    <p class="muted">Central de mercados em tempo real — ações, FIIs, ETFs, cripto, câmbio e commodities. Cada cotação mostra fonte, horário e atraso.</p>
    <div class="page-head-actions">
        <span class="market-status" id="market-status" title="Status da conexão com os mercados">
            <span class="ms-dot"></span>
            <span class="ms-label">Conectando…</span>
            <span class="ms-time muted" id="market-last-update"></span>
        </span>
    </div>
</div>

<section class="mkt-tiles" id="mkt-tiles" aria-label="Panorama do mercado"></section>

<nav class="tabs" id="markets-tabs" role="tablist" aria-label="Seções de mercados">
    <button type="button" class="tab-link active" data-tab="overview" role="tab">Visão geral</button>
    <button type="button" class="tab-link" data-tab="explore" role="tab">Explorar ativos</button>
    <button type="button" class="tab-link" data-tab="movers" role="tab">Altas & quedas</button>
    <button type="button" class="tab-link" data-tab="watchlist" role="tab">Watchlist</button>
    <button type="button" class="tab-link" data-tab="alerts" role="tab">Alertas</button>
    <button type="button" class="tab-link" data-tab="portfolio" role="tab">Minha carteira</button>
</nav>

<!-- ==================== VISÃO GERAL ==================== -->
<section class="tab-panel active" id="tab-overview" role="tabpanel">
    <section class="grid-2">
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>Índices</h2><span class="muted">Brasil e mundo — atraso de até 15 min (fonte pública)</span></div></div>
            <div id="ov-indices" class="stack"></div>
        </article>
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>Câmbio e commodities</h2><span class="muted">Câmbio comercial em tempo real</span></div></div>
            <div id="ov-forex" class="stack"></div>
        </article>
    </section>
    <section class="card">
        <div class="card-head">
            <div class="card-head-text"><h2>Criptomoedas</h2><span class="muted">Tempo real via Binance — WebSocket quando disponível</span></div>
            <span class="livedot live" id="crypto-live-indicator">Ao vivo</span>
        </div>
        <div id="ov-crypto" class="stack"></div>
    </section>
</section>

<!-- ==================== EXPLORAR ==================== -->
<section class="tab-panel" id="tab-explore" role="tabpanel">
    <section class="toolbar">
        <div class="search-wrap">
            <label for="mk-search">Buscar ativo</label>
            <input type="search" id="mk-search" class="input" placeholder="Ticker, nome ou empresa… (ex.: PETR4, Bitcoin)" autocomplete="off">
            <div class="search-dropdown" id="mk-search-dropdown"></div>
        </div>
        <div class="filter" style="flex:0">
            <label>Classe</label>
            <div class="class-chips" id="mk-class-chips" style="align-self:center"></div>
        </div>
    </section>
    <section class="card table-card">
        <div class="table-wrap">
            <table class="table asset-table" id="mk-asset-table">
                <thead>
                    <tr>
                        <th>Ativo</th>
                        <th>Nome</th>
                        <th class="right">Preço</th>
                        <th class="right">Variação</th>
                        <th class="right">Volume (R$)</th>
                        <th>Fonte · atraso</th>
                    </tr>
                </thead>
                <tbody id="mk-asset-tbody"><tr><td colspan="6" class="empty-cell">Carregando…</td></tr></tbody>
            </table>
        </div>
        <div class="table-foot"><span class="muted small" id="mk-asset-count"></span></div>
    </section>
</section>

<!-- ==================== ALTAS & QUEDAS ==================== -->
<section class="tab-panel" id="tab-movers" role="tabpanel">
    <section class="toolbar">
        <div class="filter" style="flex:0">
            <label>Universo</label>
            <div class="class-chips movers-chips" id="mv-class-chips"></div>
        </div>
    </section>
    <section class="movers-grid">
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>▲ Maiores altas</h2><span class="muted" id="mv-gainers-note"></span></div></div>
            <div class="mover-list" id="mv-gainers"></div>
        </article>
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>▼ Maiores quedas</h2><span class="muted" id="mv-losers-note"></span></div></div>
            <div class="mover-list" id="mv-losers"></div>
        </article>
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>≡ Maiores volumes</h2><span class="muted">Volume financeiro negociado no dia</span></div></div>
            <div class="mover-list" id="mv-volumes"></div>
        </article>
    </section>
    <section class="card">
        <div class="card-head">
            <div class="card-head-text"><h2>Mapa de calor</h2><span class="muted">Cor = variação do dia · tamanho = volume financeiro</span></div>
        </div>
        <div class="heatmap" id="mv-heatmap"></div>
        <p class="heat-note">Cotações reais com atraso de até 15 minutos (B3). Clique em um ativo para ver o detalhe.</p>
    </section>
</section>

<!-- ==================== WATCHLIST ==================== -->
<section class="tab-panel" id="tab-watchlist" role="tabpanel">
    <section class="toolbar">
        <div class="search-wrap">
            <label for="wl-add-input">Adicionar ativo</label>
            <input type="search" id="wl-add-input" class="input" placeholder="Ex.: PETR4, BTC, HGLG11…" autocomplete="off">
            <div class="search-dropdown" id="wl-add-dropdown"></div>
        </div>
        <div class="page-head-actions">
            <button class="btn btn-ghost" id="wl-new-list"><?= $icoPlus ?> Nova lista</button>
        </div>
    </section>
    <div class="wl-header">
        <div class="wl-tabs" id="wl-tabs"></div>
    </div>
    <div id="wl-items"></div>
</section>

<!-- ==================== ALERTAS ==================== -->
<section class="tab-panel" id="tab-alerts" role="tabpanel">
    <section class="toolbar">
        <p class="muted small" style="flex:1">Os alertas são avaliados sempre que o FinLife recebe cotações — e notificados aqui no sistema. Ex.: "PETR4 acima de R$ 40".</p>
        <div class="page-head-actions">
            <button class="btn btn-primary" id="al-new"><?= $icoPlus ?> Novo alerta</button>
        </div>
    </section>
    <div id="al-list"></div>
</section>

<!-- ==================== CARTEIRA ==================== -->
<section class="tab-panel" id="tab-portfolio" role="tabpanel">
    <section class="toolbar">
        <p class="muted small" style="flex:1">Registre compras e vendas — o preço médio (com taxas) e a rentabilidade são calculados com cotações reais.</p>
        <div class="page-head-actions">
            <button class="btn btn-ghost" id="pf-add-dividend">Registrar dividendo</button>
            <button class="btn btn-primary" id="pf-add-tx"><?= $icoPlus ?> Registrar operação</button>
        </div>
    </section>

    <section class="pf-grid" id="pf-stats"></section>

    <section class="grid-2">
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>Alocação da carteira</h2><span class="muted">Distribuição por classe de ativo</span></div></div>
            <div id="pf-allocation"></div>
        </article>
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>Performance vs. mercado</h2><span class="muted" id="pf-bench-period"></span></div></div>
            <div id="pf-benchmark"></div>
        </article>
    </section>

    <section class="card">
        <div class="card-head"><div class="card-head-text"><h2>Posições</h2><span class="muted">Preço atual com fonte e horário da cotação</span></div></div>
        <div class="table-wrap">
            <table class="table asset-table">
                <thead>
                    <tr>
                        <th>Ativo</th>
                        <th>Classe</th>
                        <th class="right">Qtd.</th>
                        <th class="right">Preço médio</th>
                        <th class="right">Preço atual</th>
                        <th class="right">Valor</th>
                        <th class="right">Lucro</th>
                        <th class="right">Rentab.</th>
                        <th>Fonte</th>
                    </tr>
                </thead>
                <tbody id="pf-positions"><tr><td colspan="9" class="empty-cell">Carregando…</td></tr></tbody>
            </table>
        </div>
    </section>

    <section class="grid-2">
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>Operações</h2><span class="muted" id="pf-tx-count"></span></div></div>
            <div id="pf-transactions"></div>
        </article>
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>Dividendos e proventos</h2><span class="muted" id="pf-div-summary"></span></div></div>
            <div id="pf-dividends"></div>
        </article>
    </section>
</section>
