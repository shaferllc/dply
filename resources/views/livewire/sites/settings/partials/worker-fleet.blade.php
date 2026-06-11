{{-- Worker servers (the app's worker pool) — detect attached worker SERVERS and
     scale them up/down. Distinct from the Workers/daemons tab (Supervisor
     processes on this box). Actions delegate to WorkerPoolManager; deep controls
     (autoscale, cross-region, queue/Horizon config) live on the pool page. --}}
@php
    $pools = $site->attachedWorkerPools();
    // Candidate pools in the org not yet attached (the picker), and which of the
    // attached pools are EXPLICIT (operator-defined → detachable) vs implicit.
    $availablePools = $site->availableWorkerPools();
    $explicitPoolIds = $site->workerPools()->pluck('worker_pools.id')->all();
    // Running worker processes on a member (from the last stats probe) — the best
    // available proxy for how much of the shared pull-queue this box is draining.
    $workerProcs = function ($m) {
        $s = is_array(data_get($m->meta, 'pool.stats')) ? data_get($m->meta, 'pool.stats') : [];
        return max((int) ($s['horizon_procs'] ?? 0), (int) ($s['queue_procs'] ?? 0), (int) ($s['sv_running'] ?? 0));
    };
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-square-3-stack-3d class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Background capacity') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Worker servers') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Worker servers in this workspace run your queues/background jobs. Scale them up when the backlog grows, down when it’s quiet.') }}</p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
        @if ($pools->isEmpty())
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-4 text-sm text-brand-moss">
                <p class="font-medium text-brand-ink">{{ __('No worker servers attached.') }}</p>
                <p class="mt-1">{{ __('Provision a worker server in this workspace (a server with the “worker” role), then create a pool from its server page to scale background processing.') }}</p>
                <a href="{{ route('servers.index') }}" wire:navigate class="mt-2 inline-block text-xs font-semibold text-brand-forest hover:underline">{{ __('Go to servers') }} →</a>
            </div>
        @else
            <div class="space-y-5">
                @foreach ($pools as $pool)
                    @php
                        $members = $pool->servers;
                        $active = $members->count();
                        $desired = (int) $pool->desired_count;
                        $cap = (int) ($pool->max_size ?: 50);
                        $primary = $pool->primaryServer;
                        // Pool-wide workload (Horizon snapshot on pool meta) + total worker
                        // processes across members (the denominator for each worker's share).
                        $hz = is_array($pool->meta['horizon'] ?? null) ? $pool->meta['horizon'] : [];
                        $poolProcs = (int) $members->sum(fn ($m) => $workerProcs($m));
                        $collectedAt = $members->map(fn ($m) => data_get($m->meta, 'pool.stats.collected_at'))->filter()->sort()->last();
                    @endphp
                    <div class="rounded-2xl border border-brand-ink/10 bg-white p-4" x-data="{ n: {{ $desired ?: $active }} }">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-brand-ink">{{ $pool->name ?: __('Worker pool') }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ trans_choice(':n worker|:n workers', $active, ['n' => $active]) }} · {{ __('target') }} {{ $desired ?: $active }} · {{ __('max') }} {{ $cap }} · {{ __('status') }} {{ $pool->status }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                @if ($primary)
                                    <a href="{{ route('servers.worker-pool', ['server' => $primary]) }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Autoscale · queues · cross-region') }} →</a>
                                @endif
                                @can('update', $site)
                                    @if (in_array($pool->id, $explicitPoolIds, true))
                                        <button type="button"
                                            wire:click="detachWorkerPool(@js((string) $pool->id))"
                                            wire:confirm="{{ __('Detach this worker pool from the site? The pool keeps running; it just stops being listed here.') }}"
                                            wire:loading.attr="disabled"
                                            class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-moss hover:bg-brand-sand/40 disabled:opacity-50">{{ __('Detach') }}</button>
                                    @endif
                                @endcan
                            </div>
                        </div>

                        @can('update', $site)
                            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-brand-ink/10 pt-3">
                                <span class="text-xs font-medium text-brand-moss">{{ __('Scale to') }}</span>
                                <input type="number" min="1" max="{{ $cap }}" x-model.number="n" class="w-20 rounded-lg border border-brand-ink/15 px-2 py-1 text-sm" />
                                <button type="button" x-on:click="$wire.scaleWorkerPool(@js((string) $pool->id), n)" wire:loading.attr="disabled"
                                    class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-forest disabled:opacity-50">{{ __('Apply') }}</button>
                                <button type="button" wire:click="addPoolWorker(@js((string) $pool->id))" wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Add worker') }}
                                </button>
                            </div>
                        @endcan

                        {{-- Live workload. The pool is a PULL queue — every worker drains the
                             SAME Redis queues, so there's no per-worker job assignment: the
                             backlog/throughput are pool-wide, and each worker's "share" below is
                             its running worker processes ÷ the pool's (its drain capacity). --}}
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-brand-ink/10 pt-3 text-xs text-brand-moss">
                            <span><span class="font-semibold text-brand-ink">{{ $hz['pending'] ?? '—' }}</span> {{ __('in queue') }}</span>
                            <span><span class="font-semibold text-brand-ink">{{ $hz['jobs_per_minute'] ?? '—' }}</span> {{ __('jobs/min') }}</span>
                            <span>{{ __('processed') }} <span class="font-medium text-brand-ink">{{ $hz['processed'] ?? '—' }}</span></span>
                            <span>{{ __('failed') }} <span class="font-medium text-brand-ink">{{ $hz['failed'] ?? '—' }}</span></span>
                            <span>{{ trans_choice(':n worker process|:n worker processes', $poolProcs, ['n' => $poolProcs]) }}</span>
                            @can('update', $site)
                                <button type="button" wire:click="refreshWorkerStats(@js((string) $pool->id))" wire:loading.attr="disabled" wire:target="refreshWorkerStats"
                                    class="ml-auto inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="refreshWorkerStats" /> {{ __('Refresh') }}
                                </button>
                            @endcan
                        </div>
                        @if ($collectedAt)
                            <p class="mt-1 text-[10px] text-brand-mist">{{ __('Stats as of :t', ['t' => \Illuminate\Support\Carbon::parse($collectedAt)->diffForHumans()]) }}</p>
                        @endif

                        <ul class="mt-3 divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                            @foreach ($members as $member)
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('servers.overview', ['server' => $member]) }}" wire:navigate class="truncate font-medium text-brand-ink hover:text-brand-sage hover:underline">{{ $member->name }}</a>
                                            @if ($member->isPoolPrimary())
                                                <span class="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800">{{ __('primary') }}</span>
                                            @else
                                                <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('replica') }}</span>
                                            @endif
                                            @if ($member->poolMemberState())
                                                <span class="text-[10px] text-brand-mist">{{ $member->poolMemberState() }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-0.5 truncate font-mono text-[11px] text-brand-mist">{{ $member->ip_address ?? '—' }} · {{ $member->region ?? '—' }} · {{ $member->size ?? '—' }}</p>
                                        @php
                                            $mProcs = $workerProcs($member);
                                            $share = $poolProcs > 0 ? (int) round($mProcs / $poolProcs * 100) : 0;
                                        @endphp
                                        <div class="mt-1.5 flex items-center gap-2" title="{{ __('Worker processes on this box — its share of the pool\'s drain capacity (pull queue, so no fixed per-worker assignment).') }}">
                                            <span class="text-[10px] text-brand-moss">{{ trans_choice(':n process|:n processes', $mProcs, ['n' => $mProcs]) }}</span>
                                            <div class="h-1.5 w-24 overflow-hidden rounded-full bg-brand-sand/60">
                                                <div class="h-full rounded-full bg-violet-500" style="width: {{ $share }}%"></div>
                                            </div>
                                            <span class="text-[10px] font-medium text-brand-mist">{{ $share }}%</span>
                                        </div>
                                    </div>
                                    @can('update', $site)
                                        @unless ($member->isPoolPrimary())
                                            <button type="button"
                                                wire:click="removePoolWorker(@js((string) $pool->id), @js((string) $member->id))"
                                                wire:confirm="{{ __('Drain and destroy this worker server? In-flight jobs finish first.') }}"
                                                wire:loading.attr="disabled"
                                                class="shrink-0 rounded-lg border border-red-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50">{{ __('Remove') }}</button>
                                        @endunless
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Attach existing worker pools in this organization. Lets the operator
             define which workers serve this site (instead of only the implicit
             "pool whose source server is this site's server"). --}}
        @can('update', $site)
            @if ($availablePools->isNotEmpty())
                <div class="mt-6 rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Attach worker servers') }}</p>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Worker pools in this organization you can attach to this site.') }}</p>
                    <ul class="mt-3 divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                        @foreach ($availablePools as $candidate)
                            <li class="flex items-center justify-between gap-3 px-3 py-2.5 text-sm" wire:key="attach-pool-{{ $candidate->id }}">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-brand-ink">{{ $candidate->name ?: __('Worker pool') }}</p>
                                    <p class="mt-0.5 truncate text-[11px] text-brand-mist">
                                        {{ trans_choice(':n worker|:n workers', $candidate->servers->count(), ['n' => $candidate->servers->count()]) }}
                                        @if ($candidate->sourceServer)· {{ __('source') }}: {{ $candidate->sourceServer->name }}@endif
                                        · {{ __('status') }} {{ $candidate->status }}
                                    </p>
                                </div>
                                <button type="button"
                                    wire:click="attachWorkerPool(@js((string) $candidate->id))"
                                    wire:loading.attr="disabled"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-forest/30 hover:bg-brand-forest/5 hover:text-brand-forest disabled:opacity-50">
                                    <x-heroicon-o-link class="h-3.5 w-3.5" /> {{ __('Attach') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endcan
    </div>
</section>
