{{--
    Fleet grid card. Expects from the including scope: $server and the shared
    closures ($stripe, $isFullyReady, $isSetupFailed, $displayStatus,
    $statusTone, $statusLabel, $insightBadgeClass) plus $insightRollup,
    $latestSnapshots.
--}}
<li wire:key="server-grid-{{ $server->id }}" class="flex overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm transition-colors hover:border-brand-ink/20">
    <div class="w-1 shrink-0 {{ $stripe($server) }}" aria-hidden="true"></div>
    <div class="flex min-w-0 flex-1 flex-col gap-3 p-4">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <a href="{{ route('servers.show', $server) }}" wire:navigate class="block truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">{{ $server->name }}</a>
                <p class="mt-0.5 truncate font-mono text-xs text-brand-moss">{{ $server->ip_address ?? __('Provisioning…') }}</p>
            </div>
            @feature('workspace.insights')
                @php $insOpen = (int) ($insightRollup[$server->id]['open'] ?? 0); @endphp
                @if ($insOpen > 0)
                    <a href="{{ route('servers.insights', $server) }}" wire:navigate title="{{ __('Open insights') }}" class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none {{ $insightBadgeClass($server->id) }}">{{ trans_choice(':count insight|:count insights', $insOpen, ['count' => $insOpen]) }}</a>
                @endif
            @endfeature
        </div>

        @include('livewire.servers.partials.server-status-chips', ['server' => $server])

        <div>
            <x-server-metric-pulse :snapshot="$latestSnapshots[$server->id] ?? null" />
        </div>

        @php $serverTags = \App\Support\Servers\ServerTags::forServer($server); @endphp
        @if (count($serverTags) > 0)
            <div class="flex flex-wrap gap-1">
                @foreach ($serverTags as $tag)
                    <button
                        type="button"
                        wire:click="$set('tagFilter', @js($tag))"
                        class="inline-flex items-center rounded-full bg-brand-sand/50 px-2 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10 transition hover:bg-brand-sage/15 hover:text-brand-ink"
                        title="{{ __('Filter fleet by :tag', ['tag' => $tag]) }}"
                    >
                        {{ $tag }}
                    </button>
                @endforeach
            </div>
        @endif

        @if ($server->workspace)
            @feature('surface.projects')
                <p class="text-xs text-brand-moss">
                    {{ __('Project:') }}
                    <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                        {{ $server->workspace->name }}
                    </a>
                </p>
            @endfeature
        @endif

        @include('livewire.servers.partials.server-resource-tabs', ['server' => $server])

        <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-1">
            @include('livewire.servers.partials.server-deploy-action', ['server' => $server, 'deployTargets' => $deployTargets])
            <a href="{{ route('servers.show', $server) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream transition hover:bg-brand-forest">
                <x-heroicon-m-cog-6-tooth class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Manage') }}
            </a>
            @if (auth()->user()->can('delete', $server) || $server->scheduled_deletion_at)
                <x-dropdown align="right" width="w-56">
                    <x-slot name="trigger">
                        <button type="button" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white p-1.5 text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink" title="{{ __('More actions') }}">
                            <span class="sr-only">{{ __('More actions') }}</span>
                            <x-heroicon-o-ellipsis-vertical class="h-4 w-4" aria-hidden="true" />
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <a href="{{ route('servers.show', $server) }}" wire:navigate class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Open workspace') }}
                        </a>
                        @if ($server->scheduled_deletion_at)
                            <button type="button" wire:click="cancelScheduledServerRemoval(@js($server->id))" class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                                <x-heroicon-o-arrow-uturn-left class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                {{ __('Cancel scheduled removal') }}
                            </button>
                        @endif
                        @can('delete', $server)
                            <button type="button" wire:click="openRemoveServerModal(@js($server->id))" class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-red-600 transition hover:bg-red-50">
                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Remove server') }}
                            </button>
                        @endcan
                    </x-slot>
                </x-dropdown>
            @endif
        </div>
    </div>
</li>
