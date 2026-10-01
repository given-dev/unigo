/* ==========================================================================
   UniGo - charts (Chart.js)
   --------------------------------------------------------------------------
   Applies the UniGo design tokens to Chart.js defaults so dashboards look
   native without repeating options in every view.

   Usage
     UniGo.chart('revenueChart', {
        type: 'bar',
        data: { labels: [...], datasets: [{ label: 'Revenue', data: [...] }] },
        options: { currency: true }
     });
   ========================================================================== */
(function (window, document) {
    'use strict';

    var UniGo = window.UniGo || {};
    var created = {};
    var palette = ['#2563EB', '#0EA5E9', '#16A34A', '#F59E0B', '#DC2626', '#8B5CF6', '#0891B2', '#64748B'];

    function token(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (value || '').trim() || fallback;
    }

    function defaults() {
        var text = token('--text-muted', '#64748B');
        var border = token('--border', '#E2E8F0');
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        padding: 14,
                        color: text,
                        font: { family: token('--font-sans', 'Inter'), size: 12 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, .94)',
                    padding: 10,
                    cornerRadius: 8,
                    titleFont: { family: token('--font-sans', 'Inter'), size: 12, weight: '600' },
                    bodyFont: { family: token('--font-sans', 'Inter'), size: 12 },
                    displayColors: true,
                    boxPadding: 4
                }
            },
            scales: {
                x: {
                    grid: { display: false, drawBorder: false },
                    border: { display: false },
                    ticks: { color: text, font: { size: 11 }, maxRotation: 0, autoSkip: true }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: border, drawBorder: false },
                    border: { display: false },
                    ticks: { color: text, font: { size: 11 }, padding: 8 }
                }
            }
        };
    }

    function merge(base, extra) {
        if (!extra) return base;
        var out = Array.isArray(base) ? base.slice() : Object.assign({}, base);
        Object.keys(extra).forEach(function (key) {
            var value = extra[key];
            if (value && typeof value === 'object' && !Array.isArray(value) &&
                base[key] && typeof base[key] === 'object' && !Array.isArray(base[key])) {
                out[key] = merge(base[key], value);
            } else {
                out[key] = value;
            }
        });
        return out;
    }

    function applyPalette(config) {
        var datasets = (config.data && config.data.datasets) || [];
        datasets.forEach(function (ds, i) {
            if (ds.backgroundColor || ds.borderColor) return;
            var color = palette[i % palette.length];
            if (config.type === 'line') {
                ds.borderColor = color;
                ds.backgroundColor = color + '22';
                ds.tension = ds.tension === undefined ? 0.35 : ds.tension;
                ds.fill = ds.fill === undefined ? true : ds.fill;
                ds.pointRadius = 0;
                ds.pointHoverRadius = 4;
                ds.borderWidth = 2;
            } else if (config.type === 'doughnut' || config.type === 'pie') {
                ds.backgroundColor = datasets.length > 1
                    ? palette
                    : [color, '#E2E8F0'];
                ds.borderWidth = 0;
            } else {
                ds.backgroundColor = color;
                ds.borderRadius = 6;
                ds.maxBarThickness = 34;
            }
        });
    }

    function applyAxisTitles(config) {
        var currency = config.options && config.options.currency;
        var fmt = function (value) {
            return UniGo.format ? UniGo.format.short(value) : String(value);
        };
        ['x', 'y'].forEach(function (axis) {
            var scale = config.options && config.options.scales && config.options.scales[axis];
            if (scale && scale.ticks && scale.ticks.callback) return;
            if (!config.options || !config.options.scales) return;
            config.options.scales[axis] = config.options.scales[axis] || {};
            config.options.scales[axis].ticks = config.options.scales[axis].ticks || {};
            config.options.scales[axis].ticks.callback = function (value) {
                if (typeof value !== 'number') return value;
                if (currency && axis === 'y') {
                    return (window.UNIGO && window.UNIGO.currency ? window.UNIGO.currency + ' ' : '') + UniGo.format.short(value);
                }
                return fmt(value);
            };
        });
    }

    /**
     * @param {string|HTMLCanvasElement} target
     * @param {object} config Chart.js configuration
     */
    UniGo.chart = function (target, config) {
        var canvas = typeof target === 'string' ? document.getElementById(target) : target;
        if (!canvas) return null;

        if (!window.Chart) {
            console.warn('[UniGo] Chart.js is not loaded; skipping chart for', canvas.id || canvas);
            var box = canvas.closest('.chart-box');
            if (box) {
                box.innerHTML = '<div class="empty" style="padding:24px">' +
                    '<span class="empty__icon"><span class="icon" data-icon="bar-chart"></span></span>' +
                    '<p class="empty__title">Chart unavailable</p>' +
                    '<p class="empty__text">Chart.js could not be loaded.</p></div>';
                if (UniGo.upgradeIcons) UniGo.upgradeIcons(box);
            }
            return null;
        }

        var merged = {
            type: config.type || 'line',
            data: config.data || { labels: [], datasets: [] },
            options: merge(defaults(), config.options || {})
        };
        applyPalette(merged);
        applyAxisTitles(merged);

        var instance = new window.Chart(canvas.getContext('2d'), merged);
        var id = canvas.id || 'chart-' + Object.keys(created).length;
        created[id] = instance;
        return instance;
    };

    UniGo.chart.destroy = function (id) {
        if (created[id]) {
            created[id].destroy();
            delete created[id];
        }
    };

    /**
     * Sparkline helper: a tiny inline chart for stat tiles.
     */
    UniGo.sparkline = function (target, values, options) {
        options = options || {};
        var canvas = typeof target === 'string' ? document.getElementById(target) : target;
        if (!canvas) return null;
        return UniGo.chart(canvas, {
            type: 'line',
            data: {
                labels: values.map(function (_, i) { return i + 1; }),
                datasets: [{
                    data: values,
                    borderColor: options.color || token('--primary', '#2563EB'),
                    backgroundColor: (options.color || token('--primary', '#2563EB')) + '1F',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } },
                elements: { line: { borderJoinStyle: 'round' } }
            }
        });
    };

    window.UniGo = UniGo;
})(window, document);
