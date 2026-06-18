{{--
    Fleet card Deploy / Sync controls — the per-server twin of the site deploy
    sidebar (App\Livewire\Sites\DeployControl). Expects $server and $deployTargets
    (from Servers\Index::buildDeployTargets) in scope. Renders nothing for servers
    with no deployable sites (e.g. database / cache boxes).

    The whole fleet is a single Livewire component, so wire:target carries the
    site id — without it, clicking one card's Deploy would spin every card's.
--}}
@php $deploy = $deployTargets[$server->id] ?? null; @endphp
@if ($deploy)
    @php
        $deploySites = $deploy['sites'];
        $deployAnchor = $deploy['anchor'];
        $deploySyncCount = $deploy['sync_count'];
    @endphp

    @if ($deploySites->count() === 1)
        {{-- Single deployable site: Deploy + (when it has peers) Sync N. --}}
        <button
            type="button"
            wire:click="deploySite('{{ $deployAnchor->id }}')"
            wire:loading.attr="disabled"
            wire:target="deploySite('{{ $deployAnchor->id }}')"
            title="{{ __('Deploy :name', ['name' => $deployAnchor->name]) }}"
            class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
        >
            <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" wire:loading.remove wire:target="deploySite('{{ $deployAnchor->id }}')" aria-hidden="true" />
            <span wire:loading wire:target="deploySite('{{ $deployAnchor->id }}')" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>
            {{ __('Deploy') }}
        </button>

        @if ($deploySyncCount > 1)
            <button
                type="button"
                wire:click="deploySyncedSites('{{ $deployAnchor->id }}')"
                wire:loading.attr="disabled"
                wire:target="deploySyncedSites('{{ $deployAnchor->id }}')"
                title="{{ __('Deploy :name together with its synced sites.', ['name' => $deployAnchor->name]) }}"
                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
            >
                <x-heroicon-o-arrows-right-left class="h-4 w-4 shrink-0" wire:loading.remove wire:target="deploySyncedSites('{{ $deployAnchor->id }}')" aria-hidden="true" />
                <span wire:loading wire:target="deploySyncedSites('{{ $deployAnchor->id }}')" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>
                {{ __('Sync') }}
                <span class="rounded bg-brand-sand/60 px-1.5 text-[10px] font-bold tabular-nums text-brand-moss">{{ $deploySyncCount }}</span>
            </button>
        @endif
    @else
        {{-- Multiple deployable sites: Deploy-all + per-site picks. --}}
        <x-dropdown align="right" width="w-64">
            <x-slot name="trigger">
                <button type="button" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Deploy') }}
                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </button>
            </x-slot>
            <x-slot name="content">
                <button type="button" wire:click="deployServerSites('{{ $server->id }}')" class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                    <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Deploy all :count sites', ['count' => $deploySites->count()]) }}
                </button>
                <div class="my-1 border-t border-brand-ink/10"></div>
                @foreach ($deploySites as $deploySite)
                    <button type="button" wire:click="deploySite('{{ $deploySite->id }}')" class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                        <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                        <span class="min-w-0 truncate">{{ $deploySite->name }}</span>
                    </button>
                @endforeach
            </x-slot>
        </x-dropdown>
    @endif
@endif
