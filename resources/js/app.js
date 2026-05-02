import './bootstrap';

import './dply-webauthn.js';

import { registerDplyThemeListeners } from './theme.js';

registerDplyThemeListeners();

/**
 * Livewire 3 ships Alpine; do not import `alpinejs` or call `Alpine.start()` here
 * (avoids "Detected multiple instances of Alpine running").
 *
 * @see https://livewire.laravel.com/docs/installation
 */
const toastRegionClasses = {
    top_center:
        'fixed left-1/2 top-24 z-50 flex w-full max-w-xl -translate-x-1/2 flex-col items-center gap-2 px-4',
    top_right: 'fixed top-24 right-4 z-50 flex max-w-xl flex-col items-end gap-2 px-4',
    bottom_right: 'fixed bottom-4 right-4 z-50 flex max-w-md flex-col gap-2',
    bottom_left: 'fixed bottom-4 left-4 z-50 flex max-w-md flex-col gap-2',
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('toastStore', (config = {}) => {
        const positionKey = config.position ?? 'bottom_right';
        const savedClass =
            toastRegionClasses[positionKey] ?? toastRegionClasses.bottom_right;

        return {
            regionClass: savedClass,
            savedRegionClass: savedClass,
            toasts: [],
            init() {
                window.addEventListener('toast', (e) => {
                    const pos = e.detail?.position;
                    if (pos && toastRegionClasses[pos]) {
                        this.regionClass = toastRegionClasses[pos];
                    } else {
                        this.regionClass = this.savedRegionClass;
                    }

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
        };
    });
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

function isDplyDocumentDark() {
    return document.documentElement.classList.contains('dark');
}

function buildDplyRegionMapTrace(points, selected, isDark) {
    const markerSelected = isDark ? '#38bdf8' : '#0284c7';
    const markerUnselected = isDark ? '#71717a' : '#475569';
    const textColor = isDark ? '#e4e4e7' : '#0f172a';
    const markerLine = isDark ? '#fafafa' : '#ffffff';

    return {
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
            color: points.map((point) => (point.value === selected ? markerSelected : markerUnselected)),
            line: {
                color: markerLine,
                width: 2,
            },
        },
        textfont: {
            family: 'Inter, ui-sans-serif, system-ui, sans-serif',
            size: 11,
            color: textColor,
        },
        customdata: points.map((point) => point.value),
    };
}

function buildDplyRegionMapLayout(isDark) {
    const paper = isDark ? '#18181b' : '#ffffff';
    const land = isDark ? '#1e3a4a' : '#dbeafe';
    const ocean = isDark ? '#0c1220' : '#f8fafc';
    const country = isDark ? '#3f3f46' : '#cbd5e1';
    const coast = isDark ? '#52525b' : '#94a3b8';

    return {
        margin: { l: 0, r: 0, t: 0, b: 0 },
        paper_bgcolor: paper,
        plot_bgcolor: paper,
        geo: {
            scope: 'world',
            projection: { type: 'natural earth' },
            showland: true,
            landcolor: land,
            showocean: true,
            oceancolor: ocean,
            showcountries: true,
            countrycolor: country,
            coastlinecolor: coast,
            showcoastlines: true,
            bgcolor: paper,
        },
    };
}

async function renderDplyRegionMaps() {
    const mapElements = [...document.querySelectorAll('[data-region-map]')];

    if (mapElements.length === 0) {
        return;
    }

    const Plotly = await loadPlotly();
    const isDark = isDplyDocumentDark();

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

        const trace = buildDplyRegionMapTrace(points, selected, isDark);
        const layout = buildDplyRegionMapLayout(isDark);

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

// wire:navigate swaps the DOM without firing DOMContentLoaded — re-render any maps
// that landed in the new page so the create wizard's region map works after SPA nav.
document.addEventListener('livewire:navigated', () => {
    renderDplyRegionMaps().catch(() => {});
});

window.addEventListener('dply-theme-applied', () => {
    renderDplyRegionMaps().catch(() => {});
});

window.addEventListener('dply:region-map-open', () => {
    window.setTimeout(() => {
        renderDplyRegionMaps().catch(() => {});
        window.dispatchEvent(new Event('resize'));
    }, 50);
});
