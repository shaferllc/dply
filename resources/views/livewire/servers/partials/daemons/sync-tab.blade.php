<div
    class="flex flex-wrap gap-1 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-1 shadow-sm"
    role="tablist"
    aria-label="{{ __('Sync sections') }}"
>
    <button
        type="button"
        role="tab"
        id="daemons-sync-sub-preview"
        wire:click="$set('daemons_sync_subtab', 'preview')"
        aria-selected="{{ $daemons_sync_subtab === 'preview' ? 'true' : 'false' }}"
        class="shrink-0 rounded-xl px-3 py-2 text-xs font-medium transition-colors sm:text-sm {{ $daemons_sync_subtab === 'preview' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
    >
        {{ __('Preview') }}
    </button>
    <button
        type="button"
        role="tab"
        id="daemons-sync-sub-drift"
        wire:click="$set('daemons_sync_subtab', 'drift')"
        aria-selected="{{ $daemons_sync_subtab === 'drift' ? 'true' : 'false' }}"
        class="shrink-0 rounded-xl px-3 py-2 text-xs font-medium transition-colors sm:text-sm {{ $daemons_sync_subtab === 'drift' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
    >
        {{ __('Drift') }}
    </button>
    <button
        type="button"
        role="tab"
        id="daemons-sync-sub-output"
        wire:click="$set('daemons_sync_subtab', 'output')"
        aria-selected="{{ $daemons_sync_subtab === 'output' ? 'true' : 'false' }}"
        class="shrink-0 rounded-xl px-3 py-2 text-xs font-medium transition-colors sm:text-sm {{ $daemons_sync_subtab === 'output' ? 'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' : 'text-brand-moss hover:bg-white/60 hover:text-brand-ink' }}"
    >
        {{ __('Last output') }}
    </button>
</div>

@if ($daemons_sync_subtab === 'preview')
    <div
        role="tabpanel"
        id="daemons-sync-panel-preview"
        aria-labelledby="daemons-sync-sub-preview"
    >
        <div class="{{ $card }}">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                <x-icon-badge>
                    <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Preview') }}</p>
                    <h2 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Sync preview') }}</h2>
                    <p class="mt-1 max-w-2xl text-xs text-brand-moss leading-relaxed">
                        {{ __('Compare generated configs to files on the server before writing. Read-only over SSH.') }}
                    </p>
                </div>
            </div>
            <div class="space-y-4 p-6 sm:p-8">
                <button
                    type="button"
                    wire:click="loadPreviewSync"
                    wire:loading.attr="disabled"
                    wire:target="loadPreviewSync"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="loadPreviewSync">{{ __('Load preview') }}</span>
                    <span wire:loading wire:target="loadPreviewSync" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                        {{ __('Loading…') }}
                    </span>
                </button>
                <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $preview_sync_output !== '' ? $preview_sync_output : __('Click “Load preview”.') }}</pre>
            </div>
        </div>
    </div>
@endif

@if ($daemons_sync_subtab === 'drift')
    <div
        role="tabpanel"
        id="daemons-sync-panel-drift"
        aria-labelledby="daemons-sync-sub-drift"
    >
        <div class="{{ $card }}">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                <x-icon-badge>
                    <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Drift') }}</p>
                    <h2 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Config drift') }}</h2>
                    <p class="mt-1 max-w-2xl text-xs text-brand-moss leading-relaxed">
                        {{ __('Compare Dply program IDs to dply-sv-*.conf files on the server.') }}
                    </p>
                </div>
            </div>
            <div class="space-y-4 p-6 sm:p-8">
                <button
                    type="button"
                    wire:click="loadDrift"
                    wire:loading.attr="disabled"
                    wire:target="loadDrift"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="loadDrift">{{ __('Check drift') }}</span>
                    <span wire:loading wire:target="loadDrift" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                        {{ __('Loading…') }}
                    </span>
                </button>
                <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $drift_output !== '' ? $drift_output : __('Click “Check drift”.') }}</pre>
            </div>
        </div>
    </div>
@endif

@if ($daemons_sync_subtab === 'output')
    <div
        role="tabpanel"
        id="daemons-sync-panel-output"
        aria-labelledby="daemons-sync-sub-output"
    >
        <div class="{{ $card }}">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                <x-icon-badge>
                    <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Output') }}</p>
                    <h2 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Last sync log') }}</h2>
                    <p class="mt-1 max-w-2xl text-xs text-brand-moss">
                        {{ __('Output from the most recent “Sync Supervisor on server” run. Run sync from the Programs tab to refresh.') }}
                    </p>
                </div>
            </div>
            <div class="p-6 sm:p-8">
                <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $last_supervisor_sync_output !== '' ? $last_supervisor_sync_output : __('No sync yet. Use Programs → “Sync Supervisor on server”.') }}</pre>
            </div>
        </div>
    </div>
@endif
