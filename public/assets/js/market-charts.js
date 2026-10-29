/* ==============================================================
   FinLife — Gráfico financeiro (candlestick/linha/área + volume)
   SVG puro: crosshair, tooltip OHLCV, zoom por scroll, pan por
   arraste, períodos. Dados sempre reais (vêm das APIs).
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL || {};

    function esc(v) { return FL.esc ? FL.esc(v) : String(v == null ? '' : v); }
    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v || '').trim() || fallback;
    }
    function fmtNum(v, dec) {
        var d = dec === undefined ? 2 : dec;
        return Number(v).toLocaleString('pt-BR', { minimumFractionDigits: d, maximumFractionDigits: d });
    }

    var FLM = {};

    /**
     * Renderiza o gráfico financeiro completo.
     * container: elemento (com .fchart)
     * candles: [{t,o,h,l,c,v}]
     * opts: {type: 'candles'|'line'|'area', decimals, currency, intervalLabel}
     */
    FLM.chart = function (container, candles, opts) {
        opts = opts || {};
        opts.type = opts.type || 'area';
        if (!container || !candles || candles.length < 2) {
            if (container) {
                container.innerHTML = '<p class="empty">Sem dados de histórico para o período.</p>';
            }
            return;
        }

        var W = Math.max(280, container.clientWidth || 600);
        var H = opts.height || 340;
        var VOL_H = 56;        // faixa de volume
        var PAD_L = 8, PAD_R = 58, PAD_T = 10, PAD_B = 22;
        var priceH = H - VOL_H - PAD_T - PAD_B - 6;

        // estado do slice visível (zoom/pan)
        var start = 0;
        var end = candles.length;

        var cGrid = cssVar('--chart-grid', '#EEEAF6');
        var cAxis = cssVar('--chart-axis', '#9D97B2');
        var cUp = cssVar('--success-bright', '#059669');
        var cDown = cssVar('--danger-bright', '#E04938');
        var cLine = cssVar('--primary', '#7C3AED');
        var cText = cssVar('--text', '#1B1730');
        var cBg = cssVar('--bg', '#F1EDF9');

        function dec() { return opts.decimals === undefined ? 2 : opts.decimals; }

        function render() {
            var view = candles.slice(start, end);
            if (view.length < 2) { view = candles.slice(0, Math.max(2, candles.length)); start = 0; end = candles.length; }
            var innerW = W - PAD_L - PAD_R;

            // escala de preço
            var pMin = Infinity, pMax = -Infinity;
            for (var i = 0; i < view.length; i++) {
                var c = view[i];
                if (opts.type === 'candles') {
                    pMin = Math.min(pMin, c.l); pMax = Math.max(pMax, c.h);
                } else {
                    pMin = Math.min(pMin, c.c); pMax = Math.max(pMax, c.c);
                }
            }
            var span = (pMax - pMin) || pMax || 1;
            pMin -= span * 0.06; pMax += span * 0.06;
            var vMax = 0;
            for (var j = 0; j < view.length; j++) { vMax = Math.max(vMax, view[j].v || 0); }

            function xAt(k) { return PAD_L + (innerW * k / view.length) + (innerW / view.length) / 2; }
            function yPrice(p) { return PAD_T + priceH - ((p - pMin) / (pMax - pMin)) * priceH; }
            var candleW = Math.max(1.5, Math.min(14, (innerW / view.length) * 0.62));
            var volTop = PAD_T + priceH + 6;

            // grid horizontal (5 linhas) + labels de preço
            var grid = '', labels = '';
            for (var g = 0; g <= 4; g++) {
                var gp = pMin + ((pMax - pMin) * g / 4);
                var gy = yPrice(gp);
                grid += '<line x1="' + PAD_L + '" y1="' + gy.toFixed(1) + '" x2="' + (W - PAD_R) + '" y2="' + gy.toFixed(1) + '" stroke="' + cGrid + '" stroke-width="1"/>';
                labels += '<text x="' + (W - PAD_R + 6) + '" y="' + (gy + 3.5).toFixed(1) + '" fill="' + cAxis + '" font-size="10">' + fmtNum(gp, dec()) + '</text>';
            }

            // separador volume
            grid += '<line x1="' + PAD_L + '" y1="' + (volTop - 3) + '" x2="' + (W - PAD_R) + '" y2="' + (volTop - 3) + '" stroke="' + cGrid + '" stroke-width="1"/>';

            var body = '';
            // volume
            for (var k = 0; k < view.length; k++) {
                var vd = view[k];
                var vh = vMax > 0 ? (vd.v / vMax) * (VOL_H - 6) : 0;
                if (vh > 0) {
                    var vColor = vd.c >= vd.o ? cUp : cDown;
                    body += '<rect x="' + (xAt(k) - candleW / 2).toFixed(1) + '" y="' + (volTop + VOL_H - 6 - vh).toFixed(1)
                        + '" width="' + candleW.toFixed(1) + '" height="' + Math.max(1, vh).toFixed(1)
                        + '" rx="1" fill="' + vColor + '" opacity="0.35"/>';
                }
            }

            // preço
            if (opts.type === 'candles') {
                for (var m = 0; m < view.length; m++) {
                    var cd = view[m];
                    var up = cd.c >= cd.o;
                    var col = up ? cUp : cDown;
                    var x = xAt(m);
                    body += '<line x1="' + x.toFixed(1) + '" y1="' + yPrice(cd.h).toFixed(1) + '" x2="' + x.toFixed(1) + '" y2="' + yPrice(cd.l).toFixed(1) + '" stroke="' + col + '" stroke-width="1"/>'
                        + '<rect x="' + (x - candleW / 2).toFixed(1) + '" y="' + yPrice(Math.max(cd.o, cd.c)).toFixed(1)
                        + '" width="' + candleW.toFixed(1) + '" height="' + Math.max(1, Math.abs(yPrice(cd.o) - yPrice(cd.c))).toFixed(1)
                        + '" rx="1" fill="' + col + '"/>';
                }
            } else {
                var pts = [];
                for (var n = 0; n < view.length; n++) { pts.push([xAt(n), yPrice(view[n].c)]); }
                var d = 'M' + pts[0][0].toFixed(1) + ' ' + pts[0][1].toFixed(1);
                for (var p = 1; p < pts.length; p++) {
                    var p0 = pts[p - 1], p1 = pts[p];
                    var cx = (p0[0] + p1[0]) / 2;
                    d += ' C' + cx.toFixed(1) + ' ' + p0[1].toFixed(1) + ' ' + cx.toFixed(1) + ' ' + p1[1].toFixed(1) + ' ' + p1[0].toFixed(1) + ' ' + p1[1].toFixed(1);
                }
                if (opts.type === 'area') {
                    var baseY = PAD_T + priceH;
                    var gid = 'flm-grad-' + Math.random().toString(36).slice(2, 8);
                    body += '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">'
                        + '<stop offset="0" stop-color="' + cLine + '" stop-opacity="0.18"/>'
                        + '<stop offset="1" stop-color="' + cLine + '" stop-opacity="0"/></linearGradient></defs>';
                    body += '<path d="' + d + ' L' + pts[pts.length - 1][0].toFixed(1) + ' ' + baseY + ' L' + pts[0][0].toFixed(1) + ' ' + baseY + ' Z" fill="url(#' + gid + ')"/>';
                }
                body += '<path d="' + d + '" fill="none" stroke="' + cLine + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
                body += '<circle cx="' + pts[pts.length - 1][0].toFixed(1) + '" cy="' + pts[pts.length - 1][1].toFixed(1) + '" r="3.5" fill="' + cBg + '" stroke="' + cLine + '" stroke-width="2"/>';
            }

            // labels de tempo (4-6 marcas)
            var timeLabels = '';
            var step = Math.max(1, Math.floor(view.length / 5));
            for (var t = 0; t < view.length; t += step) {
                var dt = new Date(view[t].t * 1000);
                var label = (view.length > 90)
                    ? dt.toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' })
                    : dt.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
                timeLabels += '<text x="' + xAt(t).toFixed(1) + '" y="' + (H - 6) + '" text-anchor="middle" fill="' + cAxis + '" font-size="10">' + esc(label) + '</text>';
            }

            container.innerHTML =
                '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" role="img" aria-label="Gráfico de preço">'
                + grid + body + labels + timeLabels
                + '<line class="flm-cross" x1="0" y1="' + PAD_T + '" x2="0" y2="' + (volTop + VOL_H - 6) + '" stroke="' + cAxis + '" stroke-width="1" stroke-dasharray="3 3" opacity="0"/>'
                + '</svg>';
            container._flm = {
                view: view, xAt: xAt, candles: candles,
                volTop: volTop, volH: VOL_H, W: W, PAD_L: PAD_L, PAD_R: PAD_R
            };
            bindPointer(container, opts);
        }

        function bindPointer(container2, o) {
            var svg = container2.querySelector('svg');
            var cross = svg.querySelector('.flm-cross');
            var tip = container2.querySelector('.fc-crosshair');
            if (!tip) {
                tip = document.createElement('div');
                tip.className = 'fc-crosshair';
                container2.appendChild(tip);
            }
            var st = container2._flm;
            var hideT = null;

            svg.onmousemove = function (e) {
                var rect = svg.getBoundingClientRect();
                var scale = rect.width / st.W;
                var px = (e.clientX - rect.left) / scale;
                var k = Math.round((px - st.PAD_L - ((st.W - st.PAD_L - st.PAD_R) / st.view.length) / 2)
                    / ((st.W - st.PAD_L - st.PAD_R) / st.view.length));
                k = Math.max(0, Math.min(st.view.length - 1, k));
                var cd = st.view[k];
                cross.setAttribute('x1', st.xAt(k)); cross.setAttribute('x2', st.xAt(k));
                cross.setAttribute('opacity', '0.7');
                var dt = new Date(cd.t * 1000);
                var cls = cd.c >= cd.o ? 'up' : 'down';
                tip.innerHTML = '<strong>' + dt.toLocaleString('pt-BR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) + '</strong><br>'
                    + 'A <strong>' + fmtNum(cd.o, dec()) + '</strong> · M <strong>' + fmtNum(cd.h, dec()) + '</strong><br>'
                    + 'm <strong>' + fmtNum(cd.l, dec()) + '</strong> · F <strong class="' + cls + '">' + fmtNum(cd.c, dec()) + '</strong>'
                    + (cd.v > 0 ? '<br>Vol <strong>' + esc(fmtNum(cd.v, 0)) + '</strong>' : '');
                tip.classList.add('show');
                var cw = container2.clientWidth;
                var left = Math.min(cw - tip.offsetWidth - 8, Math.max(8, px + 14));
                tip.style.left = left + 'px';
                tip.style.top = Math.max(6, (e.clientY - rect.top) - 40) + 'px';
                clearTimeout(hideT);
            };
            svg.onmouseleave = function () {
                cross.setAttribute('opacity', '0');
                tip.classList.remove('show');
            };

            // pan (arraste)
            var dragging = false, dragX = 0;
            svg.onmousedown = function (e) {
                dragging = true; dragX = e.clientX;
                svg.style.cursor = 'grabbing';
                e.preventDefault();
            };
            window.addEventListener('mouseup', function () {
                dragging = false;
                svg.style.cursor = '';
            });
            svg.onmousemove = function (e) {
                if (!dragging) { return; }
                var rect = svg.getBoundingClientRect();
                var scale = rect.width / st.W;
                var dx = e.clientX - dragX;
                var perPx = candles.length ? (st.W - st.PAD_L - st.PAD_R) / (end - start) : 1;
                var shift = Math.round(dx / scale / perPx);
                if (shift !== 0) {
                    start = Math.max(0, Math.min(candles.length - 2, start - shift));
                    end = Math.max(start + 2, Math.min(candles.length, end - shift));
                    dragX = e.clientX;
                    render();
                }
            };

            // zoom (roda)
            svg.onwheel = function (e) {
                e.preventDefault();
                var direction = e.deltaY > 0 ? 1 : -1;
                var size = end - start;
                var newSize = Math.max(8, Math.min(candles.length, size + Math.round(size * 0.15) * direction));
                var center = start + Math.floor(size / 2);
                var newStart = Math.max(0, Math.min(candles.length - newSize, center - Math.floor(newSize / 2)));
                start = newStart;
                end = newStart + newSize;
                render();
            };
        }

        render();

        // re-render no resize
        if (window.FLMResizeObserver === undefined) {
            window.FLMResizeObserver = true;
            var rTimer = null;
            window.addEventListener('resize', function () {
                clearTimeout(rTimer);
                rTimer = setTimeout(function () {
                    document.querySelectorAll('.fchart[data-flm-live]').forEach(function (el) {
                        if (el._flmRender) { el._flmRender(); }
                    });
                }, 200);
            });
        }
        container.dataset.flmLive = '1';
        container._flmRender = render;
        return { render: render };
    };

    /** Mini sparkline (tiles da home). */
    FLM.sparkline = function (container, candles, up) {
        if (!container || !candles || candles.length < 2) { return; }
        var W = 120, H = 28;
        var min = Infinity, max = -Infinity;
        var pts = [];
        for (var i = 0; i < candles.length; i++) {
            min = Math.min(min, candles[i].c); max = Math.max(max, candles[i].c);
        }
        var span = (max - min) || 1;
        for (var k = 0; k < candles.length; k++) {
            pts.push([(k / (candles.length - 1)) * W, H - ((candles[k].c - min) / span) * (H - 4) - 2]);
        }
        var d = 'M' + pts.map(function (p) { return p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' L');
        var color = up ? cssVar('--success-bright', '#059669') : cssVar('--danger-bright', '#E04938');
        container.innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">'
            + '<path d="' + d + ' L' + W + ' ' + H + ' L0 ' + H + ' Z" fill="' + color + '" opacity="0.12"/>'
            + '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="1.6" stroke-linecap="round"/>'
            + '</svg>';
    };

    window.FLMCharts = FLM;
})();
