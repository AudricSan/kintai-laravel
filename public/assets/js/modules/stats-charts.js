(function () {
    'use strict';

    var cfg = {};
    var profitCfg = {};

    // Données des graphiques (JSON émis par la page) ; relues par refresh() quand un widget est ajouté sans
    // rechargement (dashboard-layout.js).
    function readData() {
        cfg = {};
        profitCfg = {};
        try {
            var el = document.getElementById('kintai-stats-data');
            if (el) cfg = JSON.parse(el.textContent);
        } catch (e) { /* pas de données */ }
        try {
            var profEl = document.getElementById('kintai-profit-data');
            if (profEl) profitCfg = JSON.parse(profEl.textContent);
        } catch (e) { /* pas de données rentabilité */ }
    }
    readData();

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    }

    function fmtNum(v) {
        if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
        if (v >= 10000)   return (v / 1000).toFixed(0) + 'k';
        if (v >= 1000)    return (v / 1000).toFixed(1) + 'k';
        return v % 1 === 0 ? String(v) : v.toFixed(1);
    }

    function baseChart(canvas, H) {
        var dpr = window.devicePixelRatio || 1;
        var W   = canvas.offsetWidth || 400;
        canvas.width  = W * dpr;
        canvas.height = H * dpr;
        canvas.style.width  = W + 'px';
        canvas.style.height = H + 'px';
        var ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        return { ctx: ctx, W: W, H: H };
    }

    function drawGrid(ctx, pad, cW, cH, max, GRID) {
        var border = cssVar('--color-border', '#e5e7eb');
        var muted  = cssVar('--color-text-muted', '#6b7280');
        ctx.strokeStyle = border;
        ctx.lineWidth   = 1;
        ctx.fillStyle   = muted;
        ctx.font        = '10px ' + cssVar('--font-sans', 'sans-serif');
        ctx.textAlign   = 'right';
        for (var i = 0; i <= GRID; i++) {
            var y = pad.top + cH - (i / GRID) * cH;
            ctx.beginPath();
            ctx.moveTo(pad.left, y);
            ctx.lineTo(pad.left + cW, y);
            ctx.stroke();
            ctx.fillText(fmtNum(max * i / GRID), pad.left - 6, y + 3);
        }
        return muted;
    }

    function drawLineChart(canvas, labels, values, color) {
        if (!labels.length) return;

        var base = baseChart(canvas, 180);
        var ctx  = base.ctx;
        var W    = base.W;
        var H    = base.H;

        var pad  = { top: 16, right: 16, bottom: 32, left: 52 };
        var cW   = W - pad.left - pad.right;
        var cH   = H - pad.top - pad.bottom;
        var max  = Math.max.apply(null, values) || 1;
        var muted = drawGrid(ctx, pad, cW, cH, max, 4);

        ctx.clearRect(0, 0, W, H);
        drawGrid(ctx, pad, cW, cH, max, 4);

        var pts = values.map(function (v, i) {
            return {
                x: pad.left + (values.length < 2 ? cW / 2 : (i / (values.length - 1)) * cW),
                y: pad.top + cH - (v / max) * cH
            };
        });

        var grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + cH);
        grad.addColorStop(0, color + '33');
        grad.addColorStop(1, color + '05');
        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        for (var j = 1; j < pts.length; j++) ctx.lineTo(pts[j].x, pts[j].y);
        ctx.lineTo(pts[pts.length - 1].x, pad.top + cH);
        ctx.lineTo(pts[0].x, pad.top + cH);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        for (var k = 1; k < pts.length; k++) ctx.lineTo(pts[k].x, pts[k].y);
        ctx.strokeStyle = color;
        ctx.lineWidth   = 2;
        ctx.lineJoin    = 'round';
        ctx.stroke();

        for (var m = 0; m < pts.length; m++) {
            ctx.beginPath();
            ctx.arc(pts[m].x, pts[m].y, 3, 0, Math.PI * 2);
            ctx.fillStyle = color;
            ctx.fill();
        }

        ctx.fillStyle = muted;
        ctx.textAlign = 'center';
        var step = Math.max(1, Math.ceil(labels.length / 10));
        for (var n = 0; n < labels.length; n++) {
            if (n % step === 0 || n === labels.length - 1) {
                ctx.fillText(labels[n], pts[n].x, H - 6);
            }
        }
    }

    function drawBarChart(canvas, labels, values, color) {
        if (!labels.length) return;

        var base = baseChart(canvas, 180);
        var ctx  = base.ctx;
        var W    = base.W;
        var H    = base.H;

        var pad  = { top: 16, right: 16, bottom: 32, left: 52 };
        var cW   = W - pad.left - pad.right;
        var cH   = H - pad.top - pad.bottom;

        var nums  = values.filter(function (v) { return v !== null && v !== undefined; });
        var max   = Math.max.apply(null, nums) || 1;
        var muted = drawGrid(ctx, pad, cW, cH, max, 4);

        var slotW = cW / labels.length;
        var barW  = Math.max(2, slotW * 0.65);
        var barOff = (slotW - barW) / 2;
        var step  = Math.max(1, Math.ceil(labels.length / 10));

        ctx.fillStyle = color + 'cc';
        for (var i = 0; i < labels.length; i++) {
            var v = values[i];
            if (v === null || v === undefined || v <= 0) continue;
            var x = pad.left + i * slotW + barOff;
            var h = (v / max) * cH;
            ctx.fillRect(x, pad.top + cH - h, barW, h);
        }

        ctx.fillStyle = muted;
        ctx.textAlign = 'center';
        for (var n = 0; n < labels.length; n++) {
            if (n % step === 0 || n === labels.length - 1) {
                var xc = pad.left + n * slotW + slotW / 2;
                ctx.fillText(labels[n], xc, H - 6);
            }
        }
    }

    // Données de la page rentabilité (si présentes)
    function init() {
        // ── Page statistiques ──────────────────────────────────────────────
        var weekCanvas = document.getElementById('chart-weekly-hours');
        if (weekCanvas && cfg.hoursByWeek) {
            drawLineChart(
                weekCanvas,
                Object.keys(cfg.hoursByWeek),
                Object.values(cfg.hoursByWeek).map(Number),
                '#8b5cf6'
            );
        }

        var monthCanvas = document.getElementById('chart-monthly-trend');
        if (monthCanvas) {
            if (cfg.hasCost && cfg.costByMonth && Object.keys(cfg.costByMonth).length) {
                drawLineChart(
                    monthCanvas,
                    Object.keys(cfg.costByMonth),
                    Object.values(cfg.costByMonth).map(Number),
                    '#10b981'
                );
            } else if (cfg.hoursByMonth && Object.keys(cfg.hoursByMonth).length) {
                drawLineChart(
                    monthCanvas,
                    Object.keys(cfg.hoursByMonth),
                    Object.values(cfg.hoursByMonth).map(Number),
                    '#10b981'
                );
            }
        }

        // ── Page rentabilité : graphiques mensuels ─────────────────────────
        var profSalesCanvas = document.getElementById('chart-profit-sales');
        if (profSalesCanvas && profitCfg.salesByMonth) {
            drawLineChart(
                profSalesCanvas,
                Object.keys(profitCfg.salesByMonth),
                Object.values(profitCfg.salesByMonth).map(Number),
                '#4f46e5'
            );
        }

        var profLcCanvas = document.getElementById('chart-profit-lc-pct');
        if (profLcCanvas && profitCfg.lcPctByMonth) {
            drawLineChart(
                profLcCanvas,
                Object.keys(profitCfg.lcPctByMonth),
                Object.values(profitCfg.lcPctByMonth).map(Number),
                '#f59e0b'
            );
        }

        var profLaborCanvas = document.getElementById('chart-profit-labor');
        if (profLaborCanvas && profitCfg.laborByMonth) {
            drawBarChart(
                profLaborCanvas,
                Object.keys(profitCfg.laborByMonth),
                Object.values(profitCfg.laborByMonth).map(Number),
                '#10b981'
            );
        }

        var profCustCanvas = document.getElementById('chart-profit-customers');
        if (profCustCanvas && profitCfg.customersByMonth) {
            drawBarChart(
                profCustCanvas,
                Object.keys(profitCfg.customersByMonth),
                Object.values(profitCfg.customersByMonth).map(Number),
                '#6366f1'
            );
        }

        // ── Page rentabilité : graphiques journaliers ──────────────────────
        var dailySalesCanvas = document.getElementById('chart-profit-daily-sales');
        if (dailySalesCanvas && profitCfg.salesByDay) {
            drawBarChart(
                dailySalesCanvas,
                Object.keys(profitCfg.salesByDay),
                Object.values(profitCfg.salesByDay).map(Number),
                '#3b82f6'
            );
        }

        var dailyLcCanvas = document.getElementById('chart-profit-daily-lc');
        if (dailyLcCanvas && profitCfg.lcPctByDay) {
            var lcKeys = Object.keys(profitCfg.lcPctByDay);
            var lcVals = lcKeys.map(function (k) {
                var v = profitCfg.lcPctByDay[k];
                return v !== null && v !== undefined ? Number(v) : null;
            });
            var filteredKeys = [], filteredVals = [];
            for (var i = 0; i < lcKeys.length; i++) {
                if (lcVals[i] !== null) {
                    filteredKeys.push(lcKeys[i]);
                    filteredVals.push(lcVals[i]);
                }
            }
            if (filteredKeys.length) {
                drawLineChart(dailyLcCanvas, filteredKeys, filteredVals, '#f97316');
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(init, 120);
    });

    window.kintaiStatsCharts = { refresh: function () { readData(); init(); } };
})();
