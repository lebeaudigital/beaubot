(function () {
    'use strict';

    var data = window.beaubotStatsData;
    if (!data || !data.length) return;

    var canvas = document.getElementById('beaubot-cost-chart');
    if (!canvas) return;

    // Regrouper par modèle
    var models = {};
    var allDays = {};

    data.forEach(function (row) {
        allDays[row.day] = true;
        if (!models[row.model]) {
            models[row.model] = {};
        }
        models[row.model][row.day] = (models[row.model][row.day] || 0) + row.cost;
    });

    var labels = Object.keys(allDays).sort();

    var palette = {
        'gpt-4o':      { border: '#6366f1', bg: 'rgba(99, 102, 241, 0.1)' },
        'gpt-4o-mini': { border: '#f59e0b', bg: 'rgba(245, 158, 11, 0.1)' },
        'unknown':     { border: '#94a3b8', bg: 'rgba(148, 163, 184, 0.1)' },
    };

    var fallbackColors = [
        { border: '#10b981', bg: 'rgba(16, 185, 129, 0.1)' },
        { border: '#ef4444', bg: 'rgba(239, 68, 68, 0.1)' },
        { border: '#8b5cf6', bg: 'rgba(139, 92, 246, 0.1)' },
    ];
    var fallbackIdx = 0;

    var datasets = [];
    Object.keys(models).sort().forEach(function (modelName) {
        var color = palette[modelName];
        if (!color) {
            color = fallbackColors[fallbackIdx % fallbackColors.length];
            fallbackIdx++;
        }

        var points = labels.map(function (day) {
            return models[modelName][day] || 0;
        });

        datasets.push({
            label: modelName === 'unknown' ? 'Inconnu' : modelName,
            data: points,
            borderColor: color.border,
            backgroundColor: color.bg,
            borderWidth: 2,
            fill: true,
            tension: 0.3,
            pointRadius: labels.length > 90 ? 0 : 3,
            pointHoverRadius: 5,
        });
    });

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                    },
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.dataset.label + ': $' + ctx.parsed.y.toFixed(5);
                        },
                    },
                },
            },
            scales: {
                x: {
                    type: 'category',
                    ticks: {
                        maxTicksLimit: 15,
                        maxRotation: 45,
                    },
                    grid: {
                        display: false,
                    },
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (value) {
                            return '$' + value.toFixed(4);
                        },
                    },
                    title: {
                        display: true,
                        text: 'Coût ($)',
                    },
                },
            },
        },
    });
})();
