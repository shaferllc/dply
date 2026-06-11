<x-server-workspace-layout
    :server="$server"
    active="worker-pool"
    :title="__('Worker Pool')"
    :description="__('Clone this worker and scale background capacity. The pool keeps one primary (scheduler owner); the rest are queue-worker replicas.')"
    :context-site="null"
>
    @if (! $pool)
        {{-- No pool yet: offer to create one from this worker. --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Scaling') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create a worker pool') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Turn this worker into the primary of a pool. You can then scale to N workers — each clone replays this server\'s sites and joins the same queue.') }}
                    </p>
                </div>
            </div>

            @if ($server->isWorkerHost())
                <form wire:submit="createPool" class="space-y-5 px-6 py-6 sm:px-7">
                    <div>
                        <x-input-label for="pool_name" :value="__('Pool name')" />
                        <x-text-input id="pool_name" wire:model="pool_name" class="mt-2 block w-full text-sm" />
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button type="submit">{{ __('Create pool') }}</x-primary-button>
                    </div>
                </form>
            @else
                <div class="px-6 py-6 sm:px-7">
                    <p class="text-sm text-brand-moss">{{ __('This server is not a worker host, so it cannot start a worker pool. Worker pools are for servers with the worker role (queue_worker profile).') }}</p>
                </div>
            @endif
        </section>
    @else
        @php
            $active = $pool->activeMemberCount();
            $healthy = $members->filter(fn ($m) => $m->isReady() && in_array($m->poolMemberState(), [null, \App\Models\WorkerPool::MEMBER_ACTIVE], true) || $m->isPoolPrimary())->count();
            $draining = $members->filter(fn ($m) => $m->poolMemberState() === \App\Models\WorkerPool::MEMBER_DRAINING)->count();
            $converging = $members->filter(fn ($m) => ! $m->isPoolPrimary() && in_array($m->poolMemberState(), [\App\Models\WorkerPool::MEMBER_PROVISIONING, \App\Models\WorkerPool::MEMBER_REPLAYING, \App\Models\WorkerPool::MEMBER_DEPLOYING], true))->count();

            $as = is_array($pool->meta['autoscale'] ?? null) ? $pool->meta['autoscale'] : [];
            $asOn = (bool) ($as['enabled'] ?? false);
            $lastScaledAt = ! empty($as['last_scaled_at']) ? \Illuminate\Support\Carbon::parse($as['last_scaled_at']) : null;

            // Derive the displayed status from LIVE member states, not the
            // persisted `status` column — the reconciler only flips that column
            // when it fully converges, so a stuck/exhausted reconcile leaves it
            // frozen at "scaling" even when nothing is actually happening.
            //   steady   — at/above desired, nothing in flight
            //   scaling  — actively converging (provisioning/replaying/deploying) or draining
            //   behind   — below desired but NOTHING in flight (idle deficit — needs a reconcile)
            //   degraded — a member failed
            $inFlight = $converging > 0 || $draining > 0;
            if ($pool->status === \App\Models\WorkerPool::STATUS_DEGRADED && ! $inFlight) {
                $effectiveStatus = 'degraded';
            } elseif ($inFlight) {
                $effectiveStatus = 'scaling';
            } elseif ($active >= $pool->desired_count) {
                $effectiveStatus = 'steady';
            } else {
                $effectiveStatus = 'behind';
            }

            $statusMeta = match ($effectiveStatus) {
                'steady' => ['label' => __('Steady'), 'tone' => 'emerald', 'blurb' => __('All desired workers are active and healthy.')],
                'scaling' => ['label' => __('Scaling'), 'tone' => 'sky', 'blurb' => __('Converging to the desired worker count — provisioning, replaying, and deploying replicas.')],
                'behind' => ['label' => __('Behind'), 'tone' => 'amber', 'blurb' => __('Below the desired worker count and idle — lower the desired count, or use Reconcile now to provision the missing worker(s).')],
                'degraded' => ['label' => __('Degraded'), 'tone' => 'rose', 'blurb' => __('A member failed to provision or deploy. Check the console below and the member list.')],
                default => ['label' => ucfirst((string) $effectiveStatus), 'tone' => 'slate', 'blurb' => ''],
            };
            $toneClasses = [
                'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
                'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
                'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
                'slate' => 'bg-brand-sand/60 text-brand-moss ring-brand-ink/15',
            ][$statusMeta['tone']];

            $totalCents = $perWorkerCents * max(0, $active);
            $regions = $members->pluck('region')->filter()->unique()->values();
            $providers = $members->map(fn ($m) => $m->provider?->value)->filter()->unique()->values();

            $memberStateMeta = fn (?string $state) => match ($state) {
                \App\Models\WorkerPool::MEMBER_PROVISIONING => ['label' => __('Provisioning'), 'cls' => 'bg-amber-50 text-amber-700 ring-amber-200'],
                \App\Models\WorkerPool::MEMBER_REPLAYING => ['label' => __('Replaying'), 'cls' => 'bg-sky-50 text-sky-700 ring-sky-200'],
                \App\Models\WorkerPool::MEMBER_DEPLOYING => ['label' => __('Deploying'), 'cls' => 'bg-indigo-50 text-indigo-700 ring-indigo-200'],
                \App\Models\WorkerPool::MEMBER_ACTIVE => ['label' => __('Active'), 'cls' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
                \App\Models\WorkerPool::MEMBER_DRAINING => ['label' => __('Draining'), 'cls' => 'bg-rose-50 text-rose-700 ring-rose-200'],
                \App\Models\WorkerPool::MEMBER_ERRORED => ['label' => __('Errored'), 'cls' => 'bg-rose-100 text-rose-800 ring-rose-300'],
                default => ['label' => __('Ready'), 'cls' => 'bg-brand-sand/60 text-brand-moss ring-brand-ink/15'],
            };
        @endphp

        {{-- Pool status — at-a-glance health, capacity, autoscale and spread.
             Re-renders on the console partial's poll while a scale is active. --}}
        <section class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $pool->name }}</p>
                    <div class="mt-1 flex items-center gap-2">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Pool status') }}</h2>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $toneClasses }}">
                            @if ($effectiveStatus === 'scaling')
                                <span class="inline-flex h-4 w-4"><x-spinner variant="forest" size="sm" /></span>
                            @endif
                            {{ $statusMeta['label'] }}
                        </span>
                    </div>
                    @if ($statusMeta['blurb'])
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $statusMeta['blurb'] }}</p>
                    @endif
                </div>
                <div class="flex flex-col items-end gap-2 text-right text-xs text-brand-moss">
                    <p>{{ __('Updated :ago', ['ago' => $pool->updated_at?->diffForHumans() ?? '—']) }}</p>
                    @if ($scaleRun)
                        <p class="text-brand-forest">{{ __('Live scaling run below ↓') }}</p>
                    @endif
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="reconcileNow"
                            wire:loading.attr="disabled"
                            wire:target="reconcileNow"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            title="{{ __('Re-run the reconciler to advance stuck members and re-check pending deploys.') }}"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                            {{ __('Reconcile now') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('tearDownPool', [], @js(__('Tear down pool')), @js(__('This drains and DESTROYS all :n replica(s). :primary stays as a standalone worker. This cannot be undone.', ['n' => max(0, $members->count() - 1), 'primary' => $pool->primaryServer?->name ?? $server->name])), @js(__('Tear down pool')), true)"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm transition-colors hover:bg-rose-50"
                        >
                            <x-heroicon-o-trash class="h-4 w-4" />
                            {{ __('Tear down pool') }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- Capacity tiles --}}
            <dl class="grid grid-cols-2 gap-px bg-brand-ink/5 sm:grid-cols-3 lg:grid-cols-6">
                @php
                    $tiles = [
                        ['k' => __('Desired'), 'v' => $pool->desired_count],
                        ['k' => __('Active'), 'v' => $active],
                        ['k' => __('Healthy'), 'v' => $healthy],
                        ['k' => __('Converging'), 'v' => $converging],
                        ['k' => __('Draining'), 'v' => $draining],
                        ['k' => __('Max'), 'v' => $pool->max_size],
                    ];
                @endphp
                @foreach ($tiles as $t)
                    <div class="bg-white px-4 py-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $t['k'] }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ $t['v'] }}</dd>
                    </div>
                @endforeach
            </dl>

            {{-- Secondary facts --}}
            <div class="grid grid-cols-1 gap-x-8 gap-y-2 px-6 py-4 text-sm sm:grid-cols-2 sm:px-7">
                <p class="text-brand-moss">
                    <span class="font-medium text-brand-ink">{{ __('Primary') }}:</span>
                    {{ $pool->primaryServer?->name ?? '—' }}
                </p>
                <p class="text-brand-moss">
                    <span class="font-medium text-brand-ink">{{ __('Est. cost') }}:</span>
                    @if ($perWorkerCents > 0)
                        ${{ number_format($totalCents / 100, 2) }}/mo ({{ __(':n × $:each', ['n' => $active, 'each' => number_format($perWorkerCents / 100, 2)]) }})
                    @else
                        {{ __('unknown') }}
                    @endif
                </p>
                <p class="text-brand-moss">
                    <span class="font-medium text-brand-ink">{{ __('Regions') }}:</span>
                    {{ $regions->isNotEmpty() ? $regions->implode(', ') : '—' }}
                </p>
                <p class="text-brand-moss">
                    <span class="font-medium text-brand-ink">{{ __('Providers') }}:</span>
                    {{ $providers->isNotEmpty() ? $providers->implode(', ') : '—' }}
                </p>
                <p class="text-brand-moss sm:col-span-2">
                    <span class="font-medium text-brand-ink">{{ __('Autoscaling') }}:</span>
                    @if ($asOn)
                        <span class="font-medium text-brand-forest">{{ __('on') }}</span>
                        — {{ __(':min–:max workers, :n jobs/worker', ['min' => $as['min'] ?? 1, 'max' => $as['max'] ?? $pool->max_size, 'n' => $as['per_worker_backlog'] ?? 100]) }}
                        @if (isset($as['last_backlog'])) · {{ __('last backlog :n', ['n' => $as['last_backlog']]) }} @endif
                        @if ($lastScaledAt) · {{ __('last scaled :ago', ['ago' => $lastScaledAt->diffForHumans()]) }} @endif
                    @else
                        <span class="text-brand-moss">{{ __('off — set the desired count manually below') }}</span>
                    @endif
                </p>
            </div>
        </section>


        {{-- Live scaling console — the reconciler streams provision / replay /
             deploy / drain progress here while the pool converges. --}}
        @include('livewire.partials.console-action-banner-static', [
            'run' => $scaleRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])

        {{-- Tabs --}}
        @php
            $tabs = [
                'overview' => __('Overview'),
                'members' => __('Members'),
                'horizon' => __('Horizon'),
                'traffic' => __('Traffic & Redis'),
            ];
        @endphp
        <div class="mt-6 border-b border-brand-ink/10">
            <nav class="-mb-px flex flex-wrap gap-1" aria-label="{{ __('Worker pool sections') }}">
                @foreach ($tabs as $tabKey => $tabLabel)
                    <button
                        type="button"
                        wire:click="$set('tab', @js($tabKey))"
                        @class([
                            'border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors',
                            'border-brand-forest text-brand-forest' => $tab === $tabKey,
                            'border-transparent text-brand-moss hover:text-brand-ink hover:border-brand-ink/20' => $tab !== $tabKey,
                        ])
                    >
                        {{ $tabLabel }}
                        @if ($tabKey === 'members')
                            <span class="ml-1 rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-[11px] text-brand-moss">{{ $members->count() }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        </div>

        @if ($tab === 'overview')
        {{-- Poll the whole component every 15s so pool status, capacity and
             member health stay fresh without a manual reload. --}}
        <div wire:poll.15s></div>
        {{-- Scale control --}}
        <section class="dply-card mt-6 overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-arrows-pointing-out class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $pool->name }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Scale workers') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Desired :desired · active :active · healthy :healthy · max :max · status :status', [
                            'desired' => $pool->desired_count,
                            'active' => $active,
                            'healthy' => $healthy,
                            'max' => $pool->max_size,
                            'status' => $pool->status,
                        ]) }}
                    </p>
                </div>
            </div>

            <form wire:submit="scale" class="flex flex-wrap items-end gap-4 px-6 py-6 sm:px-7">
                <div>
                    <x-input-label for="desired_count" :value="__('Desired worker count (incl. primary)')" />
                    <x-text-input id="desired_count" type="number" min="1" :max="$pool->max_size" wire:model="desired_count" class="mt-2 block w-32 text-sm" />
                    <x-input-error :messages="$errors->get('desired_count')" class="mt-2" />
                </div>
                <div class="pb-0.5">
                    <x-primary-button type="submit">{{ __('Apply scale') }}</x-primary-button>
                </div>
                @php
                    $delta = (int) $desired_count - $active;
                    $costDelta = abs($delta) * $perWorkerCents;
                    $costLabel = $perWorkerCents > 0 ? ' ≈ $'.number_format($costDelta / 100, 2).'/mo' : '';
                @endphp
                @if ($delta !== 0)
                    <p class="pb-1 text-xs text-brand-moss">
                        {{ $delta > 0
                            ? __('+:n server(s) will be provisioned (billable):cost.', ['n' => $delta, 'cost' => $costLabel])
                            : __(':n server(s) will be drained and destroyed (saves:cost).', ['n' => abs($delta), 'cost' => $costLabel]) }}
                    </p>
                @endif
            </form>
        </section>

        {{-- Autoscaling --}}
        <section class="dply-card mt-6 overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Autoscaling') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Scale by queue backlog') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('When enabled, dply checks the queue backlog every 5 minutes and sets the desired worker count to backlog ÷ jobs-per-worker, clamped to your min/max.') }}
                        @if (! empty($pool->meta['autoscale']['last_backlog']))
                            {{ __('Last backlog: :n.', ['n' => $pool->meta['autoscale']['last_backlog']]) }}
                        @endif
                    </p>
                </div>
            </div>
            <form wire:submit="saveAutoscale" class="space-y-4 px-6 py-6 sm:px-7">
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model="as_enabled" class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
                    <span class="text-sm font-medium text-brand-ink">{{ __('Enable autoscaling') }}</span>
                </label>
                <div class="flex flex-wrap gap-4">
                    <div>
                        <x-input-label for="as_min" :value="__('Min workers')" />
                        <x-text-input id="as_min" type="number" min="1" wire:model="as_min" class="mt-2 block w-28 text-sm" />
                    </div>
                    <div>
                        <x-input-label for="as_max" :value="__('Max workers')" />
                        <x-text-input id="as_max" type="number" min="1" :max="$pool->max_size" wire:model="as_max" class="mt-2 block w-28 text-sm" />
                    </div>
                    <div>
                        <x-input-label for="as_backlog" :value="__('Jobs per worker')" />
                        <x-text-input id="as_backlog" type="number" min="1" wire:model="as_backlog" class="mt-2 block w-32 text-sm" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <x-primary-button type="submit">{{ __('Save autoscaling') }}</x-primary-button>
                </div>
            </form>
        </section>

        {{-- Cross-region exposure plan: which private backends must be exposed
             + this clone's IP allowlisted. dply does not open these automatically. --}}
        @php $plan = $this->exposurePlan(); @endphp
        @if (! empty($plan))
            <section class="dply-card mt-6 overflow-hidden border border-amber-200">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-amber-200 bg-amber-50 px-6 py-4 sm:px-7">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-amber-900">{{ __('Cross-region backend exposure') }}</h2>
                        <p class="mt-1 text-sm text-amber-800">{{ __('These workers reach private services over the public network. dply can bind each backend (password-gated) and allowlist only these worker IPs at the firewall.') }}</p>
                    </div>
                    <button type="button" wire:click="openConfirmActionModal('applyExposure', [], @js(__('Expose & allowlist backends')), @js(__('Bind these backends (password-gated) and allowlist the worker IPs at the firewall now?')), @js(__('Expose & allowlist')), false)" class="shrink-0 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">{{ __('Expose & allowlist now') }}</button>
                </div>
                @php $exposure = $pool->meta['exposure'] ?? null; @endphp
                @if ($exposure)
                    <div class="border-b border-amber-100 bg-amber-50/50 px-6 py-3 text-xs sm:px-7">
                        @foreach (($exposure['applied'] ?? []) as $line)
                            <p class="text-emerald-800">✓ {{ $line }}</p>
                        @endforeach
                        @foreach (($exposure['warnings'] ?? []) as $line)
                            <p class="text-amber-900">⚠ {{ $line }}</p>
                        @endforeach
                    </div>
                @endif
                <div class="divide-y divide-amber-100">
                    @foreach ($plan as $e)
                        <div class="px-6 py-3 text-sm sm:px-7">
                            <span class="font-medium text-brand-ink">{{ $e['server_name'] ?? $e['server_id'] }}</span>
                            <span class="text-brand-moss">
                                — {{ __('expose') }} {{ implode(', ', array_map(fn ($k) => $k, $e['keys'] ?? [])) }}
                                @if (! empty($e['ports'])) ({{ __('port(s)') }} {{ implode(', ', $e['ports']) }}) @endif
                                · {{ __('allowlist') }} <code class="rounded bg-amber-100 px-1">{{ ($e['member_ip'] ?? '?') }}/32</code>
                            </span>
                            <a href="{{ route('servers.overview', $e['server_id']) }}" wire:navigate class="ml-1 font-medium text-brand-forest hover:underline">{{ __('open backend') }}</a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Add a worker in another region/provider (Phase 2). --}}
        <section class="dply-card mt-6 overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Cross-region') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add a worker in another region') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Provision one replica in a different region (same provider). Its env is rewritten to reach your backends over their public address — you\'ll then need to expose + allowlist those backends (shown above once it\'s ready).') }}
                    </p>
                </div>
            </div>
            <form wire:submit="addCrossRegion" class="space-y-4 px-6 py-6 sm:px-7">
                <div class="flex flex-wrap gap-4">
                    <div>
                        <x-input-label for="cr_provider" :value="__('Provider')" />
                        <select id="cr_provider" wire:model.live="cr_provider" class="dply-input mt-2 w-48 text-sm">
                            <option value="">{{ __('Same as source') }} ({{ $server->provider->value }})</option>
                            @foreach ($providerOptions as $p)
                                <option value="{{ $p }}">{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($cr_provider !== '' && $cr_provider !== $server->provider->value)
                        <div>
                            <x-input-label for="cr_credential_id" :value="__('Credential')" />
                            <select id="cr_credential_id" wire:model.live="cr_credential_id" class="dply-input mt-2 w-56 text-sm">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach ($credentialOptions as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <x-input-label for="cr_region" :value="__('Region')" />
                        @if (! empty($cr_regions))
                            <select id="cr_region" wire:model.live="cr_region" class="dply-input mt-2 w-56 text-sm">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach ($cr_regions as $r)
                                    <option value="{{ $r['value'] }}">{{ $r['label'] ?? $r['value'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <x-text-input id="cr_region" wire:model="cr_region" class="mt-2 block w-48 text-sm" placeholder="{{ $server->region }}" />
                        @endif
                    </div>
                    <div>
                        <x-input-label for="cr_size" :value="__('Size')" />
                        @if (! empty($cr_sizes))
                            <select id="cr_size" wire:model="cr_size" class="dply-input mt-2 w-64 text-sm">
                                <option value="">{{ __('Default (match source)') }}</option>
                                @foreach ($cr_sizes as $s)
                                    <option value="{{ $s['value'] }}">{{ $s['label'] ?? $s['value'] }}@isset($s['price_monthly'])@if($s['price_monthly']) — ${{ number_format((float) $s['price_monthly'], 2) }}/mo @endif @endisset</option>
                                @endforeach
                            </select>
                        @else
                            <x-text-input id="cr_size" wire:model="cr_size" class="mt-2 block w-48 text-sm" placeholder="{{ $server->size }}" />
                        @endif
                    </div>
                </div>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="cr_ack_secrets" class="mt-0.5 h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
                    <span class="text-xs text-brand-moss">{{ __('I understand this server\'s secrets (.env, including credentials) will be replicated to the new region/provider.') }}</span>
                </label>
                <div class="flex justify-end">
                    <x-primary-button type="submit">{{ __('Provision cross-region worker') }}</x-primary-button>
                </div>
            </form>
        </section>
        @endif {{-- /overview --}}

        @if ($tab === 'members')
        <div wire:poll.15s></div>
        {{-- Members --}}
        <section class="dply-card overflow-hidden mt-6">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Members') }}</h2>
            </div>
            <div class="divide-y divide-brand-ink/10">
                @foreach ($members as $member)
                    @php
                        $sm = $memberStateMeta($member->poolMemberState());
                        $isReady = $member->isReady();
                        $pendingDeploys = is_array($member->meta['pool']['pending_deploys'] ?? null) ? count($member->meta['pool']['pending_deploys']) : 0;
                        $crossRegion = (bool) ($member->meta['cross_region'] ?? false);
                    @endphp
                    <div class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex h-2 w-2 shrink-0 rounded-full {{ $isReady ? 'bg-emerald-500' : 'bg-amber-400' }}" title="{{ $isReady ? __('Server ready') : __('Server not ready') }}"></span>
                                <a href="{{ route('servers.overview', $member) }}" wire:navigate class="text-sm font-semibold text-brand-ink hover:text-brand-forest hover:underline">{{ $member->name }}</a>
                                @if ($member->isPoolPrimary())
                                    <span class="rounded-full bg-brand-forest/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Primary') }}</span>
                                @else
                                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Replica') }}</span>
                                @endif
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $sm['cls'] }}">{{ $sm['label'] }}</span>
                                @if ($crossRegion)
                                    <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-700 ring-1 ring-indigo-200">{{ __('Cross-region') }}</span>
                                @endif
                            </div>
                            <p class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-brand-moss">
                                <span><span class="text-brand-mist">{{ __('region') }}</span> {{ $member->region ?: '—' }}</span>
                                <span><span class="text-brand-mist">{{ __('size') }}</span> {{ $member->size ?: '—' }}</span>
                                <span><span class="text-brand-mist">{{ __('provider') }}</span> {{ $member->provider?->value ?? '—' }}</span>
                                <span><span class="text-brand-mist">{{ __('ip') }}</span> <code class="rounded bg-brand-sand/50 px-1">{{ $member->ip_address ?: __('pending') }}</code></span>
                                <span><span class="text-brand-mist">{{ __('server') }}</span> {{ $member->status }}</span>
                                <span><span class="text-brand-mist">{{ __('created') }}</span> {{ $member->created_at?->diffForHumans() ?? '—' }}</span>
                                @if ($pendingDeploys > 0)
                                    <span class="text-indigo-700">{{ __(':n deploy(s) pending', ['n' => $pendingDeploys]) }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="inline-flex overflow-hidden rounded-lg border border-brand-ink/15" title="{{ __('Start / restart / stop this member\'s worker daemon (Horizon or queue:work) via the active process manager.') }}">
                                <button type="button" wire:click="controlMemberWorkers('{{ $member->id }}', 'start')" class="px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Start') }}</button>
                                <button type="button" wire:click="controlMemberWorkers('{{ $member->id }}', 'restart')" class="border-l border-brand-ink/15 px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Restart') }}</button>
                                <button type="button" wire:click="controlMemberWorkers('{{ $member->id }}', 'stop')" class="border-l border-brand-ink/15 px-2.5 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50">{{ __('Stop') }}</button>
                                <button type="button" wire:click="controlMemberWorkers('{{ $member->id }}', 'check')" class="border-l border-brand-ink/15 px-2.5 py-1.5 text-xs font-medium text-brand-sage hover:bg-brand-sage/10" title="{{ __('Show process manager status in the console below') }}">{{ __('Check') }}</button>
                            </div>
                            @if (! $member->isPoolPrimary())
                                <button type="button" wire:click="openConfirmActionModal('promote', @js([$member->id]), @js(__('Promote to primary')), @js(__(':name will become the pool primary (scheduler owner); the current primary becomes a replica.', ['name' => $member->name])), @js(__('Promote')), false)" class="rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Promote') }}</button>
                                <button type="button" wire:click="openConfirmActionModal('removeMember', @js([$member->id]), @js(__('Drain & destroy worker')), @js(__('Drain and destroy :name? In-flight jobs finish first, then the box is torn down. This cannot be undone.', ['name' => $member->name])), @js(__('Drain & destroy')), true)" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                            @else
                                <span class="text-xs text-brand-mist">{{ __('Promote another member to remove this one') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <p class="mt-4 text-xs text-brand-moss">
            {{ __('Same-region workers join this server\'s private network (env copied verbatim). Cross-region workers reach backends over the public network (env rewritten) and require you to expose + allowlist those backends. Backend exposure is not automated yet.') }}
        </p>
        @endif {{-- /members --}}

        @if ($tab === 'horizon')
        {{-- Horizon dashboard — a read-only mirror of the app's Horizon metrics
             (failed/completed/pending, throughput, per-queue workload, recent
             failed jobs), pulled over SSH from the app's own Horizon. --}}
        @php
            $hz = is_array($pool->meta['horizon'] ?? null) ? $pool->meta['horizon'] : [];
            $hzAt = ! empty($hz['collected_at']) ? \Illuminate\Support\Carbon::parse($hz['collected_at']) : null;
            $hzAttemptAt = ! empty($hz['last_attempt_at']) ? \Illuminate\Support\Carbon::parse($hz['last_attempt_at']) : null;
            $hzError = $hz['error'] ?? null;
            $hzStatus = $hz['status'] ?? null;

            // Whether the member app actually ships Horizon. Live feed shows
            // regardless; the Horizon aggregate layer is gated on this.
            $hzInstalled = ($hz['horizon_installed'] ?? null) !== false;

            // Auto-detected queue config (DetectWorkerPoolHorizonConfigJob) —
            // advisory only; the operator applies it into the form then saves.
            $hzDetection = is_array($pool->meta['horizon_detection'] ?? null) ? $pool->meta['horizon_detection'] : [];
            $hzRec = is_array($hzDetection['recommended'] ?? null) ? $hzDetection['recommended'] : [];

            // Compact relative-time formatter for a seconds value ("3s", "2m", "1h").
            $fmtAge = function ($seconds) {
                if ($seconds === null) { return null; }
                $s = (int) round($seconds);
                if ($s < 60) { return $s.'s ago'; }
                if ($s < 3600) { return floor($s / 60).'m ago'; }
                if ($s < 86400) { return floor($s / 3600).'h ago'; }
                return floor($s / 86400).'d ago';
            };

            // Drift: dply's saved config (#1) vs the box's running supervisor (#3).
            $hzRunning = is_array($hz['running_config'] ?? null) ? $hz['running_config'] : null;
            $hzSaved = \App\Support\WorkerPools\WorkerPoolHorizonConfig::for($pool);
            $hzDrift = [];
            if ($hzRunning) {
                $runQ = collect($hzRunning['queues'] ?? [])->sort()->values()->all();
                $savedQ = collect($hzSaved['queues'] ?? [])->sort()->values()->all();
                if ($runQ !== $savedQ) { $hzDrift['queues'] = ['saved' => implode(', ', $savedQ) ?: '—', 'running' => implode(', ', $runQ) ?: '—']; }
                foreach (['balance', 'max_processes', 'min_processes', 'memory', 'timeout'] as $k) {
                    $r = $hzRunning[$k] ?? null; $sv = $hzSaved[$k] ?? null;
                    if ($r !== null && (string) $r !== (string) $sv) { $hzDrift[$k] = ['saved' => $sv, 'running' => $r]; }
                }
            }
        @endphp
        {{-- No wire:poll here: the Live jobs feed is pushed over Reverb in real
             time. Aggregate tiles/lists refresh on Refresh Horizon / Snapshot. --}}
        <div class="mt-6 space-y-6">
            @unless ($hzInstalled)
                <div class="flex items-start gap-2 rounded-xl border border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                    <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-mist" />
                    <span>{{ __('Horizon isn\'t installed on this app, so aggregate metrics are unavailable — the Live jobs feed below still streams every job in real time. Install laravel/horizon for the full dashboard.') }}</span>
                </div>
            @endunless
            @if ($hzInstalled && ! empty($hzDrift))
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
                    <div class="flex items-center gap-2 font-semibold text-amber-800">
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" />
                        {{ __('Running config differs from saved') }}
                    </div>
                    <p class="mt-1 text-xs text-amber-700">{{ __('The workers are running settings that don\'t match this pool\'s saved config. Click “Save & apply” to push the saved values, or update the saved config to match.') }}</p>
                    <ul class="mt-2 space-y-0.5 font-mono text-[11px] text-amber-800">
                        @foreach ($hzDrift as $field => $d)
                            <li>{{ $field }}: <span class="text-amber-600">saved={{ is_array($d['saved'] ?? null) ? implode(',', $d['saved']) : ($d['saved'] ?? '—') }}</span> → <span class="font-semibold">running={{ is_array($d['running'] ?? null) ? implode(',', $d['running']) : ($d['running'] ?? '—') }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Horizon') }}</p>
                        <div class="mt-0.5 flex items-center gap-2">
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Queue dashboard') }}</h2>
                            @if ($hzStatus)
                                <span @class([
                                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1',
                                    'bg-emerald-50 text-emerald-700 ring-emerald-200' => $hzStatus === 'running',
                                    'bg-amber-50 text-amber-700 ring-amber-200' => $hzStatus === 'paused',
                                    'bg-rose-50 text-rose-700 ring-rose-200' => ! in_array($hzStatus, ['running', 'paused'], true),
                                ])>{{ $hzStatus }}</span>
                            @endif
                        </div>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Live job metrics pulled from the app\'s Horizon over SSH — processed, failed, pending, throughput and recent failures across the pool.') }}</p>
                        @php $watchedQueues = \App\Support\WorkerPools\WorkerPoolHorizonConfig::for($pool)['queues'] ?? ['default']; @endphp
                        <p class="mt-1.5 text-xs text-brand-moss">
                            <span class="font-medium text-brand-ink">{{ __('Queues watched:') }}</span>
                            @foreach ($watchedQueues as $q)
                                <code class="ml-1 rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-[11px]">{{ $q }}</code>
                            @endforeach
                            <span class="ml-1 text-brand-mist">{{ __('· isolated from dply\'s own queues (dply / dply-control)') }}</span>
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-2 text-right text-xs text-brand-moss">
                        <span>{{ $hzAt ? __('collected :ago', ['ago' => $hzAt->diffForHumans()]) : __('no data yet') }}</span>
                        @if ($hzError)
                            <span class="inline-flex max-w-xs items-center gap-1 text-right font-medium text-rose-600" title="{{ $hzError }}">
                                <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" />
                                {{ __('last refresh failed :ago', ['ago' => $hzAttemptAt?->diffForHumans() ?? __('just now')]) }}
                            </span>
                        @elseif ($hzAttemptAt && (! $hzAt || $hzAttemptAt->gt($hzAt)))
                            <span class="text-amber-700">{{ __('refresh ran :ago — no change', ['ago' => $hzAttemptAt->diffForHumans()]) }}</span>
                        @endif
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <div class="inline-flex overflow-hidden rounded-lg border border-brand-ink/15" title="{{ __('Control Horizon on every member.') }}">
                                <button type="button" wire:click="controlPoolHorizon('horizon:pause')" class="px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Pause') }}</button>
                                <button type="button" wire:click="controlPoolHorizon('horizon:continue')" class="border-l border-brand-ink/15 px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Resume') }}</button>
                                <button type="button" wire:click="controlPoolHorizon('horizon:terminate')" class="border-l border-brand-ink/15 px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Restart') }}</button>
                                <button type="button" wire:click="controlPoolHorizon('horizon:snapshot')" class="border-l border-brand-ink/15 px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Snapshot') }}</button>
                            </div>
                            <button type="button" wire:click="runHorizonTestJobs" wire:loading.attr="disabled" wire:target="runHorizonTestJobs"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:opacity-60"
                                title="{{ __('Dispatch 5 throwaway test jobs onto the queue, then re-pull the snapshot to confirm Horizon picked them up.') }}">
                                <x-heroicon-o-beaker class="h-4 w-4" wire:loading.remove wire:target="runHorizonTestJobs" />
                                <span wire:loading wire:target="runHorizonTestJobs" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>
                                {{ __('Test jobs') }}
                            </button>
                            <button type="button" wire:click="refreshHorizon" wire:loading.attr="disabled" wire:target="refreshHorizon"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60">
                                <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="refreshHorizon" />
                                <span wire:loading wire:target="refreshHorizon" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="white" size="sm" /></span>
                                {{ __('Refresh Horizon') }}
                            </button>
                        </div>
                    </div>
                </div>

                @if ($hz === [])
                    <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-7">{{ __('No Horizon data yet — click “Refresh Horizon”. (Requires laravel/horizon on the app and a running worker.)') }}</div>
                @else
                    <dl class="grid grid-cols-2 gap-px bg-brand-ink/5 sm:grid-cols-3 lg:grid-cols-6">
                        @php
                            $hzTiles = [
                                ['k' => __('Processes'), 'v' => $hz['processes'] ?? '—'],
                                ['k' => __('Jobs / min'), 'v' => $hz['jobs_per_minute'] ?? '—'],
                                ['k' => __('Recent'), 'v' => $hz['recent'] ?? '—'],
                                ['k' => __('Completed'), 'v' => $hz['completed'] ?? '—'],
                                ['k' => __('Pending'), 'v' => $hz['pending'] ?? '—'],
                                ['k' => __('Failed (total)'), 'v' => $hz['failed_total'] ?? '—'],
                            ];
                        @endphp
                        @foreach ($hzTiles as $t)
                            <div class="bg-white px-4 py-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $t['k'] }}</dt>
                                <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ is_numeric($t['v']) ? number_format((float) $t['v']) : $t['v'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>

            {{-- Live jobs — real-time per-job feed pushed from the worker boxes
                 over Reverb (no polling); newest first. Populated by the
                 #[On('worker-pool-job')] handler as Echo delivers events. --}}
            <section class="dply-card overflow-hidden" x-data="{ open: true }">
                <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 text-left sm:px-7">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                        <span class="relative flex h-2 w-2" title="{{ __('Live') }}">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Live jobs') }}</h3>
                    </div>
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-semibold text-brand-moss">{{ count($liveJobs) }}</span>
                </button>
                <div x-show="open" x-collapse>
                @if (empty($liveJobs))
                    <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('Waiting for job activity… events stream in here the instant workers process them.') }}</div>
                @else
                    <div class="divide-y divide-brand-ink/5">
                        @foreach ($liveJobs as $i => $j)
                            @php $liveAge = ! empty($j['received_at']) ? max(0, now()->timestamp - (int) $j['received_at']) : null; @endphp
                            <div class="flex flex-wrap items-center gap-2 px-6 py-2.5 sm:px-7" wire:key="livejob-{{ $i }}-{{ $j['received_at'] ?? $i }}">
                                <span class="text-sm font-medium text-brand-ink">{{ $j['name'] }}</span>
                                <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[11px] text-brand-moss">{{ $j['queue'] }}</span>
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1',
                                    'bg-sky-50 text-sky-700 ring-sky-200' => $j['status'] === 'processing',
                                    'bg-emerald-50 text-emerald-700 ring-emerald-200' => $j['status'] === 'completed',
                                    'bg-rose-50 text-rose-700 ring-rose-200' => $j['status'] === 'failed',
                                    'bg-amber-50 text-amber-700 ring-amber-200' => $j['status'] === 'dropped',
                                    'bg-brand-sand/60 text-brand-moss ring-brand-ink/15' => ! in_array($j['status'], ['processing', 'completed', 'failed', 'dropped'], true),
                                ])>{{ $j['status'] }}</span>
                                @if ($liveAge !== null)
                                    <span class="ml-auto text-xs text-brand-mist">{{ $fmtAge($liveAge) }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                </div>
            </section>

            {{-- Test-jobs console — streams the workers processing the throwaway
                 jobs dispatched by the “Test jobs” button above. Same run as the
                 Traffic tab; shown here so dispatch + result sit together. --}}
            @include('livewire.partials.console-action-banner-static', [
                'run' => $testRun,
                'kindLabels' => (array) config('console_actions.kinds', []),
            ])

            {{-- Process manager — always visible, not Horizon-gated. Switching
                 re-provisions every member under the chosen backend and tears
                 down the other. Uses saveProcessManager() (PM only) when changed
                 standalone; saveHorizonConfig() bundles it with Horizon tuning. --}}
            @if ($pool)
            <section class="dply-card overflow-hidden">
                <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-cog-8-tooth class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Process manager') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Worker daemon backend') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Choose how dply manages long-running workers on each member. Switching tears down the current backend and re-provisions under the new one.') }}
                        </p>
                    </div>
                </div>
                <form wire:submit="saveProcessManager" class="space-y-5 px-6 py-5 sm:px-7">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ([
                            ['v' => 'systemd',    'icon' => 'server-stack',    'label' => __('systemd'),    'desc' => __('One unit per worker. Restart=always, journald logs. Default.')],
                            ['v' => 'supervisor', 'icon' => 'rectangle-stack', 'label' => __('Supervisor'), 'desc' => __('supervisord [program] groups. Installs supervisor if not present.')],
                        ] as $pm)
                            <label @class([
                                'flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3 transition-colors',
                                'border-brand-forest bg-brand-sage/10 ring-1 ring-brand-sage/30' => $hz_process_manager === $pm['v'],
                                'border-brand-ink/10 hover:border-brand-ink/20' => $hz_process_manager !== $pm['v'],
                            ])>
                                <input type="radio" wire:model="hz_process_manager" value="{{ $pm['v'] }}" class="mt-0.5 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-brand-ink">{{ $pm['label'] }}</span>
                                    <span class="block text-xs text-brand-moss">{{ $pm['desc'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-brand-ink/8 pt-4">
                        <p class="text-xs text-brand-moss">{{ __('Currently active: :pm. Changing this re-provisions every member over SSH.', ['pm' => $pool->processManager()]) }}</p>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveProcessManager">
                            <span wire:loading.remove wire:target="saveProcessManager">{{ __('Switch backend') }}</span>
                            <span wire:loading wire:target="saveProcessManager">{{ __('Switching…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </section>
            @endif

            {{-- Horizon configuration — env-var driven; dply writes HORIZON_* to
                 each member's .env and restarts the workers. Auto-defaulted.
                 Horizon-only: a queue:work member is configured via its unit. --}}
            @if ($hzInstalled)
            <section class="dply-card overflow-hidden" x-data="{ open: false }">
                <button type="button" x-on:click="open = !open" class="flex w-full items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 text-left sm:px-7">
                    <div>
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Configuration') }}</h3>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Queues, processes, balance, memory, timeout and tries — applied to every worker over SSH.') }}</p>
                    </div>
                    <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                </button>
                <form wire:submit="saveHorizonConfig" x-show="open" x-cloak class="space-y-5 px-6 py-6 sm:px-7">
                    {{-- Auto-detect: SSH to a member, introspect the app's real queues +
                         box spec, and offer a one-click suggestion. Advisory only —
                         nothing is pushed until the operator hits Save & apply below. --}}
                    @if ($hzDetecting)
                        <div wire:poll.3s="checkHorizonDetection" class="flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 animate-spin text-brand-mist" />
                            <span>{{ __('Detecting the app\'s queues over SSH — this takes a few seconds.') }}</span>
                        </div>
                    @elseif (! empty($hzRec['queues']))
                        @php
                            $detQueues = implode(', ', array_map('strval', $hzRec['queues']));
                            $detSource = ($hzDetection['source'] ?? null) === 'package' ? __('package introspection') : __('code scan');
                            $detDiffers = $detQueues !== implode(', ', \App\Support\WorkerPools\WorkerPoolHorizonConfig::for($pool)['queues']);
                        @endphp
                        <div class="rounded-xl border border-brand-forest/25 bg-brand-forest/5 px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-brand-ink">
                                        {{ __('Detected configuration') }}
                                        <span class="ml-1 font-normal text-brand-moss">· {{ $detSource }}</span>
                                    </p>
                                    <p class="mt-1 break-words font-mono text-xs text-brand-ink">{{ $detQueues }}</p>
                                    <p class="mt-1 text-[11px] text-brand-moss">
                                        {{ __('Suggested :min–:max processes · :mem MB · :to s timeout', [
                                            'min' => $hzRec['min_processes'] ?? '—',
                                            'max' => $hzRec['max_processes'] ?? '—',
                                            'mem' => $hzRec['memory'] ?? '—',
                                            'to' => $hzRec['timeout'] ?? '—',
                                        ]) }}
                                        @unless ($detDiffers)<span class="text-brand-mist"> · {{ __('matches current') }}</span>@endunless
                                    </p>
                                </div>
                                <x-secondary-button type="button" wire:click="applyDetectedHorizonConfig" class="shrink-0 text-xs">
                                    {{ __('Apply suggestions') }}
                                </x-secondary-button>
                            </div>
                            <p class="mt-2 text-[11px] text-brand-moss">{{ __('Fills the fields below — review, then Save & apply to push to the workers.') }}</p>
                        </div>
                    @endif

                    <div x-data="{
                        q: @js((string) $hz_queues),
                        get tokens() { return this.q.split(',').map(s => s.trim()).filter(Boolean); },
                        get first() { return this.tokens[0] || '—'; },
                    }" x-on:horizon-config-applied.window="q = $event.detail.queues">
                        <div class="flex items-center justify-between gap-3">
                            <x-input-label for="hz_queues" :value="__('Queues watched')" />
                            <button type="button" wire:click="detectHorizonConfig" wire:loading.attr="disabled" wire:target="detectHorizonConfig" class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:text-brand-ink disabled:opacity-50">
                                <x-heroicon-o-sparkles class="h-3.5 w-3.5" />
                                {{ __('Detect') }}
                            </button>
                        </div>
                        <x-text-input id="hz_queues" wire:model="hz_queues" x-on:input="q = $event.target.value" class="mt-2 block w-full font-mono text-sm" placeholder="default, emails, notifications" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Comma-separated. Workers process these queues in priority order.') }}</p>
                        {{-- Live preview: the FIRST queue is the dispatch target (REDIS_QUEUE) —
                             so a typo here silently misroutes jobs. Make it visible before save. --}}
                        <p class="mt-1 text-[11px] text-brand-moss">
                            {{ __('Dispatch queue (REDIS_QUEUE):') }}
                            <span class="font-mono font-semibold text-brand-ink" x-text="first"></span>
                            <span class="text-brand-mist"> · </span>{{ __('watching') }}
                            <span class="font-mono text-brand-ink" x-text="tokens.join(', ') || '—'"></span>
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="hz_min_processes" :value="__('Min processes')" />
                            <x-text-input id="hz_min_processes" type="number" min="1" max="256" wire:model="hz_min_processes" class="mt-2 block w-full text-sm" />
                        </div>
                        <div>
                            <x-input-label for="hz_max_processes" :value="__('Max processes')" />
                            <x-text-input id="hz_max_processes" type="number" min="1" max="256" wire:model="hz_max_processes" class="mt-2 block w-full text-sm" />
                        </div>
                        <div>
                            <x-input-label for="hz_balance" :value="__('Balance')" />
                            <select id="hz_balance" wire:model="hz_balance" class="mt-2 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                                <option value="auto">{{ __('auto') }}</option>
                                <option value="simple">{{ __('simple') }}</option>
                                <option value="false">{{ __('false (off)') }}</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="hz_memory" :value="__('Memory (MB)')" />
                            <x-text-input id="hz_memory" type="number" min="32" max="4096" wire:model="hz_memory" class="mt-2 block w-full text-sm" />
                        </div>
                        <div>
                            <x-input-label for="hz_timeout" :value="__('Job timeout (s)')" />
                            <x-text-input id="hz_timeout" type="number" min="5" max="3600" wire:model="hz_timeout" class="mt-2 block w-full text-sm" />
                        </div>
                        <div>
                            <x-input-label for="hz_tries" :value="__('Tries')" />
                            <x-text-input id="hz_tries" type="number" min="1" max="25" wire:model="hz_tries" class="mt-2 block w-full text-sm" />
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-brand-moss">{{ __('Saving restarts each worker\'s Horizon to pick up the new settings.') }}</p>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveHorizonConfig">
                            <span wire:loading.remove wire:target="saveHorizonConfig">{{ __('Save & apply') }}</span>
                            <span wire:loading wire:target="saveHorizonConfig">{{ __('Applying…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </section>
            @endif {{-- /$hzInstalled : config panel --}}

            {{-- Per-queue workload --}}
            @if ($hzInstalled && ! empty($hz['workload']))
                <section class="dply-card overflow-hidden" x-data="{ open: true }">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 text-left sm:px-7">
                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Queues') }}</h3>
                    </button>
                    <div x-show="open" x-collapse>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                                <th class="px-6 py-2 sm:px-7">{{ __('Queue') }}</th>
                                <th class="px-3 py-2">{{ __('Length') }}</th>
                                <th class="px-3 py-2">{{ __('Wait') }}</th>
                                <th class="px-3 py-2">{{ __('Processes') }}</th>
                                <th class="px-3 py-2">{{ __('Throughput (jobs/min)') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($hz['workload'] as $w)
                                @php
                                    $series = $hz['queue_throughput'][$w['name'] ?? ''] ?? [];
                                    $series = collect($series)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v)->values()->all();
                                    $spMax = $series ? max(max($series), 0.0001) : 0;
                                    $spN = count($series);
                                    $spPoints = $spN > 1
                                        ? collect($series)->map(fn ($v, $i) => round($i / ($spN - 1) * 100, 1).','.round(18 - ($v / $spMax) * 16, 1))->implode(' ')
                                        : '';
                                    $spLast = $spN ? $series[$spN - 1] : null;
                                @endphp
                                <tr>
                                    <td class="px-6 py-2 font-mono text-brand-ink sm:px-7">{{ $w['name'] ?? '?' }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $w['length'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ isset($w['wait']) ? $w['wait'].'s' : '—' }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $w['processes'] ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if ($spPoints !== '')
                                            <span class="inline-flex items-center gap-2">
                                                <svg viewBox="0 0 100 20" preserveAspectRatio="none" class="h-5 w-24 text-brand-forest">
                                                    <polyline points="{{ $spPoints }}" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                                                </svg>
                                                <span class="font-mono text-xs text-brand-moss">{{ $spLast !== null ? rtrim(rtrim(number_format((float) $spLast, 1), '0'), '.') : '' }}</span>
                                            </span>
                                        @else
                                            <span class="text-brand-mist">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </section>
            @endif

            {{-- Pending + Recent + Completed jobs (Horizon-only aggregate) --}}
            @if ($hzInstalled)
            @php
                $jobLists = [
                    ['key' => 'pending_jobs', 'title' => __('Pending jobs'), 'empty' => __('Nothing waiting in the queue.')],
                    ['key' => 'recent_jobs', 'title' => __('Recent jobs'), 'empty' => __('No recent jobs recorded.')],
                    ['key' => 'completed_jobs', 'title' => __('Completed jobs'), 'empty' => __('No completed jobs recorded.')],
                ];
                $statusTone = fn (string $s) => match (strtolower($s)) {
                    'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                    'failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
                    'reserved', 'running' => 'bg-sky-50 text-sky-700 ring-sky-200',
                    'pending' => 'bg-amber-50 text-amber-700 ring-amber-200',
                    default => 'bg-brand-sand/60 text-brand-moss ring-brand-ink/15',
                };
            @endphp
            @foreach ($jobLists as $list)
                <section class="dply-card overflow-hidden" x-data="{ open: true }">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 text-left sm:px-7">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                            <h3 class="text-sm font-semibold text-brand-ink">{{ $list['title'] }}</h3>
                        </div>
                        <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-semibold text-brand-moss">{{ count($hz[$list['key']] ?? []) }}</span>
                    </button>
                    <div x-show="open" x-collapse>
                    @if (empty($hz[$list['key']]))
                        <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ $list['empty'] }}</div>
                    @else
                        <div class="divide-y divide-brand-ink/5">
                            @foreach ($hz[$list['key']] as $j)
                                <div class="flex flex-wrap items-center gap-2 px-6 py-2.5 sm:px-7">
                                    <span class="text-sm font-medium text-brand-ink">{{ $j['name'] ?? 'job' }}</span>
                                    <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[11px] text-brand-moss">{{ $j['queue'] ?? '?' }}</span>
                                    @if (! empty($j['status']))
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $statusTone($j['status']) }}">{{ $j['status'] }}</span>
                                    @endif
                                    @if (($j['age'] ?? null) !== null)
                                        <span class="ml-auto text-xs text-brand-mist" title="{{ $hzAt ? $hzAt->copy()->subSeconds((int) $j['age'])->toDayDateTimeString() : '' }}">{{ $fmtAge($j['age']) }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    </div>
                </section>
            @endforeach

            {{-- Recent failed jobs --}}
            <section class="dply-card overflow-hidden" x-data="{ open: true }">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                    <button type="button" x-on:click="open = ! open" class="flex items-center gap-2 text-left">
                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Recent failed jobs') }}</h3>
                        @if (($hz['failed_total'] ?? 0) > 0)
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">{{ number_format((float) $hz['failed_total']) }}</span>
                        @endif
                    </button>
                    @if (! empty($hz['failed_jobs']))
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="retryAllFailed" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Retry all') }}</button>
                            <button type="button" wire:click="openConfirmActionModal('flushFailed', [], @js(__('Flush failed jobs')), @js(__('Permanently delete ALL failed jobs? This cannot be undone.')), @js(__('Flush all')), true)" class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50">{{ __('Flush all') }}</button>
                        </div>
                    @endif
                </div>
                <div x-show="open" x-collapse>
                @if (empty($hz['failed_jobs']))
                    <div class="px-6 py-6 text-sm text-brand-moss sm:px-7">{{ __('No recent failed jobs. 🎉') }}</div>
                @else
                    <div class="divide-y divide-brand-ink/5">
                        @foreach ($hz['failed_jobs'] as $fj)
                            <div class="px-6 py-3 sm:px-7" x-data="{ open: false }">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-semibold text-brand-ink">{{ $fj['name'] ?? 'job' }}</span>
                                    <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[11px] text-brand-moss">{{ $fj['queue'] ?? '?' }}</span>
                                    @foreach (($fj['tags'] ?? []) as $tag)
                                        <span class="rounded-full bg-indigo-50 px-1.5 py-0.5 text-[11px] text-indigo-700 ring-1 ring-indigo-100">{{ $tag }}</span>
                                    @endforeach
                                    <span class="ml-auto text-xs text-brand-mist">{{ $fj['failed_at'] ?? '' }}</span>
                                    @if (! empty($fj['uuid']))
                                        <span class="inline-flex overflow-hidden rounded-md border border-brand-ink/15">
                                            <button type="button" wire:click="retryFailedJob('{{ $fj['uuid'] }}')" class="px-2 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Retry') }}</button>
                                            <button type="button" wire:click="openConfirmActionModal('forgetFailedJob', @js([$fj['uuid']]), @js(__('Delete failed job')), @js(__('Permanently delete this failed job?')), @js(__('Delete')), true)" class="border-l border-brand-ink/15 px-2 py-1 text-[11px] font-medium text-rose-700 hover:bg-rose-50">{{ __('Delete') }}</button>
                                        </span>
                                    @endif
                                </div>
                                @if (! empty($fj['exception']))
                                    <button type="button" x-on:click="open = !open" class="mt-1 flex w-full items-start gap-1.5 text-left">
                                        <x-heroicon-o-chevron-right class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                                        <span class="font-mono text-xs text-rose-700/90" x-bind:class="open ? '' : 'truncate'">{{ $fj['exception'] }}</span>
                                    </button>
                                    @if (! empty($fj['exception_full']))
                                        <div x-show="open" x-cloak class="relative mt-2" x-data="{ copied: false, copyTrace() { navigator.clipboard.writeText(@js($fj['exception_full'])).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500); }); } }">
                                            <button type="button" x-on:click="copyTrace()" class="absolute right-2 top-2 z-10 inline-flex items-center gap-1 rounded-md bg-white/10 px-2 py-1 text-[11px] font-medium text-rose-100/90 backdrop-blur transition hover:bg-white/20" :title="copied ? @js(__('Copied!')) : @js(__('Copy stack trace'))">
                                                <x-heroicon-o-clipboard class="h-4 w-4" x-show="! copied" />
                                                <x-heroicon-o-check class="h-4 w-4" x-show="copied" x-cloak />
                                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                                            </button>
                                            <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 pr-20 font-mono text-[11px] leading-relaxed text-rose-100">{{ $fj['exception_full'] }}</pre>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                </div>
            </section>
            @endif {{-- /$hzInstalled : buckets + failed jobs --}}
        </div>
        @endif {{-- /horizon --}}

        @if ($tab === 'traffic')
        {{-- Traffic & Redis — per-member host/worker/Redis stats collected over
             SSH by CollectWorkerPoolStatsJob, plus pool-wide queue backlog. --}}
        <div wire:poll.8s class="mt-6 space-y-6">
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Traffic & Redis') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Live worker stats') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Host load, worker processes and Redis throughput for every member, collected over SSH. Workers share the same queue backend, so Redis figures reflect the whole pool.') }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" wire:click="ensureWorkers" wire:loading.attr="disabled" wire:target="ensureWorkers"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
                            title="{{ __('Define the queue daemon (Horizon if installed, else queue:work) on every member and start it via systemd.') }}">
                            <x-heroicon-o-bolt class="h-4 w-4" wire:loading.remove wire:target="ensureWorkers" />
                            <span wire:loading wire:target="ensureWorkers" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="white" size="sm" /></span>
                            {{ __('Ensure workers everywhere') }}
                        </button>
                        <button type="button" wire:click="runTestJobs" wire:loading.attr="disabled" wire:target="runTestJobs"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            title="{{ __('Dispatch 5 throwaway jobs onto the queue and confirm the workers process them.') }}">
                            <x-heroicon-o-beaker class="h-4 w-4" wire:loading.remove wire:target="runTestJobs" />
                            <span wire:loading wire:target="runTestJobs" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                            {{ __('Run test jobs') }}
                        </button>
                        <button type="button" wire:click="collectStats" wire:loading.attr="disabled" wire:target="collectStats"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="collectStats" />
                            <span wire:loading wire:target="collectStats" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                            {{ __('Refresh stats') }}
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-px bg-brand-ink/5 sm:grid-cols-4">
                    @php
                        // Prefer a live queue size from the probe (queries the app's real
                        // backend), falling back to the autoscaler's last reading.
                        $liveBacklogs = $members->map(fn ($m) => $m->meta['pool']['stats']['queue_size'] ?? null)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v);
                        $backlog = $liveBacklogs->isNotEmpty() ? $liveBacklogs->max() : ($as['last_backlog'] ?? null);
                        $daemonProc = fn ($m) => (int) ($m->meta['pool']['stats']['horizon_procs'] ?? 0) + (int) ($m->meta['pool']['stats']['queue_procs'] ?? 0);
                        $totalWorkers = $members->sum($daemonProc);
                        $horizonMembers = $members->filter(fn ($m) => (int) ($m->meta['pool']['stats']['horizon_procs'] ?? 0) > 0)->count();
                        $daemonRunningMembers = $members->filter(fn ($m) => $daemonProc($m) > 0)->count();
                        $statsMembers = $members->filter(fn ($m) => is_array($m->meta['pool']['stats'] ?? null))->count();
                        $poolTiles = [
                            ['k' => __('Queue backlog'), 'v' => $backlog !== null ? $backlog : '—'],
                            ['k' => __('Worker procs'), 'v' => $statsMembers ? $totalWorkers : '—'],
                            ['k' => __('Daemon running on'), 'v' => $statsMembers ? $daemonRunningMembers.' / '.$members->count() : '—'],
                            ['k' => __('Horizon on'), 'v' => $statsMembers ? $horizonMembers.' / '.$members->count() : '—'],
                        ];
                    @endphp
                    @foreach ($poolTiles as $t)
                        <div class="bg-white px-4 py-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $t['k'] }}</dt>
                            <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ $t['v'] }}</dd>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Stats-probe console: raw per-member host/worker/Redis output (incl. Redis errors) from Refresh stats. --}}
            @include('livewire.partials.console-action-banner-static', [
                'run' => $statsRun,
                'kindLabels' => (array) config('console_actions.kinds', []),
            ])

            {{-- Test-jobs console: dispatches throwaway jobs and shows the workers processing them. --}}
            @include('livewire.partials.console-action-banner-static', [
                'run' => $testRun,
                'kindLabels' => (array) config('console_actions.kinds', []),
            ])

            @foreach ($members as $member)
                @php
                    $st = is_array($member->meta['pool']['stats'] ?? null) ? $member->meta['pool']['stats'] : [];
                    $redisUp = ($st['redis_ping'] ?? '') === 'PONG';
                    $collectedAt = ! empty($st['collected_at']) ? \Illuminate\Support\Carbon::parse($st['collected_at']) : null;
                @endphp
                <section class="dply-card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-white px-6 py-3 sm:px-7">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex h-2 w-2 rounded-full {{ $member->isReady() ? 'bg-emerald-500' : 'bg-amber-400' }}"></span>
                            <span class="text-sm font-semibold text-brand-ink">{{ $member->name }}</span>
                            @if ($member->isPoolPrimary())
                                <span class="rounded-full bg-brand-forest/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Primary') }}</span>
                            @endif
                            @php
                                $hp = (int) ($st['horizon_procs'] ?? 0);
                                $qp = (int) ($st['queue_procs'] ?? 0);
                                if ($hp > 0) {
                                    $daemon = ['label' => __('Horizon running (:n)', ['n' => $hp]), 'cls' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'];
                                } elseif ($qp > 0) {
                                    $daemon = ['label' => __('queue:work (:n)', ['n' => $qp]), 'cls' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'];
                                } elseif ($st !== []) {
                                    $daemon = ['label' => __('No worker running'), 'cls' => 'bg-rose-50 text-rose-700 ring-rose-200'];
                                } else {
                                    $daemon = null;
                                }
                            @endphp
                            @if ($daemon)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $daemon['cls'] }}">{{ $daemon['label'] }}</span>
                            @endif
                        </div>
                        <span class="text-xs text-brand-moss">{{ $collectedAt ? __('collected :ago', ['ago' => $collectedAt->diffForHumans()]) : __('no data yet') }}</span>
                    </div>
                    @if ($st === [])
                        <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('No stats collected yet — click “Refresh stats”.') }}</div>
                    @else
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 px-6 py-5 text-sm sm:grid-cols-3 lg:grid-cols-4 sm:px-7">
                            @php
                                $facts = [
                                    __('Load (1/5/15m)') => $st['load'] ?? '—',
                                    __('CPUs') => $st['cpus'] ?? '—',
                                    __('Memory (used/total MB)') => $st['mem'] ?? '—',
                                    __('Disk /') => $st['disk'] ?? '—',
                                    __('Uptime') => $st['uptime'] ?? '—',
                                    __('Queue backlog') => $st['queue_size'] ?? '—',
                                    __('Horizon procs') => $st['horizon_procs'] ?? '—',
                                    __('queue:work procs') => $st['queue_procs'] ?? '—',
                                    __('systemd worker units') => $st['systemd_workers'] ?? '—',
                                    __('Redis target') => $st['redis_host'] ?? '—',
                                    __('Redis') => $redisUp ? __('up') : ($st['redis_ping'] ?? __('unknown')),
                                    __('Redis memory') => ($st['redis_mem'] ?? '—').(! empty($st['redis_peak']) ? ' (peak '.$st['redis_peak'].')' : ''),
                                    __('Redis clients') => $st['redis_clients'] ?? '—',
                                    __('Redis ops/sec') => $st['redis_ops'] ?? '—',
                                    __('Redis total cmds') => $st['redis_total_cmds'] ?? '—',
                                    __('Redis keys') => $st['redis_keys'] ?? '—',
                                ];
                            @endphp
                            @foreach ($facts as $label => $value)
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.1em] text-brand-mist">{{ $label }}</dt>
                                    <dd class="mt-0.5 font-mono text-brand-ink">{{ $value !== '' ? $value : '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            @endforeach
        </div>
        @endif {{-- /traffic --}}
    @endif

    @include('livewire.partials.confirm-action-modal')
</x-server-workspace-layout>
