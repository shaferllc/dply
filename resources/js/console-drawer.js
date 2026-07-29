/**
 * Global SSH console drawer chrome (open state, keyboard, events).
 * Kept out of Blade x-data so HTML attribute quoting cannot truncate the script.
 */
export function registerConsoleDrawer(Alpine) {
    Alpine.data('dplyConsoleDrawer', () => ({
        open: false,

        init() {
            this.open = localStorage.getItem('dply.consoleDrawer.open') === '1';
            if (this.open) {
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('dply-console-drawer-opened')));
            }

            document.addEventListener('keydown', (e) => {
                const tag = (e.target.tagName || '').toLowerCase();
                const inInput = ['input', 'textarea', 'select'].includes(tag) || e.target.isContentEditable;
                if (e.key === '`' && ! inInput && ! e.metaKey && ! e.ctrlKey && ! e.altKey) {
                    e.preventDefault();
                    this.toggle();
                } else if (e.key === 'Escape' && this.open) {
                    this.close();
                }
            });

            window.addEventListener('dply-open-console-drawer', () => {
                if (! this.open) {
                    this.open = true;
                    localStorage.setItem('dply.consoleDrawer.open', '1');
                    this.$nextTick(() => window.dispatchEvent(new CustomEvent('dply-console-drawer-opened')));
                }
            });

            window.addEventListener('dply-toggle-console-drawer', () => this.toggle());
        },

        toggle() {
            this.open = ! this.open;
            localStorage.setItem('dply.consoleDrawer.open', this.open ? '1' : '0');
            if (this.open) {
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('dply-console-drawer-opened')));
            } else {
                window.dispatchEvent(new CustomEvent('dply-console-drawer-closed'));
            }
        },

        close() {
            if (! this.open) {
                return;
            }
            this.open = false;
            localStorage.setItem('dply.consoleDrawer.open', '0');
            window.dispatchEvent(new CustomEvent('dply-console-drawer-closed'));
        },
    }));
}
