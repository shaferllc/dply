<div class="{{ $card ?? 'border-b border-brand-ink/10' }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-arrow-path"
        :title="__('Server sync')"
        :note="__('Re-probe which engines are installed, then query MySQL and PostgreSQL for database names to compare with Dply.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="refreshDatabaseCapabilities"
                wire:loading.attr="disabled"
                wire:target="refreshDatabaseCapabilities"
                @disabled($isDeployer ?? false)
                title="{{ __('Re-run engine detection (cached for a few minutes)') }}"
                class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="refreshDatabaseCapabilities" class="inline-flex items-center gap-1">
                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" />
                    {{ __('Recheck engines') }}
                </span>
                <span wire:loading wire:target="refreshDatabaseCapabilities" class="inline-flex items-center gap-1">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Rechecking…') }}
                </span>
            </button>
            <button
                type="button"
                wire:click="synchronizeDatabases"
                wire:loading.attr="disabled"
                wire:target="synchronizeDatabases"
                @disabled($isDeployer ?? false)
                class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="synchronizeDatabases" class="inline-flex items-center gap-1">
                    <x-heroicon-m-magnifying-glass class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" />
                    {{ __('Synchronize databases') }}
                </span>
                <span wire:loading wire:target="synchronizeDatabases" class="inline-flex items-center gap-1">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Scanning…') }}
                </span>
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>
</div>
