/* ==============================================================
   FinLife — Página Mercados
   Tempo real: WebSocket público da Binance (cripto) com reconexão
   automática + fallback REST. Demais classes: polling respeitoso
   com cache no backend. Fonte/timestamp/atraso sempre visíveis.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL;
    if (!FL) { return; }

    var API = '/api/market/';
    var state = {
        tab: 'overview',
        quotes: {},            // symbol => quote (fonte de verdade da UI)
        class: 'br_stock',     // aba explorar
        moversClass: 'br_stock',
        watchlists: [],
        activeWatchlist: null,
        loaded: {},
        assetModal: { symbol: null, range: '1M', type: 'area', data: [] },
    };

    // ------------------------------------------------------------------
    // Formatação
    // ------------------------------------------------------------------
    function $(id) { return document.getElementById(id); }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }
    function fmtPrice(q) {
        if (!q) { return '—'; }
        var dec = q.decimals !== undefined ? q.decimals : 2;
        var cur = q.currency === 'USD' ? 'US$ ' : 'R$ ';
        return cur + Number(q.price).toLocaleString('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }
    function fmtPct(v) {
        if (v === null || v === undefined) { return '—'; }
        return (v > 0 ? '+' : '') + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
    }
    function pctClass(v) { return (v !== null && v < 0) ? 'down' : 'up'; }
    function ageText(q) {
        if (!q) { return ''; }
        var ref = q.fetched_at ? Math.floor(new Date(q.fetched_at + 'Z').getTime() / 1000) : q.quote_time;
        var s = Math.max(0, Math.floor(Date.now() / 1000) - ref);
        if (s < 60) { return 'há ' + s + 's'; }
        if (s < 3600) { return 'há ' + Math.floor(s / 60) + ' min'; }
        return 'há ' + Math.floor(s / 3600) + 'h';
    }
    function liveBadge(q) {
        if (!q) { return ''; }
        if (q.is_stale) { return '<span class="livedot delayed">dados antigos</span>'; }
        return q.live ? '<span class="livedot live">ao vivo</span>'
            : '<span class="livedot delayed">atraso ' + Math.round(q.delay_seconds / 60) + ' min</span>';
    }
    function toastTriggered(list) {
        (list || []).forEach(function (msg) { FL.toast(msg, 'info', 8000); });
    }

    // ------------------------------------------------------------------
    // Atualização de elementos ligados a quotes — GRANULAR:
    // o DOM nunca é reconstruído em updates; apenas textContent de campos
    // que realmente mudaram (zero flicker). Flash discreto de alta/baixa.
    // ------------------------------------------------------------------

    /** Escreve texto apenas quando difere do atual (evita reflow/re-render). */
    function setText(el, txt) {
        if (!el || el.textContent === txt) { return; }
        el.textContent = txt;
    }

    /** Flash discreto de direção: cor temporária no preço, sem piscar. */
    var flashTimers = {};
    function flashPrice(el, direction, key) {
        if (!el || !direction) { return; }
        var k = key || Math.random().toString(36).slice(2);
        el.classList.remove('flash-up', 'flash-down');
        // força reflow para reiniciar a transição quando o tick repete
        void el.offsetWidth;
        el.classList.add(direction > 0 ? 'flash-up' : 'flash-down');
        clearTimeout(flashTimers[k]);
        flashTimers[k] = setTimeout(function () {
            el.classList.remove('flash-up', 'flash-down');
        }, 700);
    }

    function applyQuote(q) {
        if (!q) { return; }
        var prev = state.quotes[q.symbol];
        var direction = (prev && prev.price !== undefined && q.price !== prev.price)
            ? (q.price > prev.price ? 1 : -1) : 0;
        state.quotes[q.symbol] = q;
        // tiles
        var tile = document.querySelector('.mkt-tile[data-symbol="' + CSS.escape(q.symbol) + '"]');
        if (tile) { updateTile(tile, q, direction); }
        // tabelas da aba explorer
        var row = document.querySelector('.asset-table tr[data-symbol="' + CSS.escape(q.symbol) + '"]');
        if (row) { updateAssetRow(row, q); }
        // watchlist
        var wlq = document.querySelector('.wl-item[data-symbol="' + CSS.escape(q.symbol) + '"]');
        if (wlq) { updateWlItem(wlq, q); }
        // modal do ativo
        if (state.assetModal.symbol === q.symbol && $('asset-modal-price')) {
            renderAssetModalQuote(q, direction);
        }
    }

    /** Garante a estrutura interna do meta UMA vez; updates só mudam texto. */
    function ensureTileMeta(tile) {
        var meta = tile.querySelector('.mt-meta');
        if (!meta || meta.dataset.built === '1') { return meta; }
        meta.dataset.built = '1';
        meta.innerHTML =
            '<span class="mt-live"></span>'
            + '<span class="mt-sep">·</span>'
            + '<span class="data-age" data-age=""></span>'
            + '<span class="mt-sep">·</span>'
            + '<span class="mt-src"></span>';
        return meta;
    }

    function updateTile(tile, q, direction) {
        var price = tile.querySelector('.mt-price');
        if (price) {
            setText(price, fmtPrice(q));
            if (direction) { flashPrice(price, direction, 'tile-' + q.symbol); }
        }
        var chg = tile.querySelector('.mt-change');
        if (chg) {
            if (chg.dataset.built !== '1') {
                chg.dataset.built = '1';
                chg.innerHTML = '<span class="arrow"></span><span class="pct-val"></span>';
            }
            var arrow = chg.querySelector('.arrow');
            setText(arrow, q.change_pct >= 0 ? '▲' : '▼');
            setText(chg.querySelector('.pct-val'), ' ' + fmtPct(q.change_pct));
            var cls = 'mt-change ' + pctClass(q.change_pct);
            if (chg.className !== cls) { chg.className = cls; }
        }
        var meta = ensureTileMeta(tile);
        if (meta) {
            var liveKey = (q.is_stale ? 'stale' : (q.live ? 'live' : 'delay')) + (q.delay_seconds ? '-' + Math.round(q.delay_seconds / 60) : '');
            var liveEl = meta.querySelector('.mt-live');
            if (liveEl && liveEl.dataset.key !== liveKey) {
                liveEl.dataset.key = liveKey;
                liveEl.className = 'mt-live livedot ' + (q.is_stale ? 'delayed' : (q.live ? 'live' : 'delayed'));
                liveEl.textContent = q.is_stale ? 'dados antigos'
                    : (q.live ? 'ao vivo' : 'atraso ' + Math.round(q.delay_seconds / 60) + ' min');
            }
            var ageEl = meta.querySelector('.data-age');
            if (ageEl && ageEl.getAttribute('data-age') !== (q.fetched_at || '')) {
                ageEl.setAttribute('data-age', q.fetched_at || '');
            }
            setText(meta.querySelector('.mt-src'), q.provider);
        }
    }

    // relógio de idade dos dados (1s) — escreve apenas quando o texto muda
    setInterval(function () {
        document.querySelectorAll('[data-age]').forEach(function (e2) {
            var raw = e2.getAttribute('data-age');
            if (!raw) { return; }
            var ts = Math.floor(new Date(raw + 'Z').getTime() / 1000);
            var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
            setText(e2, s < 90 ? ('atualizado há ' + s + 's') : ('atualizado às ' + raw.slice(11, 16)));
            e2.classList.toggle('stale', s > 300);
        });
    }, 1000);

    // ------------------------------------------------------------------
    // STATUS GLOBAL
    // ------------------------------------------------------------------
    var conn = {
        ws: null, wsState: 'closed', attempts: 0, fallbackTimer: null, restTimer: null,
    };

    function setStatus(kind, label) {
        var pill = $('market-status');
        if (!pill) { return; }
        pill.className = 'market-status ' + kind;
        pill.querySelector('.ms-label').textContent = label;
    }

    function markUpdated() {
        var t = $('market-last-update');
        if (t) { t.textContent = '· ' + new Date().toLocaleTimeString('pt-BR'); }
    }

    // ------------------------- WebSocket (Binance, cripto) -------------------------
    var WS_STREAMS = ['btcusdt@miniTicker', 'ethusdt@miniTicker', 'solusdt@miniTicker', 'xrpusdt@miniTicker'];

    function wsConnect() {
        if (!window.WebSocket) { startRestFallback(); return; }
        try {
            conn.ws = new WebSocket('wss://stream.binance.com:9443/stream?streams=' + WS_STREAMS.join('/'));
        } catch (e) { startRestFallback(); return; }

        conn.ws.onopen = function () {
            conn.attempts = 0;
            if (conn.fallbackTimer) { clearInterval(conn.fallbackTimer); conn.fallbackTimer = null; }
            wsStateOk(true);
        };
        conn.ws.onmessage = function (ev) {
            try {
                var msg = JSON.parse(ev.data);
                var d = msg.data || msg;
                if (!d || !d.s) { return; }
                var sym = d.s; // BTCUSDT
                var open = parseFloat(d.o);
                var close = parseFloat(d.c);
                if (!open || !close) { return; }
                // merge com o quote REST existente (mantém label/provider/decimals)
                var base = state.quotes[sym] || {};
                var q = Object.assign({}, base, {
                    symbol: sym,
                    class: 'cripto',
                    price: close,
                    change_pct: ((close / open) - 1) * 100,
                    day_high: parseFloat(d.h),
                    day_low: parseFloat(d.l),
                    volume_money: parseFloat(d.q),
                    currency: base.currency || 'USD',
                    provider: 'Binance (WS)',
                    delay_seconds: 0,
                    quote_time: Math.floor(Date.now() / 1000),
                    fetched_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                    is_stale: false,
                    live: true,
                });
                if (base.price_original !== undefined) {
                    // reconverte para BRL com o câmbio conhecido
                    var fx = base.extra && base.extra.fx_rate;
                    if (fx) { q.price_original = close; q.price = Math.round(close * fx * 100) / 100; }
                }
                applyQuote(q);
            } catch (e) { /* pacote inválido: ignora */ }
        };
        conn.ws.onclose = function () { wsStateOk(false); wsRetry(); };
        conn.ws.onerror = function () { /* onclose segue */ };
    }

    var wsTimer = null;
    function wsRetry() {
        if (conn.wsState === 'retrying') { return; }
        conn.wsState = 'retrying';
        setStatus('reconnecting', 'Reconectando…');
        startRestFallback();
        conn.attempts = Math.min(conn.attempts + 1, 6);
        var delay = Math.min(30000, 1000 * Math.pow(2, conn.attempts));
        clearTimeout(wsTimer);
        wsTimer = setTimeout(function () {
            conn.wsState = 'closed';
            wsConnect();
        }, delay);
    }

    function wsStateOk(okFlag) {
        conn.wsState = okFlag ? 'open' : 'closed';
        if (okFlag) {
            setStatus('live', 'Mercado conectado');
            var ind = $('crypto-live-indicator');
            if (ind) { ind.style.display = ''; }
        }
    }

    /** Fallback: polling REST de cripto enquanto o WS estiver fora. */
    function startRestFallback() {
        if (conn.fallbackTimer) { return; }
        conn.fallbackTimer = setInterval(function () {
            if (conn.wsState === 'open') { return; }
            fetchQuotes(['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'XRPUSDT']).then(function () {
                if (conn.wsState !== 'open') { setStatus('reconnecting', 'Atualizando (sem WebSocket)'); }
            });
        }, 10000);
    }

    // ------------------------- REST comum -------------------------
    function fetchQuotes(symbols) {
        if (!symbols.length) { return Promise.resolve([]); }
        return FL.api(API + 'quote.php?symbols=' + encodeURIComponent(symbols.join(',')), { silent: true })
            .then(function (r) {
                if (r.data && r.data.ok) {
                    r.data.quotes.forEach(applyQuote);
                    toastTriggered(r.data.triggered_alerts);
                    markUpdated();
                    if (conn.wsState !== 'open') { setStatus('live', 'Atualizando'); }
                    return r.data.quotes;
                }
                setStatus('offline', 'Dados temporariamente indisponíveis');
                return [];
            });
    }

    // ------------------------------------------------------------------
    // HOME: tiles + painéis da visão geral
    // ------------------------------------------------------------------
    var HOME_SYMBOLS = ['^BVSP', '^GSPC', '^IXIC', 'USDBRL', 'BTCUSDT', 'ETHUSDT'];

    function buildTiles(quotes) {
        var wrap = $('mkt-tiles');
        wrap.innerHTML = '';
        HOME_SYMBOLS.forEach(function (sym) {
            var q = quotes[sym];
            var tile = el('div', 'mkt-tile');
            tile.setAttribute('data-symbol', sym);
            tile.appendChild(el('span', 'mt-label', q ? (q.label || sym) : sym));
            tile.appendChild(el('span', 'mt-price', q ? fmtPrice(q) : '—'));
            var chg = el('span', 'mt-change');
            tile.appendChild(chg);
            var meta = el('span', 'mt-meta');
            tile.appendChild(meta);
            tile.appendChild(el('div', 'mt-spark'));
            tile.addEventListener('click', function () { if (q) { openAssetModal(sym); } });
            wrap.appendChild(tile);
            if (q) { updateTile(tile, q); }
        });
        // sparklines (1D, cacheado no backend)
        HOME_SYMBOLS.forEach(function (sym) {
            FL.api(API + 'history.php?symbol=' + encodeURIComponent(sym) + '&range=1D', { silent: true })
                .then(function (r) {
                    if (!r.data || !r.data.ok || !window.FLMCharts) { return; }
                    var tile = wrap.querySelector('.mkt-tile[data-symbol="' + CSS.escape(sym) + '"] .mt-spark');
                    if (tile) { FLMCharts.sparkline(tile, r.data.candles, (state.quotes[sym] || {}).change_pct >= 0); }
                });
        });
    }

    function renderOverviewPanels() {
        var groups = [
            { wrap: 'ov-indices', syms: ['^BVSP', '^GSPC', '^IXIC', '^DJI', '^SMLL', '^GDAXI', '^N225', '^HSI'] },
            { wrap: 'ov-forex', syms: ['USDBRL', 'EURBRL', 'GBPBRL', 'JPYBRL', 'EURUSD=X', 'GC=F', 'SI=F', 'BZ=F'] },
            { wrap: 'ov-crypto', syms: ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'XRPUSDT', 'BNBUSDT', 'ADAUSDT', 'DOGEUSDT'] },
        ];
        groups.forEach(function (g) {
            var wrapEl = $(g.wrap);
            if (!wrapEl) { return; }
            wrapEl.innerHTML = '';
            var table = el('table', 'asset-table');
            var thead = document.createElement('thead');
            thead.innerHTML = '<tr><th>Ativo</th><th>Nome</th><th class="right">Preço</th><th class="right">Variação</th><th>Fonte · atraso</th></tr>';
            table.appendChild(thead);
            var tbody = document.createElement('tbody');
            g.syms.forEach(function (sym) {
                var q = state.quotes[sym];
                var tr = document.createElement('tr');
                tr.setAttribute('data-symbol', sym);
                tr.innerHTML = '<td class="sym"></td><td class="name-cell"></td><td class="num"></td><td class="num pct"></td><td class="src"></td>';
                tr.addEventListener('click', function () { if (state.quotes[sym]) { openAssetModal(sym); } });
                tbody.appendChild(tr);
                if (q) { updateAssetRow(tr, q); }
            });
            table.appendChild(tbody);
            wrapEl.appendChild(table);
        });
        // preenche com fetch inicial
        var all = [];
        groups.forEach(function (g) { all = all.concat(g.syms); });
        fetchQuotes(all).then(function () { refreshOverviewRows(); });
    }

    /** Garante a estrutura do src UMA vez; updates só mudam texto/classe. */
    function ensureRowSrc(tr) {
        var src = tr.querySelector('.src');
        if (!src || src.dataset.built === '1') { return src; }
        src.dataset.built = '1';
        src.innerHTML = '<span class="row-live"></span> <span class="row-prov"></span>';
        return src;
    }

    function updateAssetRow(tr, q) {
        setText(tr.querySelector('.sym'), q.display || q.symbol);
        setText(tr.querySelector('.name-cell'), q.label || '');
        setText(tr.querySelector('.num'), fmtPrice(q));
        var pct = tr.querySelector('.pct');
        setText(pct, fmtPct(q.change_pct));
        var cls = 'num pct ' + pctClass(q.change_pct);
        if (pct.className !== cls) { pct.className = cls; }
        var src = ensureRowSrc(tr);
        if (src) {
            var liveKey = (q.is_stale ? 'stale' : (q.live ? 'live' : 'delay'));
            var lv = src.querySelector('.row-live');
            if (lv && lv.dataset.key !== liveKey) {
                lv.dataset.key = liveKey;
                lv.className = 'row-live livedot ' + (q.is_stale ? 'delayed' : (q.live ? 'live' : 'delayed'));
                lv.textContent = q.is_stale ? 'dados antigos'
                    : (q.live ? 'ao vivo' : 'atraso ' + Math.round(q.delay_seconds / 60) + ' min');
            }
            setText(src.querySelector('.row-prov'), q.provider);
        }
    }

    function refreshOverviewRows() {
        Object.keys(state.quotes).forEach(function (sym) {
            var tr = document.querySelector('#tab-overview tr[data-symbol="' + CSS.escape(sym) + '"]');
            if (tr) { updateAssetRow(tr, state.quotes[sym]); }
        });
    }

    // ------------------------------------------------------------------
    // EXPLORAR
    // ------------------------------------------------------------------
    var CLASSES = [
        ['br_stock', 'Ações B3'], ['fii', 'FIIs'], ['fiagro', 'FIAGROs'], ['etf', 'ETFs'],
        ['bdr', 'BDRs'], ['index', 'Índices'], ['cripto', 'Cripto'], ['forex', 'Câmbio'], ['commodity', 'Commodities'],
    ];

    function buildClassChips() {
        var chips = $('mk-class-chips');
        chips.innerHTML = '';
        CLASSES.forEach(function (c) {
            var b = el('button', 'class-chip' + (state.class === c[0] ? ' active' : ''), c[1]);
            b.type = 'button';
            b.addEventListener('click', function () {
                state.class = c[0];
                chips.querySelectorAll('.class-chip').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                renderExplore();
            });
            chips.appendChild(b);
        });
        var mv = $('mv-class-chips');
        mv.innerHTML = '';
        CLASSES.filter(function (c) { return c[0] !== 'forex'; }).forEach(function (c) {
            var b = el('button', 'class-chip' + (state.moversClass === c[0] ? ' active' : ''), c[1]);
            b.type = 'button';
            b.addEventListener('click', function () {
                state.moversClass = c[0];
                mv.querySelectorAll('.class-chip').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                renderMovers();
            });
            mv.appendChild(b);
        });
    }

    function universeOf(cls) {
        // universos construídos a partir da constante PHP exposta no endpoint movers
        return (window.FL_MARKET_UNIVERSE && window.FL_MARKET_UNIVERSE[cls]) || [];
    }

    function renderExplore() {
        var tbody = $('mk-asset-tbody');
        var universe = universeOf(state.class);
        if (!universe.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="empty-cell">Selecione uma classe de ativo.</td></tr>';
            return;
        }
        tbody.innerHTML = '';
        universe.forEach(function (item) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-symbol', item.symbol);
            tr.innerHTML = '<td class="sym"></td><td class="name-cell"></td><td class="num"></td><td class="num pct"></td>'
                + '<td class="num"></td><td class="src"></td>';
            tr.addEventListener('click', function () { openAssetModal(item.symbol); });
            tbody.appendChild(tr);
        });
        $('mk-asset-count').textContent = universe.length + ' ativo(s)';
        fetchQuotes(universe.map(function (i) { return i.symbol; })).then(function () {
            universe.forEach(function (item) {
                var tr = tbody.querySelector('tr[data-symbol="' + CSS.escape(item.symbol) + '"]');
                var q = state.quotes[item.symbol];
                if (tr && q) {
                    updateAssetRow(tr, q);
                    var vol = tr.children[4];
                    vol.textContent = q.volume_money ? 'R$ ' + FL.moneyCompact(q.volume_money).replace('R$ ', '') : '—';
                } else if (tr) {
                    tr.querySelector('.sym').textContent = item.symbol;
                    tr.querySelector('.name-cell').textContent = item.label;
                }
            });
        });
    }

    // busca com dropdown
    function bindSearch(inputId, dropdownId, onPick) {
        var input = $(inputId), dd = $(dropdownId);
        input.addEventListener('input', FL.debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) { dd.classList.remove('show'); return; }
            FL.api(API + 'search.php?q=' + encodeURIComponent(q), { silent: true }).then(function (r) {
                if (!r.data || !r.data.ok) { return; }
                dd.innerHTML = '';
                (r.data.results || []).forEach(function (item) {
                    var d = el('div', 'search-item');
                    var sym = el('span', 'si-sym', item.symbol);
                    var name = el('span', 'si-name', item.name || item.symbol);
                    var cls = el('span', 'si-class', item.class || '');
                    d.appendChild(sym); d.appendChild(name); d.appendChild(cls);
                    d.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        dd.classList.remove('show');
                        input.value = '';
                        onPick(item.symbol);
                    });
                    dd.appendChild(d);
                });
                dd.classList.toggle('show', (r.data.results || []).length > 0);
            });
        }, 350));
        input.addEventListener('blur', function () { setTimeout(function () { dd.classList.remove('show'); }, 180); });
    }

    // ------------------------------------------------------------------
    // MOVERS + HEATMAP
    // ------------------------------------------------------------------
    function renderMovers() {
        FL.api(API + 'movers.php?class=' + state.moversClass + '&limit=10', { silent: true }).then(function (r) {
            if (!r.data || !r.data.ok) { return; }
            var d = r.data;
            fillMoverList('mv-gainers', d.gainers, 'gainers');
            fillMoverList('mv-losers', d.losers, 'losers');
            fillVolumes('mv-volumes', d.volumes);
            renderHeatmap(d.gainers.concat(d.losers).concat(d.volumes));
            r.data.gainers.concat(r.data.losers).forEach(function (q) { applyQuote(q); });
            $('mv-gainers-note').textContent = d.class_label;
            $('mv-losers-note').textContent = d.class_label;
        });
    }

    function fillMoverList(id, list, kind) {
        var wrap = $(id);
        wrap.innerHTML = '';
        if (!list || !list.length) {
            wrap.appendChild(el('p', 'empty', 'Sem dados no provedor agora.'));
            return;
        }
        list.forEach(function (q, i) {
            var row = el('div', 'mover-row');
            row.appendChild(el('span', 'mr-rank', String(i + 1)));
            row.appendChild(el('span', 'mr-sym', q.display || q.symbol));
            row.appendChild(el('span', 'mr-name', q.label || ''));
            var pct = el('span', 'mr-pct ' + pctClass(q.change_pct), fmtPct(q.change_pct));
            row.appendChild(pct);
            row.addEventListener('click', function () { openAssetModal(q.symbol); });
            wrap.appendChild(row);
        });
    }

    function fillVolumes(id, list) {
        var wrap = $(id);
        wrap.innerHTML = '';
        if (!list || !list.length) {
            wrap.appendChild(el('p', 'empty', 'Sem dados de volume.'));
            return;
        }
        list.forEach(function (q, i) {
            var row = el('div', 'mover-row');
            row.appendChild(el('span', 'mr-rank', String(i + 1)));
            row.appendChild(el('span', 'mr-sym', q.display || q.symbol));
            row.appendChild(el('span', 'mr-name', q.label || ''));
            var vol = el('span', 'mr-vol', q.volume_money ? 'R$ ' + FL.moneyCompact(q.volume_money).replace('R$ ', '') : '—');
            row.appendChild(vol);
            row.addEventListener('click', function () { openAssetModal(q.symbol); });
            wrap.appendChild(row);
        });
    }

    /** cor pela variação (verde→vermelho), intensidade fixa — nada aleatório */
    function heatColor(pct) {
        if (pct === null || pct === undefined) { return 'var(--faint)'; }
        if (pct > 3) { return '#059669'; }
        if (pct > 1.5) { return '#10B981'; }
        if (pct > 0.3) { return '#34D399'; }
        if (pct > -0.3) { return '#9D97B2'; }
        if (pct > -1.5) { return '#F87171'; }
        if (pct > -3) { return '#EF4444'; }
        return '#DC2626';
    }

    function renderHeatmap(quotes) {
        var wrap = $('mv-heatmap');
        wrap.innerHTML = '';
        var seen = {};
        var list = quotes.filter(function (q) { return q.volume_money && !seen[q.symbol] && (seen[q.symbol] = true); });
        var maxVol = Math.max.apply(null, list.map(function (q) { return q.volume_money; }).concat([1]));
        list.forEach(function (q) {
            var rel = q.volume_money / maxVol;
            var tile = el('div', 'heat-tile');
            tile.style.background = heatColor(q.change_pct);
            tile.style.gridColumn = 'span ' + (rel > 0.6 ? 2 : 1);
            tile.appendChild(el('span', 'ht-sym', q.display || q.symbol));
            tile.appendChild(el('span', 'ht-pct', fmtPct(q.change_pct)));
            tile.appendChild(el('span', 'ht-price', fmtPrice(q)));
            tile.addEventListener('click', function () { openAssetModal(q.symbol); });
            wrap.appendChild(tile);
        });
    }

    // ------------------------------------------------------------------
    // WATCHLIST
    // ------------------------------------------------------------------
    function loadWatchlist() {
        FL.api(API + 'watchlist.php', { silent: true }).then(function (r) {
            if (!r.data || !r.data.ok) { return; }
            state.watchlists = r.data.watchlists || [];
            if (!state.activeWatchlist && state.watchlists[0]) { state.activeWatchlist = state.watchlists[0].id; }
            toastTriggered(r.data.triggered_alerts);
            renderWatchlistTabs();
            renderWatchlistItems();
        });
    }

    function renderWatchlistTabs() {
        var tabs = $('wl-tabs');
        tabs.innerHTML = '';
        state.watchlists.forEach(function (wl) {
            var b = el('button', 'class-chip' + (state.activeWatchlist === wl.id ? ' active' : ''), wl.name + ' (' + wl.items.length + ')');
            b.type = 'button';
            b.addEventListener('click', function () { state.activeWatchlist = wl.id; renderWatchlistTabs(); renderWatchlistItems(); });
            tabs.appendChild(b);
        });
    }

    function renderWatchlistItems() {
        var wrap = $('wl-items');
        wrap.innerHTML = '';
        var wl = state.watchlists.find(function (w) { return w.id === state.activeWatchlist; });
        if (!wl) { return; }
        if (!wl.items.length) {
            var p = el('p', 'empty', 'Adicione ativos à sua watchlist usando a busca acima — eles atualizam automaticamente.');
            p.style.margin = '10px 2px';
            wrap.appendChild(p);
            return;
        }
        wl.items.forEach(function (item, idx) {
            var q = state.quotes[item.symbol];
            var row = el('div', 'wl-item');
            row.setAttribute('data-symbol', item.symbol);
            var info = el('span', 'wl-info');
            info.appendChild(el('strong', '', item.symbol.replace('.SA', '')));
            info.appendChild(el('small', '', (q && q.label) || ''));
            info.addEventListener('click', function () { openAssetModal(item.symbol); });
            row.appendChild(info);

            var quoteBox = el('span', 'wl-quote');
            quoteBox.appendChild(el('span', 'q-price', q ? fmtPrice(q) : '…'));
            var pct = el('span', 'q-pct', q ? fmtPct(q.change_pct) : '—');
            if (q) { pct.classList.add(pctClass(q.change_pct)); }
            quoteBox.appendChild(pct);
            var src = el('small', 'data-age muted', q ? (q.live ? 'ao vivo' : 'atraso ' + Math.round(q.delay_seconds / 60) + 'min') : '');
            if (q && q.fetched_at) { src.setAttribute('data-age', q.fetched_at); }
            quoteBox.appendChild(src);
            row.appendChild(quoteBox);

            var actions = el('span', 'wl-actions');
            if (idx > 0) {
                var upBtn = el('button', 'icon-btn');
                upBtn.title = 'Mover para cima';
                upBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m18 15-6-6-6 6"/></svg>';
                upBtn.addEventListener('click', function () { moveWatchlistItem(wl, idx, -1); });
                actions.appendChild(upBtn);
            }
            if (idx < wl.items.length - 1) {
                var downBtn = el('button', 'icon-btn');
                downBtn.title = 'Mover para baixo';
                downBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>';
                downBtn.addEventListener('click', function () { moveWatchlistItem(wl, idx, 1); });
                actions.appendChild(downBtn);
            }
            var delBtn = el('button', 'icon-btn danger');
            delBtn.title = 'Remover';
            delBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
            delBtn.addEventListener('click', function () {
                FL.api(API + 'watchlist.php?item_id=' + item.id, { method: 'DELETE' }).then(function () {
                    FL.toastSuccess('Ativo removido.');
                    loadWatchlist();
                });
            });
            actions.appendChild(delBtn);
            row.appendChild(actions);
            wrap.appendChild(row);
            if (q) { updateWlItem(row, q); }
        });
    }

    function updateWlItem(row, q) {
        var price = row.querySelector('.q-price');
        if (price) { price.textContent = fmtPrice(q); }
        var pct = row.querySelector('.q-pct');
        if (pct) {
            pct.textContent = fmtPct(q.change_pct);
            pct.className = 'q-pct ' + pctClass(q.change_pct);
        }
    }

    function moveWatchlistItem(wl, idx, dir) {
        var ids = wl.items.map(function (i) { return i.id; });
        var target = idx + dir;
        var tmp = ids[idx]; ids[idx] = ids[target]; ids[target] = tmp;
        FL.api(API + 'watchlist.php?action=items_reorder', { method: 'PUT', body: { watchlist_id: wl.id, item_ids: ids } })
            .then(loadWatchlist);
    }

    // ------------------------------------------------------------------
    // ALERTAS
    // ------------------------------------------------------------------
    function loadAlerts() {
        FL.api(API + 'alerts.php', { silent: true }).then(function (r) {
            if (!r.data || !r.data.ok) { return; }
            toastTriggered(r.data.triggered_alerts);
            renderAlerts(r.data.alerts || []);
        });
    }

    function renderAlerts(list) {
        var wrap = $('al-list');
        wrap.innerHTML = '';
        if (!list.length) {
            var p = el('p', 'empty', 'Nenhum alerta. Crie um — ex.: "avisar quando PETR4 chegar a R$ 40".');
            p.style.margin = '10px 2px';
            wrap.appendChild(p);
            return;
        }
        list.forEach(function (a) {
            var row = el('div', 'alert-row status-' + a.status);
            var info = el('div', 'al-info');
            info.appendChild(el('strong', '', a.display + (a.status === 'triggered' ? ' ✓ disparado' : '')));
            info.appendChild(el('small', '', a.note || a.label));
            row.appendChild(info);
            var cond = el('span', 'al-condition', a.type_label.replace(/ \(.*\)$/, '') + ' '
                + (a.type.indexOf('pct') === -1 ? 'R$ ' + a.target.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) : a.target.toLocaleString('pt-BR') + '%'));
            row.appendChild(cond);
            var quoteTxt = a.quote ? ('agora: ' + fmtPrice(a.quote) + ' · ' + (a.quote.live ? 'ao vivo' : 'atraso ' + Math.round(a.quote.delay_seconds / 60) + 'min')) : '';
            row.appendChild(el('span', 'al-quote', quoteTxt));

            var actions = el('span', 'al-actions');
            if (a.status === 'active') {
                var cancelBtn = el('button', 'btn btn-ghost btn-sm', 'Cancelar');
                cancelBtn.addEventListener('click', function () {
                    FL.api(API + 'alerts.php?action=cancel', { method: 'PUT', body: { id: a.id } }).then(loadAlerts);
                });
                actions.appendChild(cancelBtn);
            } else if (a.status === 'triggered') {
                var reBtn = el('button', 'btn btn-soft btn-sm', 'Reativar');
                reBtn.addEventListener('click', function () {
                    FL.api(API + 'alerts.php?action=reactivate', { method: 'PUT', body: { id: a.id } }).then(function () {
                        FL.toastSuccess('Alerta reativado.');
                        loadAlerts();
                    });
                });
                actions.appendChild(reBtn);
            }
            var delBtn = el('button', 'btn btn-danger-ghost btn-sm', 'Excluir');
            delBtn.addEventListener('click', function () {
                FL.confirm('Excluir alerta', 'Excluir o alerta de ' + a.display + '?', true).then(function (ok) {
                    if (!ok) { return; }
                    FL.api(API + 'alerts.php?id=' + a.id, { method: 'DELETE' }).then(loadAlerts);
                });
            });
            actions.appendChild(delBtn);
            row.appendChild(actions);
            wrap.appendChild(row);
        });
    }

    function openAlertForm(symbol, presetTarget) {
        FL.formModal('Novo alerta de preço', [
            { name: 'symbol', label: 'Ativo *', type: 'text', value: symbol || '', required: true, maxlength: 20, placeholder: 'Ex.: PETR4, BTC' },
            { name: 'type', label: 'Condição *', type: 'select', value: 'above', options: [
                { value: 'above', label: 'Preço acima de (R$)' },
                { value: 'below', label: 'Preço abaixo de (R$)' },
                { value: 'pct_up', label: 'Variação do dia acima de (%)' },
                { value: 'pct_down', label: 'Variação do dia abaixo de (−%)' },
            ] },
            { name: 'target', label: 'Valor-alvo *', type: 'number', value: presetTarget || '', required: true, min: 0.0001, step: 'any', hint: 'Alertas são avaliados a cada atualização de cotação e notificados no sistema.' },
            { name: 'note', label: 'Observação', type: 'text', maxlength: 255, placeholder: 'Opcional' },
        ], function (values, helpers) {
            return FL.api(API + 'alerts.php', { method: 'POST', body: values }).then(function (r) {
                if (r.data && r.data.ok) {
                    helpers.close();
                    FL.toastSuccess('Alerta criado!');
                    loadAlerts();
                } else {
                    helpers.showFormError((r.data && r.data.error) || 'Não foi possível criar.');
                }
            });
        });
    }

    // ------------------------------------------------------------------
    // CARTEIRA
    // ------------------------------------------------------------------
    function loadPortfolio() {
        FL.api(API + 'portfolio.php', { silent: true }).then(function (r) {
            if (!r.data || !r.data.ok) { return; }
            renderPortfolio(r.data);
        });
    }

    function renderPortfolio(data) {
        var s = data.summary || {};
        (s.positions || []).forEach(function (p) { if (p.quote) { applyQuote(p.quote); } });

        var stats = $('pf-stats');
        stats.innerHTML = '';
        function pfStat(label, value, cls) {
            var d = el('div', 'pf-stat' + (cls ? ' ' + cls : ''));
            d.appendChild(el('span', 'stat-label', label));
            d.appendChild(el('span', 'pf-value', value));
            stats.appendChild(d);
        }
        pfStat('Valor atual', FL.money(s.total_value || 0));
        pfStat('Total investido', FL.money(s.total_cost || 0));
        pfStat('Lucro/prejuízo', FL.money(s.profit || 0), (s.profit || 0) >= 0 ? 'positive' : 'negative');
        pfStat('Rentabilidade', s.profit_pct !== null && s.profit_pct !== undefined ? fmtPct(s.profit_pct) : '—', (s.profit || 0) >= 0 ? 'positive' : 'negative');

        // alocação
        var alloc = $('pf-allocation');
        alloc.innerHTML = '';
        if (!(s.allocation || []).length) {
            alloc.appendChild(el('p', 'empty', 'Registre operações para ver a alocação da sua carteira.'));
        } else {
            (s.allocation || []).forEach(function (a) {
                var row = el('div', 'alloc-row');
                var top = el('div', 'ar-top');
                top.appendChild(el('span', '', a.label));
                top.appendChild(el('span', 'muted', FL.money(a.value) + ' · ' + a.pct.toLocaleString('pt-BR') + '%'));
                row.appendChild(top);
                var bar = el('div', 'bar');
                var fill = el('div', 'bar-fill');
                fill.style.width = a.pct + '%';
                bar.appendChild(fill);
                row.appendChild(bar);
                alloc.appendChild(row);
            });
        }

        // benchmark
        var bench = s.benchmark;
        $('pf-bench-period').textContent = bench ? ('Desde ' + FL.brDate(bench.from) + ' · ' + bench.days + ' dias · fonte: ' + bench.source) : '—';
        var bWrap = $('pf-benchmark');
        bWrap.innerHTML = '';
        if (bench) {
            var row = el('div', 'benchmark-row');
            function chip(label, val) {
                var c = el('div', 'benchmark-chip');
                c.appendChild(el('small', '', label));
                c.appendChild(el('strong', '', val));
                row.appendChild(c);
            }
            chip('Carteira', s.profit_pct !== null && s.profit_pct !== undefined ? fmtPct(s.profit_pct) : '—');
            chip('Ibovespa', bench.ibov_pct !== null ? fmtPct(bench.ibov_pct) : 'indisp.');
            chip('CDI', bench.cdi_pct !== null ? fmtPct(bench.cdi_pct) : 'indisp.');
            bWrap.appendChild(row);
            var note = el('p', 'muted small', 'Mesma janela de tempo para os três: da primeira operação até hoje, com dados reais do Ibovespa (B3) e do CDI (BCB, série 12).');
            bWrap.appendChild(note);
        } else {
            bWrap.appendChild(el('p', 'empty', 'Sem operações registradas — o comparativo aparece após a primeira compra.'));
        }

        // posições
        var tbody = $('pf-positions');
        tbody.innerHTML = '';
        if (!(s.positions || []).length) {
            tbody.innerHTML = '<tr><td colspan="9" class="empty-cell">Nenhuma posição. Registre uma compra para começar.</td></tr>';
        } else {
            (s.positions || []).forEach(function (p) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td class="sym"></td><td></td><td class="num"></td><td class="num"></td><td class="num"></td><td class="num"></td><td class="num"></td><td class="num"></td><td class="src"></td>';
                tr.querySelector('.sym').textContent = p.display;
                tr.children[1].textContent = p.class_label;
                tr.children[2].textContent = p.quantity.toLocaleString('pt-BR', { maximumFractionDigits: 8 });
                tr.children[3].textContent = FL.money(p.avg_price);
                tr.children[4].textContent = FL.money(p.price);
                tr.children[5].textContent = FL.money(p.value);
                tr.children[6].textContent = FL.money(p.profit);
                tr.children[6].style.color = p.profit >= 0 ? 'var(--success)' : 'var(--danger)';
                tr.children[7].textContent = p.profit_pct !== null ? fmtPct(p.profit_pct) : '—';
                tr.children[7].style.fontWeight = '700';
                tr.children[7].style.color = (p.profit || 0) >= 0 ? 'var(--success)' : 'var(--danger)';
                if (p.quote) {
                    tr.querySelector('.src').innerHTML = liveBadge(p.quote) + ' <span class="data-age" data-age="' + (p.quote.fetched_at || '') + '"></span>';
                }
                tr.addEventListener('click', function () { openAssetModal(p.symbol); });
                tbody.appendChild(tr);
            });
        }

        // transações
        var txWrap = $('pf-transactions');
        txWrap.innerHTML = '';
        var txs = data.transactions || [];
        $('pf-tx-count').textContent = txs.length + ' operação(ões)';
        if (!txs.length) { txWrap.appendChild(el('p', 'empty', 'Nenhuma operação registrada.')); }
        txs.forEach(function (t) {
            var row = el('div', 'mover-row');
            var chip = el('span', 'chip ' + (t.type === 'buy' ? 'good' : 'warn'), t.type === 'buy' ? 'Compra' : 'Venda');
            row.appendChild(chip);
            row.appendChild(el('span', 'mr-sym', t.display));
            row.appendChild(el('span', 'mr-name', t.quantity.toLocaleString('pt-BR') + ' × ' + FL.money(t.price) + (t.fees ? ' (taxas ' + FL.money(t.fees) + ')' : '')));
            var date = el('span', 'mr-vol', FL.brDate(t.date));
            row.appendChild(date);
            row.style.cursor = 'default';
            txWrap.appendChild(row);
        });

        // dividendos
        var divWrap = $('pf-dividends');
        divWrap.innerHTML = '';
        var divs = data.dividends || [];
        $('pf-div-summary').textContent = 'Mês: ' + FL.money(s.dividends_month || 0) + ' · Ano: ' + FL.money(s.dividends_year || 0)
            + (s.dividend_yield !== null && s.dividend_yield !== undefined ? ' · yield ' + s.dividend_yield.toLocaleString('pt-BR') + '%' : '');
        if (!divs.length) { divWrap.appendChild(el('p', 'empty', 'Nenhum provento registrado.')); }
        divs.forEach(function (d) {
            var row = el('div', 'mover-row');
            var labels = { dividend: 'Dividendo', jcp: 'JCP', rendimento_fii: 'Rendimento FII', juros: 'Juros', outro: 'Outro' };
            var chip = el('span', 'chip good', labels[d.type] || d.type);
            row.appendChild(chip);
            row.appendChild(el('span', 'mr-sym', d.display));
            row.appendChild(el('span', 'mr-name', (d.per_unit ? d.per_unit.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) + '/un · ' : '') + FL.brDate(d.pay_date)));
            row.appendChild(el('span', 'mr-pct up', FL.money(d.amount)));
            row.style.cursor = 'default';
            divWrap.appendChild(row);
        });
    }

    function openTransactionForm(symbol) {
        FL.formModal('Registrar operação', [
            { name: 'symbol', label: 'Ativo *', type: 'text', value: symbol || '', required: true, maxlength: 20, placeholder: 'Ex.: PETR4, HGLG11, BTC' },
            { name: 'type', label: 'Operação *', type: 'select', value: 'buy', options: [
                { value: 'buy', label: 'Compra' }, { value: 'sell', label: 'Venda' },
            ] },
            { name: 'quantity', label: 'Quantidade *', type: 'number', required: true, min: 0.00000001, step: 'any', placeholder: 'Ex.: 100' },
            { name: 'price', label: 'Preço unitário (R$) *', type: 'number', required: true, min: 0.0001, step: 'any', placeholder: '0,00' },
            { name: 'fees', label: 'Taxas/corretagem (R$)', type: 'number', value: '', min: 0, step: 'any', hint: 'Incluídas no preço médio. Ex.: 5,40' },
            { name: 'date', label: 'Data *', type: 'date', value: new Date().toISOString().slice(0, 10), required: true },
            { name: 'note', label: 'Observação', type: 'text', maxlength: 255 },
        ], function (values, helpers) {
            return FL.api(API + 'portfolio.php', { method: 'POST', body: Object.assign({ action: 'transaction' }, values) })
                .then(function (r) {
                    if (r.data && r.data.ok) {
                        helpers.close();
                        FL.toastSuccess('Operação registrada!');
                        loadPortfolio();
                    } else {
                        helpers.showFormError((r.data && r.data.error) || 'Não foi possível registrar.');
                    }
                });
        });
    }

    function openDividendForm(symbol) {
        FL.formModal('Registrar dividendo/provento', [
            { name: 'symbol', label: 'Ativo *', type: 'text', value: symbol || '', required: true, maxlength: 20, placeholder: 'Ex.: PETR4, HGLG11' },
            { name: 'type', label: 'Tipo *', type: 'select', value: 'dividend', options: [
                { value: 'dividend', label: 'Dividendo' },
                { value: 'jcp', label: 'Juros sobre capital próprio (JCP)' },
                { value: 'rendimento_fii', label: 'Rendimento de FII/FIAGRO' },
                { value: 'juros', label: 'Juros' },
                { value: 'outro', label: 'Outro' },
            ] },
            { name: 'amount', label: 'Valor total recebido (R$) *', type: 'number', required: true, min: 0.01, step: 'any' },
            { name: 'per_unit', label: 'Valor por unidade (R$)', type: 'number', min: 0, step: 'any', hint: 'Opcional' },
            { name: 'ex_date', label: 'Data-com', type: 'date' },
            { name: 'pay_date', label: 'Data de pagamento *', type: 'date', value: new Date().toISOString().slice(0, 10), required: true },
            { name: 'note', label: 'Observação', type: 'text', maxlength: 255 },
        ], function (values, helpers) {
            return FL.api(API + 'portfolio.php', { method: 'POST', body: Object.assign({ action: 'dividend' }, values) })
                .then(function (r) {
                    if (r.data && r.data.ok) {
                        helpers.close();
                        FL.toastSuccess('Provento registrado!');
                        loadPortfolio();
                    } else {
                        helpers.showFormError((r.data && r.data.error) || 'Não foi possível registrar.');
                    }
                });
        });
    }

    // ------------------------------------------------------------------
    // MODAL DE DETALHE DO ATIVO
    // ------------------------------------------------------------------
    function openAssetModal(symbol) {
        symbol = symbol.toUpperCase();
        state.assetModal = { symbol: symbol, range: state.assetModal.range || '1M', type: 'area' };
        loadAssetDetail();
    }

    function loadAssetDetail() {
        var m = state.assetModal;
        FL.api(API + 'asset.php?symbol=' + encodeURIComponent(m.symbol) + '&range=' + m.range, { silent: true })
            .then(function (r) {
                if (!r.data || !r.data.ok) {
                    FL.toastError((r.data && r.data.error) || 'Ativo não encontrado.');
                    return;
                }
                var d = r.data;
                state.quotes[d.quote.symbol] = d.quote;
                state.assetModal.candles = d.candles;
                renderAssetModal(d);
            });
    }

    function renderAssetModalQuote(qArg, direction) {
        var q = qArg || state.quotes[state.assetModal.symbol];
        if (!q) { return; }
        var priceEl = $('asset-modal-price');
        var pctEl = $('asset-modal-pct');
        var srcEl = $('asset-modal-source');
        if (!priceEl) { return; }
        // estrutura construída UMA vez; depois apenas texto (sem esvaziar o DOM)
        if (priceEl.dataset.built !== '1') {
            priceEl.dataset.built = '1';
            priceEl.innerHTML = '<span class="am-main"></span><small class="am-conv"></small>';
        }
        var main = priceEl.querySelector('.am-main');
        var conv = priceEl.querySelector('.am-conv');
        setText(main, fmtPrice(q));
        if (q.price_original !== undefined) {
            setText(conv, ' (≈ ' + FL.money(q.price) + ')');
        } else {
            setText(conv, '');
        }
        if (direction) { flashPrice(main, direction, 'modal'); }
        if (pctEl) {
            setText(pctEl, (q.change_pct >= 0 ? '▲ ' : '▼ ') + fmtPct(q.change_pct));
            var cls = 'ah-pct ' + pctClass(q.change_pct);
            if (pctEl.className !== cls) { pctEl.className = cls; }
        }
        if (srcEl) {
            if (srcEl.dataset.built !== '1') {
                srcEl.dataset.built = '1';
                srcEl.innerHTML = '<span class="row-live"></span><span class="am-sep"> · </span>'
                    + '<span class="am-prov"></span><span class="am-sep"> · </span>'
                    + '<span class="am-time"></span><span class="am-sep"> · </span>'
                    + '<span class="data-age" data-age=""></span>';
            }
            var liveKey = (q.is_stale ? 'stale' : (q.live ? 'live' : 'delay'));
            var lv = srcEl.querySelector('.row-live');
            if (lv && lv.dataset.key !== liveKey) {
                lv.dataset.key = liveKey;
                lv.className = 'row-live livedot ' + (q.is_stale ? 'delayed' : (q.live ? 'live' : 'delayed'));
                lv.textContent = q.is_stale ? 'dados antigos'
                    : (q.live ? 'ao vivo' : 'atraso ' + Math.round(q.delay_seconds / 60) + ' min');
            }
            setText(srcEl.querySelector('.am-prov'), q.provider);
            setText(srcEl.querySelector('.am-time'), 'cotação de ' + new Date(q.quote_time * 1000).toLocaleTimeString('pt-BR'));
            var ageEl = srcEl.querySelector('.data-age');
            if (ageEl && ageEl.getAttribute('data-age') !== (q.fetched_at || '')) {
                ageEl.setAttribute('data-age', q.fetched_at || '');
            }
        }
    }

    function renderAssetModal(d) {
        var q = d.quote;
        var overlay = $('modal-overlay');
        var modal = document.createElement('div');
        modal.className = 'modal modal-wide';

        var body =
            '<div class="asset-head">'
            + '<div class="ah-title"><h2>' + FL.esc(q.display || q.symbol) + '</h2>'
            + '<small>' + FL.esc(q.label || '') + (q.market ? ' · ' + FL.esc(q.market) : '') + '</small>'
            + '<div class="asset-source" id="asset-modal-source"></div></div>'
            + '<div class="ah-quote"><span class="ah-price" id="asset-modal-price"></span>'
            + '<span class="ah-pct" id="asset-modal-pct"></span></div>'
            + '</div>'
            + '<div class="chart-toolbar">'
            + '<div class="period-btns" id="am-periods">'
            + ['1D', '5D', '1M', '3M', '6M', '1A', '5A', 'MAX'].map(function (p) {
                return '<button type="button" class="period-btn' + (p === state.assetModal.range ? ' active' : '') + '" data-range="' + p + '">' + p + '</button>';
            }).join('')
            + '</div>'
            + '<div class="chart-type-btns" id="am-types">'
            + '<button type="button" class="chart-type-btn" data-type="area">Área</button>'
            + '<button type="button" class="chart-type-btn" data-type="line">Linha</button>'
            + '<button type="button" class="chart-type-btn" data-type="candles">Candles</button>'
            + '</div>'
            + '</div>'
            + '<div class="fchart" id="asset-chart" style="min-height:200px"></div>'
            + '<div class="asset-stats" id="am-stats"></div>'
            + (d.crypto_extra ? '<h4 style="font-size:.8125rem;margin:4px 0 8px">Dados de mercado (CoinGecko, BRL)</h4><div class="kv-list" id="am-crypto"></div>' : '')
            + (d.derived ? '' : '')
            + '<h4 style="font-size:.8125rem;margin:12px 0 8px">Indicadores do ativo</h4>'
            + '<div class="kv-list" id="am-derived"></div>'
            + '<h4 style="font-size:.8125rem;margin:14px 0 8px">Últimas notícias <small class="muted" style="font-weight:500">(' + FL.esc(d.news_query || q.symbol) + ')</small></h4>'
            + '<div class="news-list" id="am-news"></div>'
            + '<p class="muted small" style="margin-top:14px">' + FL.esc(d.fundamentals_note || '') + '</p>';

        modal.innerHTML =
            '<div class="modal-head"><h3>' + FL.esc(q.label || q.symbol) + '</h3>'
            + '<button type="button" class="modal-close" aria-label="Fechar">×</button></div>'
            + '<div class="modal-body">' + body + '</div>'
            + '<div class="modal-foot">'
            + '<button type="button" class="btn btn-ghost" data-wl>+ Watchlist</button>'
            + '<button type="button" class="btn btn-ghost" data-alert>+ Alerta</button>'
            + '<button type="button" class="btn btn-primary" data-close>Fechar</button>'
            + '</div>';

        overlay.innerHTML = '';
        overlay.appendChild(modal);
        overlay.hidden = false;
        modal.querySelector('.modal-close').addEventListener('click', FL.closeModal);
        modal.querySelector('[data-close]').addEventListener('click', FL.closeModal);
        modal.querySelector('[data-wl]').addEventListener('click', function () { addToWatchlist(q.symbol); });
        modal.querySelector('[data-alert]').addEventListener('click', function () { FL.closeModal(); openAlertForm(q.display, q.price); });

        renderAssetModalQuote();

        // stats
        var stats = modal.querySelector('#am-stats');
        function astat(label, value) {
            var s2 = document.createElement('div');
            s2.className = 'asset-stat';
            s2.innerHTML = '<small></small><strong></strong>';
            s2.querySelector('small').textContent = label;
            s2.querySelector('strong').textContent = value;
            stats.appendChild(s2);
        }
        astat('Abertura', q.day_open !== null && q.day_open !== undefined ? fmtPrice({ price: q.day_open, currency: q.currency, decimals: q.decimals }) : '—');
        astat('Máxima', q.day_high !== null && q.day_high !== undefined ? fmtPrice({ price: q.day_high, currency: q.currency, decimals: q.decimals }) : '—');
        astat('Mínima', q.day_low !== null && q.day_low !== undefined ? fmtPrice({ price: q.day_low, currency: q.currency, decimals: q.decimals }) : '—');
        astat('Fech. anterior', q.prev_close !== null && q.prev_close !== undefined ? fmtPrice({ price: q.prev_close, currency: q.currency, decimals: q.decimals }) : '—');
        if (q.volume) { astat('Volume', q.volume.toLocaleString('pt-BR', { maximumFractionDigits: 0 }) + (q.volume_money ? ' (R$ ' + FL.moneyCompact(q.volume_money).replace('R$ ', '') + ')' : '')); }

        // crypto extras
        if (d.crypto_extra) {
            var ck = modal.querySelector('#am-crypto');
            function kv(label, value) {
                var r2 = document.createElement('div');
                r2.className = 'kv';
                r2.innerHTML = '<span></span><strong></strong>';
                r2.querySelector('span').textContent = label;
                r2.querySelector('strong').textContent = value;
                ck.appendChild(r2);
            }
            var e2 = d.crypto_extra;
            if (e2.market_cap) { kv('Market cap', FL.money(e2.market_cap) + (e2.market_cap_rank ? ' (6' + e2.market_cap_rank + ')' : '')); }
            if (e2.ath) { kv('Máxima histórica (ATH)', FL.money(e2.ath)); }
            if (e2.atl) { kv('Mínima histórica (ATL)', FL.money(e2.atl)); }
            if (e2.pct_1h !== null && e2.pct_1h !== undefined) { kv('Variação 1h', fmtPct(e2.pct_1h)); }
            if (e2.pct_7d !== null && e2.pct_7d !== undefined) { kv('Variação 7d', fmtPct(e2.pct_7d)); }
            if (e2.circulating_supply) { kv('Supply circulante', Math.round(e2.circulating_supply).toLocaleString('pt-BR')); }
        }

        // derivados reais
        var dv = modal.querySelector('#am-derived');
        if (d.derived) {
            ['high_52w|Máxima 52 sem.', 'low_52w|Mínima 52 sem.', 'volatility_annual_pct|Volatilidade anual'].forEach(function (pair) {
                var parts = pair.split('|');
                var v = d.derived[parts[0]];
                if (v === null || v === undefined) { return; }
                var r3 = document.createElement('div');
                r3.className = 'kv';
                r3.innerHTML = '<span></span><strong></strong>';
                r3.querySelector('span').textContent = parts[1];
                r3.querySelector('strong').textContent = parts[0] === 'volatility_annual_pct' ? fmtPct(v) : FL.money(v);
                dv.appendChild(r3);
            });
            var note = document.createElement('div');
            note.className = 'kv';
            note.innerHTML = '<span>Base de cálculo</span><strong>' + d.derived.series_points + ' candles reais (12 meses)</strong>';
            dv.appendChild(note);
        } else {
            dv.appendChild(el('p', 'muted small', 'Sem histórico suficiente para derivar indicadores.'));
        }

        // notícias
        var nw = modal.querySelector('#am-news');
        if ((d.news || []).length) {
            d.news.forEach(function (n) {
                var item = document.createElement('div');
                item.className = 'news-item';
                var a = document.createElement('a');
                a.href = n.link || '6';
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = n.title;
                var sm = document.createElement('small');
                sm.textContent = (n.publisher || '') + (n.published_at ? ' · ' + new Date(n.published_at * 1000).toLocaleString('pt-BR') : '');
                item.appendChild(a);
                item.appendChild(sm);
                nw.appendChild(item);
            });
        } else {
            nw.appendChild(el('p', 'muted small', 'Nenhuma notícia disponível no provedor para este ativo agora.'));
        }

        // gráfico
        drawAssetChart();

        // períodos
        modal.querySelectorAll('.period-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                state.assetModal.range = b.getAttribute('data-range');
                modal.querySelectorAll('.period-btn').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                loadAssetDetail();
            });
        });
        modal.querySelectorAll('.chart-type-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                state.assetModal.type = b.getAttribute('data-type');
                modal.querySelectorAll('.chart-type-btn').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                drawAssetChart();
            });
        });
        modal.querySelector('.chart-type-btn[data-type="' + (state.assetModal.type || 'area') + '"]').classList.add('active');
    }

    function drawAssetChart() {
        var m = state.assetModal;
        var container = $('asset-chart');
        if (container && window.FLMCharts && (m.candles || []).length > 1) {
            FLMCharts.chart(container, m.candles, {
                type: m.type,
                decimals: (state.quotes[m.symbol] || {}).decimals,
                height: 320,
            });
        }
    }

    function addToWatchlist(symbol) {
        if (!state.activeWatchlist) { loadWatchlist(); }
        var wid = state.activeWatchlist;
        if (!wid) { FL.toastError('Nenhuma watchlist ativa.'); return; }
        FL.api(API + 'watchlist.php?action=item_add', { method: 'POST', body: { watchlist_id: wid, symbol: symbol } })
            .then(function (r) {
                if (r.data && r.data.ok) {
                    FL.toastSuccess('Adicionado à watchlist!');
                    loadWatchlist();
                } else {
                    FL.toastError((r.data && r.data.error) || 'Não foi possível adicionar.');
                }
            });
    }

    // ------------------------------------------------------------------
    // TABS + POLLING GERAL
    // ------------------------------------------------------------------
    function switchTab(name) {
        state.tab = name;
        document.querySelectorAll('.tab-link').forEach(function (t) {
            t.classList.toggle('active', t.getAttribute('data-tab') === name);
        });
        document.querySelectorAll('.tab-panel').forEach(function (p) {
            p.classList.toggle('active', p.id === 'tab-' + name);
        });
        if (state.loaded[name]) { return; }
        state.loaded[name] = true;
        if (name === 'overview') { renderOverviewPanels(); }
        if (name === 'explore') { renderExplore(); }
        if (name === 'movers') { renderMovers(); }
        if (name === 'watchlist') { loadWatchlist(); }
        if (name === 'alerts') { loadAlerts(); }
        if (name === 'portfolio') { loadPortfolio(); }
    }

    // polling REST principal: overview + visíveis, respeitando o cache do backend
    function startMainPolling() {
        conn.restTimer = setInterval(function () {
            if (document.hidden) { return; }
            if (state.tab === 'watchlist' && state.activeWatchlist) {
                var wl = state.watchlists.find(function (w) { return w.id === state.activeWatchlist; });
                if (wl) {
                    fetchQuotes(wl.items.map(function (i) { return i.symbol; }));
                    return;
                }
            }
            if (state.tab === 'overview') { fetchQuotes(HOME_SYMBOLS); }
        }, 20000);
    }

    // universo exposto ao frontend (para a aba Explorar)
    function loadUniverse() {
        // o endpoint movers devolve o universo na classe pedida; obter todos:
        Promise.all(CLASSES.map(function (c) {
            return FL.api(API + 'movers.php?class=' + c[0] + '&limit=15', { silent: true }).then(function (r) { return r.data; });
        })).then(function () { /* os quotes já entraram via applyQuote */ });
    }

    // ------------------------------------------------------------------
    // INIT
    // ------------------------------------------------------------------
    document.querySelectorAll('#markets-tabs .tab-link').forEach(function (t) {
        t.addEventListener('click', function () { switchTab(t.getAttribute('data-tab')); });
    });

    bindSearch('mk-search', 'mk-search-dropdown', function (symbol) { openAssetModal(symbol); });
    bindSearch('wl-add-input', 'wl-add-dropdown', function (symbol) { addToWatchlist(symbol); });

    var wlNew = $('wl-new-list');
    if (wlNew) {
        wlNew.addEventListener('click', function () {
            FL.formModal('Nova watchlist', [
                { name: 'name', label: 'Nome *', type: 'text', required: true, maxlength: 80, placeholder: 'Ex.: Ações de dividendos' },
            ], function (values, helpers) {
                return FL.api(API + 'watchlist.php?action=list_create', { method: 'POST', body: values }).then(function (r) {
                    if (r.data && r.data.ok) {
                        helpers.close();
                        FL.toastSuccess('Watchlist criada!');
                        state.activeWatchlist = r.data.id;
                        loadWatchlist();
                    } else { helpers.showFormError('Não foi possível criar.'); }
                });
            });
        });
    }

    var alNew = $('al-new');
    if (alNew) { alNew.addEventListener('click', function () { openAlertForm('', ''); }); }
    var pfTx = $('pf-add-tx');
    if (pfTx) { pfTx.addEventListener('click', function () { openTransactionForm(''); }); }
    var pfDiv = $('pf-add-dividend');
    if (pfDiv) { pfDiv.addEventListener('click', function () { openDividendForm(''); }); }

    buildClassChips();
    wsConnect();
    startMainPolling();
    setStatus('reconnecting', 'Conectando…');

    // carga inicial: tiles + visão geral
    fetchQuotes(HOME_SYMBOLS).then(function (quotes) {
        var bySym = {};
        quotes.forEach(function (q) { bySym[q.symbol] = q; });
        buildTiles(bySym);
        renderOverviewPanels();
        state.loaded.overview = true;
    });
})();
