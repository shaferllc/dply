let docsProseLoader = null;

/**
 * Docs spec/compare card styles — slide-over panel + full /docs pages.
 */
export function dplyEnsureDocsProseStyles() {
    if (!docsProseLoader) {
        docsProseLoader = import('./lazy/docs-prose.js');
    }

    return docsProseLoader;
}

function dplyMaybeLoadDocsProseStyles() {
    if (
        document.querySelector('.docs-markdown-prose, .docs-sidebar-prose')
    ) {
        dplyEnsureDocsProseStyles().catch(() => {});
    }
}

export function registerDplyLazyAssetListeners() {
    document.addEventListener('DOMContentLoaded', () => {
        dplyMaybeLoadDocsProseStyles();
    });

    document.addEventListener('livewire:navigated', () => {
        dplyMaybeLoadDocsProseStyles();
    });

    window.addEventListener('dply-docs-open', () => {
        dplyEnsureDocsProseStyles().catch(() => {});
    });
}
