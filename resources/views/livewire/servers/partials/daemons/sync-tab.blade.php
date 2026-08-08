<div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
    <x-server-workspace-tablist id="daemons-sync-tablist" :aria-label="__('Sync sections')" scroll bare class="!mb-0 w-full">
        {{-- setDaemonsSyncSubtab(), not $set(): wire:target can't match a magic
             $set, so the tab's inline spinner never fired and the skeleton below
             had nothing to key off. --}}
        <x-server-workspace-tab
            id="daemons-sync-sub-preview"
            icon="heroicon-o-eye"
            :active="$daemons_sync_subtab === 'preview'"
            wire:click="setDaemonsSyncSubtab('preview')"
        >{{ __('Preview') }}</x-server-workspace-tab>

        <x-server-workspace-tab
            id="daemons-sync-sub-drift"
            icon="heroicon-o-arrows-right-left"
            :active="$daemons_sync_subtab === 'drift'"
            wire:click="setDaemonsSyncSubtab('drift')"
        >{{ __('Drift') }}</x-server-workspace-tab>

        <x-server-workspace-tab
            id="daemons-sync-sub-output"
            icon="heroicon-o-document-text"
            :active="$daemons_sync_subtab === 'output'"
            wire:click="setDaemonsSyncSubtab('output')"
        >{{ __('Last output') }}</x-server-workspace-tab>
    </x-server-workspace-tablist>
</div>

{{-- Sub-tab switch skeletons, one per sub-tab, each targeting the call WITH its
     argument — Livewire matches wire:target params, so only the sub-tab actually
     being opened paints. wire:loading.block, not bare wire:loading, or the
     skeleton shrink-wraps to inline-block. --}}
@foreach (['preview', 'drift', 'output'] as $skeletonSub)
    <div wire:loading.block wire:target="setDaemonsSyncSubtab('{{ $skeletonSub }}')" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading section…') }}</span>
        <div class="{{ $card }}">
            @include('livewire.servers.partials.daemons._sync-subtab-skeleton', ['sub' => $skeletonSub])
        </div>
    </div>
@endforeach

@if ($daemons_sync_subtab === 'preview')
    <div
        role="tabpanel"
        id="daemons-sync-panel-preview"
        aria-labelledby="daemons-sync-sub-preview"
        wire:loading.remove
        wire:target="setDaemonsSyncSubtab"
    >
        <div class="{{ $card }}">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-eye"
                :title="__('Sync preview')"
                :note="__('Compare generated configs to files on the server before writing. Read-only over SSH.')"
                class="border-b border-brand-ink/10"
            />
            <div class="space-y-3 px-4 py-3.5 sm:px-5">
                <button
                    type="button"
                    wire:click="loadPreviewSync"
                    wire:loading.attr="disabled"
                    wire:target="loadPreviewSync"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex h-7 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-m-eye class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="loadPreviewSync" aria-hidden="true" />
                    <x-spinner wire:loading wire:target="loadPreviewSync" variant="forest" size="sm" />
                    <span wire:loading.remove wire:target="loadPreviewSync">{{ __('Load preview') }}</span>
                    <span wire:loading wire:target="loadPreviewSync">{{ __('Loading…') }}</span>
                </button>
                <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $preview_sync_output !== '' ? $preview_sync_output : __('Click "Load preview".') }}</pre>
            </div>
        </div>
    </div>
@endif

@if ($daemons_sync_subtab === 'drift')
    <div
        role="tabpanel"
        id="daemons-sync-panel-drift"
        aria-labelledby="daemons-sync-sub-drift"
        wire:loading.remove
        wire:target="setDaemonsSyncSubtab"
    >
        <div class="{{ $card }}">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-arrows-right-left"
                :title="__('Config drift')"
                :note="__('Compare Dply program IDs to dply-sv-*.conf files on the server.')"
                class="border-b border-brand-ink/10"
            />
            <div class="space-y-3 px-4 py-3.5 sm:px-5">
                <button
                    type="button"
                    wire:click="loadDrift"
                    wire:loading.attr="disabled"
                    wire:target="loadDrift"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex h-7 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-m-arrows-right-left class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="loadDrift" aria-hidden="true" />
                    <x-spinner wire:loading wire:target="loadDrift" variant="forest" size="sm" />
                    <span wire:loading.remove wire:target="loadDrift">{{ __('Check drift') }}</span>
                    <span wire:loading wire:target="loadDrift">{{ __('Loading…') }}</span>
                </button>
                <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $drift_output !== '' ? $drift_output : __('Click "Check drift".') }}</pre>
            </div>
        </div>
    </div>
@endif

@if ($daemons_sync_subtab === 'output')
    <div
        role="tabpanel"
        id="daemons-sync-panel-output"
        aria-labelledby="daemons-sync-sub-output"
        wire:loading.remove
        wire:target="setDaemonsSyncSubtab"
    >
        <div class="{{ $card }}">
            {{-- Typographic quotes in the note, not straight ones: it rides an
                 HTML attribute here, where a `"` would close it early. --}}
            <x-workspace-panel-head
                dense
                icon="heroicon-o-document-text"
                :title="__('Last sync log')"
                :note="__('Output from the most recent “Sync Supervisor on server” run. Run sync from the Programs tab to refresh.')"
                class="border-b border-brand-ink/10"
            />
            <div class="px-4 py-3.5 sm:px-5">
                <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $last_supervisor_sync_output !== '' ? $last_supervisor_sync_output : __('No sync yet. Use Programs → "Sync Supervisor on server".') }}</pre>
            </div>
        </div>
    </div>
@endif
