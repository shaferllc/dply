/**
 * Global SSH console drawer chrome (open state, keyboard, events).
 * Kept out of Blade x-data so HTML attribute quoting cannot truncate the script.
 */
export function registerConsoleDrawer(Alpine) {
    Alpine.data('dplyConsoleDrawer', () => ({
        // Prefer drawerOpen over `open` — bare `open` can resolve to window.open
        // if Alpine scope is briefly lost during Livewire morph.
        drawerOpen: false,

        init() {
            this.drawerOpen = localStorage.getItem('dply.consoleDrawer.open') === '1';
            if (this.drawerOpen) {
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('dply-console-drawer-opened')));
            }

            document.addEventListener('keydown', (e) => {
                const tag = (e.target.tagName || '').toLowerCase();
                const inInput = ['input', 'textarea', 'select'].includes(tag) || e.target.isContentEditable;
                if (e.key === '`' && ! inInput && ! e.metaKey && ! e.ctrlKey && ! e.altKey) {
                    e.preventDefault();
                    this.toggle();
                } else if (e.key === 'Escape' && this.drawerOpen) {
                    this.close();
                }
            });

            window.addEventListener('dply-open-console-drawer', () => {
                if (! this.drawerOpen) {
                    this.drawerOpen = true;
                    localStorage.setItem('dply.consoleDrawer.open', '1');
                    this.$nextTick(() => window.dispatchEvent(new CustomEvent('dply-console-drawer-opened')));
                }
            });

            window.addEventListener('dply-toggle-console-drawer', () => this.toggle());
        },

        toggle() {
            this.drawerOpen = ! this.drawerOpen;
            localStorage.setItem('dply.consoleDrawer.open', this.drawerOpen ? '1' : '0');
            if (this.drawerOpen) {
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('dply-console-drawer-opened')));
            } else {
                window.dispatchEvent(new CustomEvent('dply-console-drawer-closed'));
            }
        },

        close() {
            if (! this.drawerOpen) {
                return;
            }
            this.drawerOpen = false;
            localStorage.setItem('dply.consoleDrawer.open', '0');
            window.dispatchEvent(new CustomEvent('dply-console-drawer-closed'));
        },
    }));
}
