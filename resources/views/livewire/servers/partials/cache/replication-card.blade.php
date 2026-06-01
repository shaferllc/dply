@php
    /** @var \App\Models\ServerCacheService $row */
    /** @var string $card */
    /** @var array<string, string> $engineLabels */
    /** @var \Illuminate\Support\Collection $activeReplications */
    /** @var \Illuminate\Support\Collection $availableReplicaServers */
    $engineLabel = $engineLabels[$row->engine] ?? ucfirst($row->engine);
    $state = $replicationState ?? null;
    $error = $replicationError ?? null;
    $role = $state['role'] ?? null;
    $isReplica = in_array($role, ['slave', 'replica'], true);
    $activeReplications = $activeReplications ?? collect();
    $availableReplicaServers = $availableReplicaServers ?? collect();
@endphp

<div
    class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8"
    wire:init="loadReplicationState"
    wire:poll.15s="loadReplicationState"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — replication', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Live INFO replication parse. Read-only view of the engine\'s current master/replica state and any attached replicas.') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            @if ($role)
                <span class="inline-flex items-center gap-1.5 rounded-full {{ $isReplica ? 'bg-violet-50 text-violet-800 ring-violet-200' : 'bg-emerald-50 text-emerald-800 ring-emerald-200' }} px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide ring-1">
                    <span class="h-1.5 w-1.5 rounded-full {{ $isReplica ? 'bg-violet-500' : 'bg-emerald-500' }}"></span>
                    {{ $isReplica ? __('Replica') : __('Master') }}
                </span>
            @endif
            @if (! $isReplica && $role === 'master')
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'add-replica-modal')"
                    class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    {{ __('Add replica') }}
                </button>
            @endif
        </div>
    </div>

    @if (! empty($replicationFromCache) && is_array($state))
        <p class="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">
            <x-heroicon-o-clock class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>{{ __('Showing cached snapshot') }}@if (! empty($replicationCachedAt)) {{ __('from :time', ['time' => \Illuminate\Support\Carbon::parse($replicationCachedAt)->diffForHumans()]) }}@endif. {{ __('Refreshing in the background — values update on the next poll tick.') }}</span>
        </p>
    @endif

    @if ($error)
        <p class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">{{ $error }}</p>
    @elseif ($state === null)
        {{-- First-load state: blue banner + skeleton role/metric tiles.
             Matches the clients/keyspace cards so an SSH round-trip in flight
             reads as a deliberate "we're working on it" instead of a stale
             tab. wire:poll.15s on the card root keeps re-dispatching until
             the worker writes the snapshot to cache. --}}
        <div class="mt-4 flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-xs text-sky-900">
            <svg class="mt-0.5 h-4 w-4 shrink-0 animate-spin text-sky-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke-linecap="round" />
            </svg>
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ __('Reading INFO replication over SSH…') }}</p>
                <p class="mt-0.5 text-sky-800/90">{{ __('Pulls master/replica role + link state from the engine. Typically 1–2 seconds; the dashboard auto-refreshes every 15 seconds.') }}</p>
            </div>
        </div>
        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
            @foreach (['Role', 'Replication ID', 'Connected replicas'] as $label)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __($label) }}</p>
                    <div class="mt-2 h-5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            @endforeach
        </dl>
    @elseif (! $state['reachable'])
        <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">{{ __('Engine unreachable — start the service from the Overview subtab to populate replication state.') }}</p>
    @else
        @if ($isReplica)
            {{-- Replica view: surface upstream master + link health. --}}
            <dl class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Master endpoint') }}</dt>
                    <dd class="mt-1 truncate font-mono text-sm text-brand-ink">{{ $state['master_endpoint'] ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Link status') }}</dt>
                    <dd class="mt-1 text-sm font-semibold {{ $state['master_link_status'] === 'up' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $state['master_link_status'] ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last IO from master') }}</dt>
                    <dd class="mt-1 text-sm text-brand-ink">
                        @if ($state['master_last_io_seconds_ago'] !== null)
                            {{ trans_choice('{1} :n second ago|[2,*] :n seconds ago', (int) $state['master_last_io_seconds_ago'], ['n' => $state['master_last_io_seconds_ago']]) }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sync in progress') }}</dt>
                    <dd class="mt-1 text-sm text-brand-ink">{{ $state['master_sync_in_progress'] ? __('Yes') : __('No') }}</dd>
                </div>
            </dl>
        @else
            {{-- Master view: surface connected replicas. --}}
            <dl class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Connected replicas') }}</dt>
                    <dd class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $state['connected_replicas'] }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2 sm:col-span-2">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Replication ID') }}</dt>
                    <dd class="mt-1 truncate font-mono text-xs text-brand-ink" title="{{ $state['master_replid'] }}">{{ $state['master_replid'] ?? '—' }}</dd>
                </div>
            </dl>

            @if (! empty($state['replicas']))
                <div class="mt-5 overflow-x-auto rounded-lg border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                        <thead class="bg-brand-sand/30 text-[10px] uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('Address') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('State') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Offset') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Lag') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($state['replicas'] as $replica)
                                @php
                                    $stateClass = $replica['state'] === 'online' ? 'text-emerald-700' : 'text-amber-700';
                                @endphp
                                <tr>
                                    <td class="px-3 py-2 font-mono text-brand-ink">{{ $replica['address'] }}</td>
                                    <td class="px-3 py-2 font-semibold {{ $stateClass }}">{{ $replica['state'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-brand-ink">{{ number_format((int) $replica['offset']) }}</td>
                                    <td class="px-3 py-2 text-right text-brand-moss">{{ trans_choice('{1} :n second|[2,*] :n seconds', (int) $replica['lag_seconds'], ['n' => $replica['lag_seconds']]) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
                    <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No replicas connected') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">{{ __('Standalone master — operating without redundancy. Use Add replica above to attach one from your fleet.') }}</p>
                </div>
            @endif

            {{-- dply-tracked replications (rows in server_cache_service_replications).
                 These are replicas we attached via the wizard; INFO replication may
                 list more if someone wired one up manually outside dply. --}}
            @if ($activeReplications->isNotEmpty())
                <div class="mt-5">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tracked replicas') }}</p>
                    <ul class="mt-2 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                        @foreach ($activeReplications as $tracked)
                            <li class="flex items-center justify-between gap-3 px-3 py-2">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-semibold text-brand-ink">{{ $tracked->replicaCacheService?->server?->name ?? '—' }}</p>
                                    <p class="mt-0.5 text-[11px] text-brand-moss">
                                        {{ __('Status: :s', ['s' => $tracked->status]) }}
                                        @if ($tracked->last_polled_at)
                                            · {{ __('Polled :time', ['time' => $tracked->last_polled_at->diffForHumans()]) }}
                                        @endif
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="removeReplica('{{ $tracked->id }}')"
                                    wire:confirm="{{ __('Detach this replica? REPLICAOF NO ONE is issued; the data already replicated stays on the target.') }}"
                                    class="shrink-0 rounded-md border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-medium text-red-700 hover:bg-red-100"
                                >
                                    {{ __('Detach') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    @endif

    @include('livewire.servers.partials.cache.add-replica-modal', [
        'row' => $row,
        'availableReplicaServers' => $availableReplicaServers,
    ])
</div>
