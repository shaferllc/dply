import './bootstrap';

/**
 * Livewire 3 ships Alpine; do not import `alpinejs` or call `Alpine.start()` here
 * (avoids "Detected multiple instances of Alpine running").
 *
 * @see https://livewire.laravel.com/docs/installation
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('toastStore', () => ({
        toasts: [],
        init() {
            window.addEventListener('toast', (e) => {
                const id = Date.now();
                const message = e.detail?.message ?? 'Done';
                const type = e.detail?.type ?? 'success';
                this.toasts.push({ id, message, type });
                setTimeout(() => {
                    this.toasts = this.toasts.filter((t) => t.id !== id);
                }, 4000);
            });
        },
        remove(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
    }));
});

const plotlyCdnUrl = 'https://cdn.jsdelivr.net/npm/plotly.js-dist-min@3.4.0/plotly.min.js';

let plotlyLoader = null;

function loadPlotly() {
    if (window.Plotly) {
        return Promise.resolve(window.Plotly);
    }

    if (plotlyLoader) {
        return plotlyLoader;
    }

    plotlyLoader = new Promise((resolve, reject) => {
        const existing = document.querySelector('script[data-plotly-cdn="1"]');

        if (existing) {
            existing.addEventListener('load', () => resolve(window.Plotly), { once: true });
            existing.addEventListener('error', () => reject(new Error('Failed to load Plotly CDN script.')), { once: true });

            return;
        }

        const script = document.createElement('script');
        script.src = plotlyCdnUrl;
        script.async = true;
        script.dataset.plotlyCdn = '1';
        script.onload = () => resolve(window.Plotly);
        script.onerror = () => reject(new Error('Failed to load Plotly CDN script.'));
        document.head.appendChild(script);
    });

    return plotlyLoader;
}

async function renderDplyRegionMaps() {
    const mapElements = [...document.querySelectorAll('[data-region-map]')];

    if (mapElements.length === 0) {
        return;
    }

    const Plotly = await loadPlotly();

    mapElements.forEach((el) => {
        const rawPoints = el.dataset.regionPoints ?? '[]';
        const selected = el.dataset.selectedRegion ?? '';

        let points = [];
        try {
            points = JSON.parse(rawPoints);
        } catch {
            points = [];
        }

        if (!Array.isArray(points) || points.length === 0) {
            return;
        }

        const trace = {
            type: 'scattergeo',
            mode: 'markers+text',
            lon: points.map((point) => point.lon),
            lat: points.map((point) => point.lat),
            text: points.map((point) => point.value),
            textposition: 'top center',
            hovertext: points.map((point) => `${point.label} (${point.value})`),
            hoverinfo: 'text',
            marker: {
                size: points.map((point) => point.value === selected ? 18 : 12),
                color: points.map((point) => point.value === selected ? '#0284c7' : '#475569'),
                line: {
                    color: '#ffffff',
                    width: 2,
                },
            },
            textfont: {
                family: 'Inter, ui-sans-serif, system-ui, sans-serif',
                size: 11,
                color: '#0f172a',
            },
            customdata: points.map((point) => point.value),
        };

        const layout = {
            margin: { l: 0, r: 0, t: 0, b: 0 },
            paper_bgcolor: '#ffffff',
            plot_bgcolor: '#ffffff',
            geo: {
                scope: 'world',
                projection: { type: 'natural earth' },
                showland: true,
                landcolor: '#dbeafe',
                showocean: true,
                oceancolor: '#f8fafc',
                showcountries: true,
                countrycolor: '#cbd5e1',
                coastlinecolor: '#94a3b8',
                showcoastlines: true,
                bgcolor: '#ffffff',
            },
        };

        Plotly.react(el, [trace], layout, {
            responsive: true,
            displayModeBar: false,
        });

        if (el.dataset.regionMapBound !== '1') {
            el.on('plotly_click', (event) => {
                const value = event?.points?.[0]?.customdata;
                if (!value) {
                    return;
                }

                window.dispatchEvent(new CustomEvent('dply-region-selected', {
                    detail: { value },
                }));
            });
            el.dataset.regionMapBound = '1';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    renderDplyRegionMaps().catch(() => {});
});

window.addEventListener('dply:region-map-open', () => {
    window.setTimeout(() => {
        renderDplyRegionMaps().catch(() => {});
        window.dispatchEvent(new Event('resize'));
    }, 50);
});
