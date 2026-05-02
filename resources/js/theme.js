/**
 * Sync `<html class="dark">` with profile theme preference (light | dark | system).
 * Inline script in theme-head runs first to reduce flash; this wires Livewire + OS scheme changes.
 */

// Dark mode temporarily disabled: always apply light, ignore the requested theme.
export function applyDplyTheme(theme) {
    const meta = document.querySelector('meta[name="dply-theme"]');
    if (meta) {
        meta.setAttribute('content', 'light');
    }

    document.documentElement.classList.remove('dark');

    window.dispatchEvent(
        new CustomEvent('dply-theme-applied', {
            detail: { theme: 'light', isDark: false },
        }),
    );
}

function extractThemeFromLivewirePayload(payload) {
    if (payload == null) {
        return null;
    }

    if (typeof payload === 'string') {
        return payload;
    }

    if (typeof payload.theme === 'string') {
        return payload.theme;
    }

    if (payload.detail && typeof payload.detail.theme === 'string') {
        return payload.detail.theme;
    }

    if (Array.isArray(payload) && payload[0] && typeof payload[0].theme === 'string') {
        return payload[0].theme;
    }

    return null;
}

export function registerDplyThemeListeners() {
    document.addEventListener('livewire:init', () => {
        const lw = window.Livewire;
        if (!lw?.on) {
            return;
        }
        lw.on('dply-theme-changed', (payload) => {
            const theme = extractThemeFromLivewirePayload(payload);
            if (theme) {
                applyDplyTheme(theme);
            }
        });
    });

    if (typeof window !== 'undefined') {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            const meta = document.querySelector('meta[name="dply-theme"]');
            const t = meta?.getAttribute('content') || 'system';
            if (t === 'system') {
                applyDplyTheme('system');
            }
        });
    }
}
