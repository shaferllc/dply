{{--
    Fleet list row. Expects from the including scope: $server and the shared
    closures ($stripe, $isFullyReady, $isSetupFailed, $displayStatus,
    $statusTone, $statusLabel, $insightBadgeClass) plus $insightRollup,
    $latestSnapshots, $provisioningDigests.
--}}
<li wire:key="server-list-{{ $server->id }}" class="flex items-stretch border-b border-brand-ink/10 last:border-b-0 hover:bg-brand-sand/15 transition-colors">
    <div class="w-1 shrink-0 {{ $stripe($server) }}" aria-hidden="true"></div>
    <div class="flex flex-1 flex-col gap-3 px-4 py-4 sm:px-6 min-w-0 lg:flex-row lg:items-center lg:gap-5">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <a href="{{ route('servers.show', $server) }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">
                    {{ $server->name }}
                </a>
                <span class="font-mono text-xs text-brand-moss">{{ $server->ip_address ?? __('Provisioning…') }}</span>
                @feature('workspace.insights')
                    @php $insOpenList = (int) ($insightRollup[$server->id]['open'] ?? 0); @endphp
                    @if ($insOpenList > 0)
                        <a href="{{ route('servers.insights', $server) }}" wire:navigate title="{{ __('Open insights') }}" class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none {{ $insightBadgeClass($server->id) }}">
                            {{ trans_choice(':count insight|:count insights', $insOpenList, ['count' => $insOpenList]) }}
                        </a>
                    @endif
                @endfeature
            </div>

            <div class="mt-2">
                @include('livewire.servers.partials.server-status-chips', ['server' => $server])
            </div>

            @if ($server->workspace)
                @feature('surface.projects')
                    <p class="mt-1.5 text-xs text-brand-moss">
                        {{ __('Project:') }}
                        <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                            {{ $server->workspace->name }}
                        </a>
                    </p>
                @endfeature
            @endif

            @php $serverTagsList = \App\Support\Servers\ServerTags::forServer($server); @endphp
            @if (count($serverTagsList) > 0)
                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach ($serverTagsList as $tag)
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

            <div class="mt-2">
                @include('livewire.servers.partials.server-sites-disclosure', ['server' => $server])
            </div>

            {{-- Setup-failed detail: red chip + journey link. Shown instead of
                 the live progress block when applyProvisionOutcomeToServer
                 flipped setup_status to failed. Without this branch the card
                 keeps ticking the elapsed counter on a dead provision. --}}
            @if ($isSetupFailed($server))
                <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-red-700 ring-1 ring-red-200">
                        <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                        {{ __('Setup failed') }}
                    </span>
                    <span class="text-brand-ink">{{ __('Provisioning did not finish — open the journey to see the failing step.') }}</span>
                    <a href="{{ route('servers.journey', $server) }}" wire:navigate class="ml-auto inline-flex items-center gap-1 text-[11px] font-semibold text-red-700 hover:text-red-900">
                        {{ __('Open journey') }}
                        <x-heroicon-m-arrow-right class="h-3 w-3" />
                    </a>
                </div>
            @endif

            {{-- Live provisioning detail: phase + current step + elapsed + a
                 thin progress bar. Mirrors the journey page's headline so an
                 operator scanning the fleet sees "where is this in the build"
                 without clicking through. Only renders for in-flight VMs and
                 is suppressed once setup_status hits failed (see above). --}}
            @php $digest = $provisioningDigests[$server->id] ?? null; @endphp
            @if ($digest && ! $isSetupFailed($server))
                <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-sky-800 ring-1 ring-sky-200">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-sky-500"></span>
                        {{ $digest->phaseLabel }}
                    </span>
                    <span class="font-medium text-brand-ink">{{ $digest->stepLabel }}</span>
                    @if ($digest->stepIndex && $digest->stepTotal)
                        <span class="text-brand-mist">·</span>
                        <span class="tabular-nums">{{ __('Step :i of :t', ['i' => $digest->stepIndex, 't' => $digest->stepTotal]) }}</span>
                    @endif
                    @if ($digest->elapsedHuman())
                        <span class="text-brand-mist">·</span>
                        <span class="tabular-nums">{{ __(':elapsed elapsed', ['elapsed' => $digest->elapsedHuman()]) }}</span>
                    @endif
                    <a href="{{ route('servers.journey', $server) }}" wire:navigate class="ml-auto inline-flex items-center gap-1 text-[11px] font-semibold text-sky-700 hover:text-sky-900">
                        {{ __('Open journey') }}
                        <x-heroicon-m-arrow-right class="h-3 w-3" />
                    </a>
                </div>
                @if ($digest->stepIndex && $digest->stepTotal)
                    @php $pct = max(0, min(100, (int) round(100 * $digest->stepIndex / $digest->stepTotal))); @endphp
                    <div class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-brand-ink/5">
                        <div class="h-full rounded-full bg-sky-500 transition-[width] duration-500" style="width: {{ $pct }}%"></div>
                    </div>
                @endif
            @endif
        </div>

        <div class="hidden shrink-0 lg:block">
            <x-server-metric-pulse :snapshot="$latestSnapshots[$server->id] ?? null" />
        </div>
        <div class="lg:hidden">
            <x-server-metric-pulse :snapshot="$latestSnapshots[$server->id] ?? null" />
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <a href="{{ route('servers.show', $server) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-3.5 py-2 text-xs font-semibold text-brand-cream transition hover:bg-brand-forest">
                <x-heroicon-m-cog-6-tooth class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Manage') }}
            </a>
            @if (auth()->user()->can('delete', $server) || $server->scheduled_deletion_at)
                <x-dropdown align="right" width="w-56">
                    <x-slot name="trigger">
                        <button type="button" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white p-2 text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink" title="{{ __('More actions') }}">
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
