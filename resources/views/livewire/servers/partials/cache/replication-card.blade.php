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

    @if ($error)
        <p class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">{{ $error }}</p>
    @elseif ($state === null)
        <p class="mt-4 text-xs text-brand-mist">{{ __('Loading…') }}</p>
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
                <p class="mt-4 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-xs text-brand-moss">{{ __('No replicas connected. Standalone master — operating without redundancy.') }}</p>
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
