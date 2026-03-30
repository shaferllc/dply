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
