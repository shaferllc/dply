<section class="rounded-2xl border border-rose-200 bg-white p-6 shadow-sm dark:border-rose-900/40 dark:bg-zinc-900 sm:p-8 space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-rose-900 dark:text-rose-200">{{ __('Delete Edge site') }}</h2>
        <p class="mt-1 text-sm text-rose-800 dark:text-rose-300/90">{{ __('Permanently remove this site from dply Edge. All deployments, CDN entries, and custom domain routing will be torn down.') }}</p>
    </div>

    <ul class="list-disc space-y-1 pl-5 text-sm text-rose-900/90 dark:text-rose-300/80">
        <li>{{ __('Live traffic will stop once teardown completes.') }}</li>
        <li>{{ __('Preview deployments for this site will also be removed.') }}</li>
        <li>{{ __('This action cannot be undone.') }}</li>
    </ul>

    @can('delete', $site)
        <button type="button" wire:click="openEdgeTeardownModal" class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-2.5 text-sm font-medium text-rose-800 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-200 dark:hover:bg-rose-950/50">
            {{ __('Delete Edge site') }}
        </button>
    @endcan
</section>

<x-modal
    name="edge-teardown-confirmation"
    :show="false"
    maxWidth="lg"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel"
    focusable
>
    <div class="border-b border-brand-ink/10 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Danger zone') }}</p>
        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Delete this Edge site?') }}</h2>
        <p class="mt-2 text-sm leading-6 text-brand-moss">
            {{ __('All deployments and edge routing entries for :name will be removed permanently.', ['name' => $site->name]) }}
        </p>
    </div>
    <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'edge-teardown-confirmation')">
            {{ __('Cancel') }}
        </x-secondary-button>
        <x-danger-button type="button" wire:click="tearDownEdge" wire:loading.attr="disabled" wire:target="tearDownEdge">
            <span wire:loading.remove wire:target="tearDownEdge">{{ __('Delete Edge site') }}</span>
            <span wire:loading wire:target="tearDownEdge">{{ __('Queueing…') }}</span>
        </x-danger-button>
    </div>
</x-modal>
