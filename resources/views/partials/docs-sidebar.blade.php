{{-- Global documentation sidebar — opens from page headers and x-docs-link.
     Shared by the app and settings layouts so the in-app docs panel is
     available everywhere the Documentation affordance is shown. --}}
<div
    x-data="{
        open: false,
        init() {
            window.addEventListener('dply-docs-open', (event) => {
                this.open = true;
                const detail = event.detail ?? {};
                this.$nextTick(() => {
                    Livewire.dispatch('docs-sidebar-open', {
                        slug: detail.slug ?? null,
                        docRoute: detail.docRoute ?? null,
                        docSlug: detail.docSlug ?? null,
                    });
                });
            });
            window.addEventListener('dply-docs-close', () => this.close());
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && this.open) {
                    this.close();
                }
            });
        },
        close() {
            this.open = false;
            Livewire.dispatch('docs-sidebar-close');
        },
    }"
    x-on:dply-docs-close.window="close()"
>
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 bg-brand-ink/40"
        x-on:click="close()"
        aria-hidden="true"
    ></div>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="fixed inset-y-0 right-0 z-50 w-full border-l border-brand-ink/10 shadow-2xl sm:w-[420px] dark:border-brand-mist/20"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Documentation') }}"
    >
        <livewire:docs.sidebar :key="'docs-sidebar-'.request()->path()" />
    </div>
</div>
