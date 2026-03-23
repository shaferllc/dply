import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('toastStore', () => ({
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

Alpine.start();
