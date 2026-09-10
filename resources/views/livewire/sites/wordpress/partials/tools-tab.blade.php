{{--
    Tools: the maintenance jobs an operator otherwise drops to the console for.

    Every action routes through WpCli, so the permission gate, risk
    classification and the instant-vs-queued split are inherited — this partial
    only decides what is worth surfacing, and how loudly to warn about it.
--}}
<div>
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Tools') }}</h3>
            <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Maintenance mode, URL rewriting, security keys, caches and permalinks.') }}</p>
        </div>
    </div>

    @error('tools')
        <p class="border-b border-rose-200/70 bg-rose-50/60 px-3 py-2 text-xs text-rose-700 sm:px-4">{{ $message }}</p>
    @enderror

    {{-- Maintenance mode --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-3 py-3 sm:px-4">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-brand-ink">
                {{ __('Maintenance mode') }}
                @if ($maintenanceActive !== null)
                    <span @class([
                        'ml-1.5 inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1',
                        'bg-amber-50 text-amber-800 ring-amber-200' => $maintenanceActive,
                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => ! $maintenanceActive,
                    ])>{{ $maintenanceActive ? __('on') : __('off') }}</span>
                @endif
            </p>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Shows visitors a maintenance page while you work. On before an update, off after.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-arrow-path" target="loadMaintenanceStatus" wire:click="loadMaintenanceStatus">{{ __('Check') }}</x-spinner-button>
            @if ($maintenanceActive !== null)
                <x-spinner-button size="xs" :variant="$maintenanceActive ? 'primary' : 'secondary'" type="button" target="toggleMaintenanceMode" wire:click="toggleMaintenanceMode">
                    {{ $maintenanceActive ? __('Turn off') : __('Turn on') }}
                </x-spinner-button>
            @endif
        </div>
    </div>

    {{-- Search & replace --}}
    <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
        <p class="text-sm font-semibold text-brand-ink">{{ __('Search and replace URLs') }}</p>
        <p class="mt-0.5 max-w-2xl text-xs text-brand-moss">
            {{ __('Rewrites values across every table, including inside serialized data — this is how a site moves to a new domain. Preview first.') }}
        </p>

        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <div>
                <x-input-label for="sr_from" :value="__('Find')" />
                <x-text-input id="sr_from" class="mt-1 block w-full font-mono text-xs" type="text" wire:model="searchReplaceFrom" placeholder="http://old.example.com" />
            </div>
            <div>
                <x-input-label for="sr_to" :value="__('Replace with')" />
                <x-text-input id="sr_to" class="mt-1 block w-full font-mono text-xs" type="text" wire:model="searchReplaceTo" placeholder="https://new.example.com" />
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-magnifying-glass" target="previewSearchReplace" wire:click="previewSearchReplace">{{ __('Preview (dry run)') }}</x-spinner-button>
            @if ($searchReplacePreview !== null)
                <x-spinner-button size="xs" variant="danger" type="button" target="confirmSearchReplace" wire:click="confirmSearchReplace">{{ __('Apply for real') }}</x-spinner-button>
            @endif
        </div>

        @if ($searchReplacePreview !== null)
            <pre class="mt-3 max-h-56 overflow-auto rounded-md bg-brand-sand/50 p-3 font-mono text-2xs leading-relaxed text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $searchReplacePreview }}</pre>
        @endif
    </div>

    {{-- Permalinks --}}
    <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Permalinks') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('The URL structure for posts. Saving also flushes rewrite rules — the usual fix for 404s on every page but the homepage.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-arrow-path" target="loadPermalinks" wire:click="loadPermalinks">{{ __('Load') }}</x-spinner-button>
                <x-spinner-button size="xs" variant="secondary" type="button" target="flushRewrites" wire:click="flushRewrites">{{ __('Flush rules') }}</x-spinner-button>
            </div>
        </div>

        @if ($permalinkStructure !== null)
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <div class="min-w-0 flex-1">
                    <x-input-label for="permalink_input" :value="__('Structure')" />
                    <x-text-input id="permalink_input" class="mt-1 block w-full font-mono text-xs" type="text" wire:model="permalinkInput" />
                </div>
                <x-spinner-button size="xs" variant="primary" type="button" target="savePermalinks" wire:click="savePermalinks">{{ __('Save') }}</x-spinner-button>
            </div>
        @endif
    </div>

    {{-- Caches --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-3 py-3 sm:px-4">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Flush caches') }}</p>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Clears the object cache and every transient. Safe, and usually the first thing to try when stale content survives a deploy.') }}</p>
        </div>
        <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-trash" target="flushCaches" wire:click="flushCaches">{{ __('Flush') }}</x-spinner-button>
    </div>

    {{-- Salts --}}
    <div class="flex flex-wrap items-center justify-between gap-3 px-3 py-3 sm:px-4">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Rotate security keys') }}</p>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Regenerates the WordPress salts, signing everyone out — including you. The right move after a leaked password or a compromised plugin.') }}</p>
        </div>
        <x-spinner-button size="xs" variant="danger" type="button" icon="heroicon-o-key" target="confirmRotateSalts" wire:click="confirmRotateSalts">{{ __('Rotate') }}</x-spinner-button>
    </div>
</div>
