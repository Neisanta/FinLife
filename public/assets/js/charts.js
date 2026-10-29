/* ==============================================================
   FinLife — Gráficos em SVG puro (sem bibliotecas externas)
   - FLCharts.donut(containerId, legendId, data)   → gastos por categoria
   - FLCharts.area(containerId, legendId, data)    → evolução receitas × despesas
   - FLCharts.bars(containerId, legendId, data)    → barras agrupadas (legado)
   Cores lidas dos tokens CSS · tooltips · re-render em resize/tema.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL || {};

    function esc(v) { return FL.esc ? FL.esc(v) : String(v == null ? '' : v); }

    /** Lê um token CSS da raiz (respeita o tema atual). */
    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        v = (v || '').trim();
        return v || fallback;
    }

    function svgEl(html) {
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        return wrap.firstElementChild;
    }

    /** Formato monetário compacto para eixos: R$ 1,2 mil / R$ 3,4 mi */
    FL.moneyCompact = function (value) {
        var v = Math.abs(Number(value) || 0);
        if (v >= 1000000) { return 'R$ ' + (v / 1000000).toFixed(1).replace('.', ',').replace(',0', '') + ' mi'; }
        if (v >= 1000) { return 'R$ ' + (v / 1000).toFixed(1).replace('.', ',').replace(',0', '') + ' mil'; }
        return FL.money ? FL.money(v).replace('R$ ', '') : String(v);
    };

    // ------------------------------------------------------------
    // Tooltips (um por container)
    // ------------------------------------------------------------
    function tooltipFor(container) {
        var tip = container.querySelector('.chart-tooltip');
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'chart-tooltip';
            container.appendChild(tip);
        }
        return tip;
    }

    function showTip(container, tip, html, x, y) {
        tip.innerHTML = html;
        tip.classList.add('show');
        var pad = 10;
        var w = tip.offsetWidth, h = tip.offsetHeight;
        var cw = container.clientWidth || w;
        var ch = container.clientHeight || h;
        var left = x + pad;
        if (left + w + pad > cw) { left = x - w - pad; }
        if (left < 0) { left = Math.max(4, (cw - w) / 2); }
        var top = y - h / 2;
        if (top < 2) { top = 2; }
        if (top + h > ch - 2) { top = Math.max(2, ch - h - 2); }
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }

    function hideTip(tip) { tip.classList.remove('show'); }

    // ------------------------------------------------------------
    // Registro para re-render (resize / troca de tema)
    // ------------------------------------------------------------
    var registry = {};

    function register(id, fn, args) {
        registry[id] = { fn: fn, args: args };
    }

    function rerenderAll() {
        Object.keys(registry).forEach(function (id) {
            var entry = registry[id];
            var el = document.getElementById(id);
            if (el) { entry.fn.apply(null, entry.args); }
        });
    }

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(rerenderAll, 180);
    });
    document.addEventListener('fl:themechange', function () { rerenderAll(); });

    var FLCharts = {};

    // ------------------------------------------------------------
    // Donut: data = [{name, color, total, pct}]
    // ------------------------------------------------------------
    FLCharts.donut = function (containerId, legendId, data) {
        var container = document.getElementById(containerId);
        var legend = document.getElementById(legendId);
        if (!container) { return; }
        register(containerId, FLCharts.donut, [containerId, legendId, data]);

        data = (data || []).filter(function (d) { return Number(d.total) > 0; });

        if (!legend) {
            legend = document.createElement('div');
            legend.id = legendId || 'fl-donut-legend';
            legend.className = 'legend';
            container.parentNode.insertBefore(legend, container.nextSibling);
        }
        legend.innerHTML = '';

        if (!data.length) {
            container.innerHTML = '<p class="empty">Sem dados neste mês.</p>';
            return;
        }

        var size = 220, cx = 110, cy = 110, r = 84, stroke = 22;
        var total = data.reduce(function (acc, d) { return acc + Number(d.total); }, 0);
        var textColor = cssVar('--text', '#1B1730');
        var mutedColor = cssVar('--muted', '#6F6885');
        var circumference = 2 * Math.PI * r;
        var gap = data.length > 1 ? 4 : 0;
        var offset = 0;

        var segments = '';
        var handlers = [];
        data.forEach(function (d) {
            var frac = Number(d.total) / total;
            var len = Math.max(0.5, frac * circumference - gap);
            var color = d.color || cssVar('--primary', '#7C3AED');
            segments += '<circle class="donut-seg" cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none"'
                + ' stroke="' + esc(color) + '" stroke-width="' + stroke + '"'
                + ' stroke-dasharray="' + len.toFixed(2) + ' ' + (circumference - len).toFixed(2) + '"'
                + ' stroke-dashoffset="' + (-offset - gap / 2).toFixed(2) + '"'
                + ' transform="rotate(-90 ' + cx + ' ' + cy + ')"></circle>';
            handlers.push({ d: d, offset: offset, len: len });
            offset += frac * circumference;
        });

        var html = '<svg viewBox="0 0 ' + size + ' ' + size + '" width="' + size + '" height="' + size + '" role="img" aria-label="Gastos por categoria" style="max-width:232px">'
            + segments
            + '<text x="' + cx + '" y="' + (cy - 8) + '" text-anchor="middle" fill="' + mutedColor + '" font-size="10.5" font-weight="500" letter-spacing="0.4">Total do mês</text>'
            + '<text x="' + cx + '" y="' + (cy + 14) + '" text-anchor="middle" fill="' + textColor + '" font-size="16.5" font-weight="700" letter-spacing="-0.3">'
            + esc(FL.money ? FL.money(total) : total) + '</text></svg>';
        container.innerHTML = '';
        var svg = svgEl(html);
        container.appendChild(svg);

        // Tooltip (depois do innerHTML='' para não ser removido)
        var tip = tooltipFor(container);

        // Tooltips por segmento
        svg.querySelectorAll('.donut-seg').forEach(function (seg, i) {
            var info = handlers[i];
            seg.addEventListener('mouseenter', function (e) {
                var rect = svg.getBoundingClientRect();
                var cRect = container.getBoundingClientRect();
                var x = rect.left - cRect.left + cx * (rect.width / size);
                var y = rect.top - cRect.top + cy * (rect.height / size);
                var html2 = '<div class="tt-title">' + esc(info.d.name) + '</div>'
                    + '<strong>' + esc(FL.money ? FL.money(info.d.total) : info.d.total) + '</strong>'
                    + ' <span style="opacity:.65">· ' + esc(String(info.d.pct).replace('.', ',').replace(/,0$/, '')) + '%</span>';
                showTip(container, tip, html2, x, y);
            });
            seg.addEventListener('mouseleave', function () { hideTip(tip); });
        });

        // Legenda
        data.forEach(function (d) {
            var item = document.createElement('div');
            item.className = 'legend-item';
            var sw = document.createElement('span');
            sw.className = 'legend-swatch';
            sw.style.background = d.color || cssVar('--primary', '#7C3AED');
            var label = document.createElement('span');
            label.textContent = d.name + ' — ';
            var strong = document.createElement('strong');
            strong.textContent = (FL.money ? FL.money(d.total) : String(d.total));
            var pct = document.createElement('span');
            pct.className = 'legend-pct';
            pct.textContent = '(' + String(d.pct).replace('.', ',') + '%)';
            item.appendChild(sw);
            item.appendChild(label);
            item.appendChild(strong);
            item.appendChild(pct);
            legend.appendChild(item);
        });
    };

    // ------------------------------------------------------------
    // Curva suave (Catmull-Rom → Bezier)
    // ------------------------------------------------------------
    function smoothPath(pts, tension) {
        var t = tension || 6;
        if (pts.length < 2) { return ''; }
        var d = 'M' + pts[0][0].toFixed(1) + ' ' + pts[0][1].toFixed(1);
        for (var i = 0; i < pts.length - 1; i++) {
            var p0 = pts[Math.max(0, i - 1)];
            var p1 = pts[i];
            var p2 = pts[i + 1];
            var p3 = pts[Math.min(pts.length - 1, i + 2)];
            var c1x = p1[0] + (p2[0] - p0[0]) / t;
            var c1y = p1[1] + (p2[1] - p0[1]) / t;
            var c2x = p2[0] - (p3[0] - p1[0]) / t;
            var c2y = p2[1] - (p3[1] - p1[1]) / t;
            d += ' C' + c1x.toFixed(1) + ' ' + c1y.toFixed(1)
                + ' ' + c2x.toFixed(1) + ' ' + c2y.toFixed(1)
                + ' ' + p2[0].toFixed(1) + ' ' + p2[1].toFixed(1);
        }
        return d;
    }

    // ------------------------------------------------------------
    // Area/line: evolução — data = [{month, income, expense}]
    // ------------------------------------------------------------
    FLCharts.area = function (containerId, legendId, data) {
        var container = document.getElementById(containerId);
        var legend = document.getElementById(legendId);
        if (!container) { return; }
        register(containerId, FLCharts.area, [containerId, legendId, data]);

        data = data || [];
        if (!legend) {
            legend = document.createElement('div');
            legend.id = legendId || 'fl-area-legend';
            legend.className = 'legend row';
            container.parentNode.insertBefore(legend, container.nextSibling);
        }
        legend.innerHTML = '';

        var hasData = data.some(function (d) { return Number(d.income) > 0 || Number(d.expense) > 0; });
        if (!hasData) {
            container.innerHTML = '<p class="empty">Sem histórico ainda.</p>';
            return;
        }

        var W = Math.max(320, container.clientWidth || 560);
        var H = 248;
        var padL = 48, padR = 14, padT = 16, padB = 28;
        var innerW = W - padL - padR;
        var innerH = H - padT - padB;

        var maxVal = 0;
        data.forEach(function (d) {
            maxVal = Math.max(maxVal, Number(d.income) || 0, Number(d.expense) || 0);
        });
        if (maxVal <= 0) { maxVal = 1; }

        var incomeColor = cssVar('--success-bright', '#059669');
        var expenseColor = cssVar('--danger-bright', '#E04938');
        var gridColor = cssVar('--chart-grid', '#EEEAF6');
        var axisColor = cssVar('--chart-axis', '#9D97B2');

        var colW = innerW / data.length;
        var xAt = function (i) { return padL + colW * i + colW / 2; };
        var yAt = function (v) { return padT + innerH - (v / maxVal) * innerH; };

        // Grid horizontal + rótulos
        var grid = '';
        var divisions = 4;
        for (var g = 0; g <= divisions; g++) {
            var gv = maxVal * (g / divisions);
            var gy = yAt(gv);
            grid += '<line x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (W - padR) + '" y2="' + gy.toFixed(1)
                + '" stroke="' + gridColor + '" stroke-width="1"/>'
                + '<text x="' + (padL - 9) + '" y="' + (gy + 3.5).toFixed(1) + '" text-anchor="end" fill="' + axisColor + '" font-size="10">'
                + esc(g === 0 ? '0' : FL.moneyCompact(gv)) + '</text>';
        }

        // Séries
        var incPts = [], expPts = [];
        data.forEach(function (d, i) {
            incPts.push([xAt(i), yAt(Number(d.income) || 0)]);
            expPts.push([xAt(i), yAt(Number(d.expense) || 0)]);
        });

        var incLine = smoothPath(incPts);
        var expLine = smoothPath(expPts);
        var baseY = padT + innerH;
        var incArea = incLine + ' L' + incPts[incPts.length - 1][0].toFixed(1) + ' ' + baseY + ' L' + incPts[0][0].toFixed(1) + ' ' + baseY + ' Z';
        var expArea = expLine + ' L' + expPts[expPts.length - 1][0].toFixed(1) + ' ' + baseY + ' L' + expPts[0][0].toFixed(1) + ' ' + baseY + ' Z';

        // Dots
        var dots = '';
        data.forEach(function (d, i) {
            if (Number(d.income) > 0) {
                dots += '<circle cx="' + incPts[i][0].toFixed(1) + '" cy="' + incPts[i][1].toFixed(1) + '" r="3.4" fill="' + cssVar('--surface', '#fff') + '" stroke="' + incomeColor + '" stroke-width="2"/>';
            }
            if (Number(d.expense) > 0) {
                dots += '<circle cx="' + expPts[i][0].toFixed(1) + '" cy="' + expPts[i][1].toFixed(1) + '" r="3.4" fill="' + cssVar('--surface', '#fff') + '" stroke="' + expenseColor + '" stroke-width="2"/>';
            }
        });

        // Rótulos de mês
        var xlabels = '';
        data.forEach(function (d, i) {
            xlabels += '<text x="' + xAt(i).toFixed(1) + '" y="' + (H - 9) + '" text-anchor="middle" fill="' + axisColor + '" font-size="10.5">'
                + esc(FL.monthLabel ? FL.monthLabel(d.month) : d.month) + '</text>';
        });

        var crosshair = '<line id="fl-cross-' + containerId + '" x1="0" y1="' + padT + '" x2="0" y2="' + baseY + '" stroke="' + axisColor + '" stroke-width="1" stroke-dasharray="3 3" opacity="0"/>';

        // Zonas de hover (dentro do próprio SVG — namespace correto para eventos)
        var zones = '';
        data.forEach(function (d, i) {
            zones += '<rect x="' + (padL + colW * i).toFixed(1) + '" y="0" width="' + colW.toFixed(1) + '" height="' + H + '" fill="transparent" class="fl-hover-zone"/>';
        });

        var uid = 'fl-g-' + containerId;
        var html = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" role="img" aria-label="Evolução financeira mensal">'
            + '<defs>'
            + '<linearGradient id="' + uid + '-inc" x1="0" y1="0" x2="0" y2="1">'
            + '<stop offset="0" stop-color="' + incomeColor + '" stop-opacity="0.16"/>'
            + '<stop offset="1" stop-color="' + incomeColor + '" stop-opacity="0"/>'
            + '</linearGradient>'
            + '<linearGradient id="' + uid + '-exp" x1="0" y1="0" x2="0" y2="1">'
            + '<stop offset="0" stop-color="' + expenseColor + '" stop-opacity="0.13"/>'
            + '<stop offset="1" stop-color="' + expenseColor + '" stop-opacity="0"/>'
            + '</linearGradient>'
            + '</defs>'
            + grid
            + '<path d="' + expArea + '" fill="url(#' + uid + '-exp)"/>'
            + '<path d="' + incArea + '" fill="url(#' + uid + '-inc)"/>'
            + '<path d="' + expLine + '" fill="none" stroke="' + expenseColor + '" stroke-width="2.25" stroke-linecap="round"/>'
            + '<path d="' + incLine + '" fill="none" stroke="' + incomeColor + '" stroke-width="2.25" stroke-linecap="round"/>'
            + dots
            + xlabels
            + crosshair
            + zones
            + '</svg>';

        container.innerHTML = '';
        var svg = svgEl(html);
        container.appendChild(svg);

        // Tooltip (depois do innerHTML='' para não ser removido)
        var tip = tooltipFor(container);

        // Hover por coluna (crosshair + tooltip)
        var cross = svg.querySelector('#fl-cross-' + containerId);
        svg.querySelectorAll('.fl-hover-zone').forEach(function (zone, i) {
            var d = data[i];
            zone.addEventListener('mouseenter', function (e) {
                var svgRect = svg.getBoundingClientRect();
                var cRect = container.getBoundingClientRect();
                var x = svgRect.left - cRect.left + xAt(i) * (svgRect.width / W);
                var y = svgRect.top - cRect.top + Math.min(incPts[i][1], expPts[i][1]) * (svgRect.height / H);
                cross.setAttribute('x1', xAt(i));
                cross.setAttribute('x2', xAt(i));
                cross.setAttribute('opacity', '0.7');
                var inc = Number(d.income) || 0, exp = Number(d.expense) || 0;
                var html2 = '<div class="tt-title">' + esc(FL.monthLabel ? FL.monthLabel(d.month) : d.month) + '</div>'
                    + '<div style="display:flex;align-items:center;gap:6px;margin:1px 0">'
                    + '<span style="width:8px;height:8px;border-radius:3px;background:' + incomeColor + ';display:inline-block"></span>'
                    + 'Receitas <strong>' + esc(FL.money ? FL.money(inc) : inc) + '</strong></div>'
                    + '<div style="display:flex;align-items:center;gap:6px">'
                    + '<span style="width:8px;height:8px;border-radius:3px;background:' + expenseColor + ';display:inline-block"></span>'
                    + 'Despesas <strong>' + esc(FL.money ? FL.money(exp) : exp) + '</strong></div>';
                showTip(container, tip, html2, x, y);
            });
            zone.addEventListener('mouseleave', function () {
                cross.setAttribute('opacity', '0');
                hideTip(tip);
            });
        });

        // Legenda
        [{ color: incomeColor, label: 'Receitas' }, { color: expenseColor, label: 'Despesas' }].forEach(function (it) {
            var item = document.createElement('div');
            item.className = 'legend-item';
            var sw = document.createElement('span');
            sw.className = 'legend-swatch';
            sw.style.background = it.color;
            var label = document.createElement('span');
            label.textContent = it.label;
            item.appendChild(sw);
            item.appendChild(label);
            legend.appendChild(item);
        });
    };

    // ------------------------------------------------------------
    // Barras agrupadas (legado): data = [{month, income, expense}]
    // ------------------------------------------------------------
    FLCharts.bars = function (containerId, legendId, data) {
        var container = document.getElementById(containerId);
        var legend = document.getElementById(legendId);
        if (!container) { return; }
        register(containerId, FLCharts.bars, [containerId, legendId, data]);

        data = data || [];
        if (!legend) {
            legend = document.createElement('div');
            legend.id = legendId || 'fl-bars-legend';
            legend.className = 'legend row';
            container.parentNode.insertBefore(legend, container.nextSibling);
        }
        legend.innerHTML = '';

        var hasData = data.some(function (d) { return Number(d.income) > 0 || Number(d.expense) > 0; });
        if (!hasData) {
            container.innerHTML = '<p class="empty">Sem histórico ainda.</p>';
            return;
        }

        var W = Math.max(320, container.clientWidth || 460), H = 232;
        var padL = 48, padR = 10, padT = 12, padB = 26;
        var innerW = W - padL - padR, innerH = H - padT - padB;
        var maxVal = 0;
        data.forEach(function (d) { maxVal = Math.max(maxVal, Number(d.income) || 0, Number(d.expense) || 0); });
        if (maxVal <= 0) { maxVal = 1; }

        var incomeColor = cssVar('--success-bright', '#059669');
        var expenseColor = cssVar('--danger-bright', '#E04938');
        var gridColor = cssVar('--chart-grid', '#EEEAF6');
        var axisColor = cssVar('--chart-axis', '#9D97B2');

        var groupW = innerW / data.length;
        var barW = Math.min(20, groupW * 0.3);
        var gap = 4;

        var bars = '';
        data.forEach(function (d, i) {
            var gx = padL + i * groupW;
            var center = gx + groupW / 2;
            var incH = (Number(d.income) || 0) / maxVal * innerH;
            var expH = (Number(d.expense) || 0) / maxVal * innerH;
            if (Number(d.income) > 0) {
                bars += '<rect x="' + (center - barW - gap / 2).toFixed(1) + '" y="' + (padT + innerH - incH).toFixed(1)
                    + '" width="' + barW.toFixed(1) + '" height="' + Math.max(2, incH).toFixed(1)
                    + '" rx="4.5" fill="' + incomeColor + '"><title>' + esc(FL.monthLabel ? FL.monthLabel(d.month) : d.month)
                    + ' — Receitas: ' + esc(FL.money ? FL.money(d.income) : d.income) + '</title></rect>';
            }
            if (Number(d.expense) > 0) {
                bars += '<rect x="' + (center + gap / 2).toFixed(1) + '" y="' + (padT + innerH - expH).toFixed(1)
                    + '" width="' + barW.toFixed(1) + '" height="' + Math.max(2, expH).toFixed(1)
                    + '" rx="4.5" fill="' + expenseColor + '"><title>' + esc(FL.monthLabel ? FL.monthLabel(d.month) : d.month)
                    + ' — Despesas: ' + esc(FL.money ? FL.money(d.expense) : d.expense) + '</title></rect>';
            }
            bars += '<text x="' + center.toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" fill="' + axisColor + '" font-size="10.5">'
                + esc(FL.monthLabel ? FL.monthLabel(d.month) : d.month) + '</text>';
        });

        var grid = '';
        for (var g = 0; g <= 4; g++) {
            var gy = padT + innerH - innerH * g / 4;
            grid += '<line x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (W - padR) + '" y2="' + gy.toFixed(1) + '" stroke="' + gridColor + '" stroke-width="1"/>'
                + '<text x="' + (padL - 9) + '" y="' + (gy + 3.5).toFixed(1) + '" text-anchor="end" fill="' + axisColor + '" font-size="10">'
                + esc(g === 0 ? '0' : FL.moneyCompact(maxVal * g / 4)) + '</text>';
        }

        var html = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" role="img" aria-label="Evolução financeira mensal">'
            + grid + bars + '</svg>';
        container.innerHTML = '';
        container.appendChild(svgEl(html));

        [{ color: incomeColor, label: 'Receitas' }, { color: expenseColor, label: 'Despesas' }].forEach(function (it) {
            var item = document.createElement('div');
            item.className = 'legend-item';
            var sw = document.createElement('span');
            sw.className = 'legend-swatch';
            sw.style.background = it.color;
            var label = document.createElement('span');
            label.textContent = it.label;
            item.appendChild(sw);
            item.appendChild(label);
            legend.appendChild(item);
        });
    };

    // ------------------------------------------------------------
    // Barras de série única: data = [{month, total}]
    // ------------------------------------------------------------
    FLCharts.barsSingle = function (containerId, data, opts) {
        var container = document.getElementById(containerId);
        if (!container) { return; }
        register(containerId, FLCharts.barsSingle, [containerId, data, opts]);

        data = data || [];
        opts = opts || {};
        var hasData = data.some(function (d) { return Number(d.total) > 0; });
        if (!hasData) {
            container.innerHTML = '<p class="empty">Sem dados neste período.</p>';
            return;
        }

        var W = Math.max(320, container.clientWidth || 560), H = 220;
        var padL = 48, padR = 10, padT = 12, padB = 26;
        var innerW = W - padL - padR, innerH = H - padT - padB;
        var maxVal = 0;
        data.forEach(function (d) { maxVal = Math.max(maxVal, Number(d.total) || 0); });
        if (maxVal <= 0) { maxVal = 1; }

        var barColor = cssVar('--primary', '#7C3AED');
        var gridColor = cssVar('--chart-grid', '#EEEAF6');
        var axisColor = cssVar('--chart-axis', '#9D97B2');
        var colW = innerW / data.length;
        var barW = Math.min(34, colW * 0.52);

        var grid = '';
        for (var g = 0; g <= 4; g++) {
            var gy = padT + innerH - innerH * g / 4;
            grid += '<line x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (W - padR) + '" y2="' + gy.toFixed(1) + '" stroke="' + gridColor + '" stroke-width="1"/>'
                + '<text x="' + (padL - 9) + '" y="' + (gy + 3.5).toFixed(1) + '" text-anchor="end" fill="' + axisColor + '" font-size="10">'
                + esc(g === 0 ? '0' : FL.moneyCompact(maxVal * g / 4)) + '</text>';
        }

        var bars = '';
        data.forEach(function (d, i) {
            var cx = padL + colW * i + colW / 2;
            var h = (Number(d.total) || 0) / maxVal * innerH;
            if (Number(d.total) > 0) {
                bars += '<rect x="' + (cx - barW / 2).toFixed(1) + '" y="' + (padT + innerH - h).toFixed(1)
                    + '" width="' + barW.toFixed(1) + '" height="' + Math.max(2, h).toFixed(1)
                    + '" rx="5" fill="' + barColor + '"><title>' + esc(FL.monthLabel ? FL.monthLabel(d.month) : d.month)
                    + ' — ' + esc(FL.money ? FL.money(d.total) : d.total) + '</title></rect>';
            }
            bars += '<text x="' + cx.toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" fill="' + axisColor + '" font-size="10.5">'
                + esc(FL.monthLabel ? FL.monthLabel(d.month) : d.month) + '</text>';
        });

        var html = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" role="img" aria-label="' + esc(opts.label || 'Evolução') + '">'
            + grid + bars + '</svg>';
        container.innerHTML = '';
        container.appendChild(svgEl(html));
    };

    window.FLCharts = FLCharts;
})();
