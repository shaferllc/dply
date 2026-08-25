@php
    use App\Modules\Queue\Models\QueueNamespace;

    $statusTone = [
        QueueNamespace::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        QueueNamespace::STATUS_PAUSED => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        QueueNamespace::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);
    $peak = max(1, collect($throughput)->max('jobs') ?: 1);

    // Depth against the tier's push-rejection threshold — the same verdict the
    // index dial carries, scoped to this one queue.
    $total = $depth?->total() ?? 0;
    $capacity = $tier->maxQueueDepth;
    $fillPercent = $capacity > 0 ? min(100, (int) round($total / $capacity * 100)) : 0;

    $fillTone = match (true) {
        $namespace->status === QueueNamespace::STATUS_FAILED => 'text-brand-rust',
        ! $namespace->isActive() => 'text-brand-mist',
        $fillPercent >= 90 => 'text-brand-rust',
        $fillPercent >= 70 => 'text-brand-gold',
        default => 'text-brand-sage',
    };

    // A real backlog can still round to 0% of a 100k cap, and an empty arc
    // reads as "failed to load" rather than "barely used".
    $dialPercent = $total > 0 ? max(2, $fillPercent) : 0;

    $windowJobs = collect($throughput)->sum('jobs');
    $jobsToday = (int) (collect($throughput)->last()['jobs'] ?? 0);
@endphp

<div class="contents">
    <x-workspace-nav />

    {{-- Scoped motion, matching the queues index: the dial draws itself and the
         activity bars rise on first paint. --}}
    @verbatim
        <style>
            @keyframes dply-bar-rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
            /* --dial-full is the full circumference, set inline on the arc. */
            @keyframes dply-dial-draw { from { stroke-dashoffset: var(--dial-full); } }
            @keyframes dply-meter-fill { from { width: 0; } to { width: var(--meter-w); } }
            .dply-bar { transform-origin: bottom; animation: dply-bar-rise .55s cubic-bezier(.16,1,.3,1) both; }
            .dply-dial { animation: dply-dial-draw 1s cubic-bezier(.16,1,.3,1) both; }
            .dply-meter { width: var(--meter-w); animation: dply-meter-fill .7s cubic-bezier(.16,1,.3,1) both; }
            @media (prefers-reduced-motion: reduce) {
                .dply-bar, .dply-dial, .dply-meter { animation: none; }
            }
        </style>
    @endverbatim

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="$breadcrumbs" />

        <x-profile-shell
            dense
            :title="$namespace->name"
            :description="$endpoint !== '' ? $endpoint : __('No public endpoint is configured for dply Queue.')"
            icon="heroicon-o-queue-list"
        >
            <x-slot:actions>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$namespace->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                    {{ ucfirst($namespace->status) }}
                </span>
                @if ($canManage)
                    <button type="button" wire:click="startTierChange" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        {{ __('Change tier') }}
                    </button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                {{-- The queue console, matching the index band: depth against this
                     queue's own push-rejection threshold reads first, because
                     hitting it means pushes start being refused. --}}
                <div class="relative overflow-hidden bg-brand-ink text-brand-cream">
                    <div class="pointer-events-none absolute -right-20 -top-28 h-72 w-72 rounded-full bg-brand-sage/25 blur-3xl" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute -left-24 -bottom-24 h-64 w-64 rounded-full bg-brand-gold/15 blur-3xl" aria-hidden="true"></div>

                    <div class="relative grid gap-6 px-4 py-5 sm:px-6 sm:py-6 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,24rem)] lg:items-center lg:gap-8">
                        <div class="flex items-center gap-4 sm:gap-5">
                            <div class="relative shrink-0">
                                <svg viewBox="0 0 120 120" class="h-24 w-24 -rotate-90 sm:h-28 sm:w-28" aria-hidden="true">
                                    <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" class="text-brand-cream/12" />
                                    <circle
                                        cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
                                        class="dply-dial {{ $fillTone }}"
                                        stroke-dasharray="326.726"
                                        stroke-dashoffset="{{ 326.726 * (1 - $dialPercent / 100) }}"
                                        style="--dial-full: 326.726"
                                    />
                                </svg>
                                <div class="absolute inset-0 flex flex-col items-center justify-center">
                                    <span class="font-mono text-2xl font-semibold tabular-nums leading-none text-brand-cream sm:text-[28px]">{{ $depth === null ? '—' : number_format($total) }}</span>
                                    <span class="mt-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-cream/45">{{ __('queued') }}</span>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Depth') }}</p>
                                <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                    @if ($depth === null)
                                        {{ __('The job store could not be reached.') }}
                                    @elseif (! $namespace->isActive())
                                        {{ __('Paused — pushes are refused.') }}
                                    @elseif ($fillPercent >= 90)
                                        {{ __('Nearly full — pushes are about to fail.') }}
                                    @elseif ($total === 0)
                                        {{ __('Drained.') }}
                                    @else
                                        {{ __('Draining normally.') }}
                                    @endif
                                </p>
                                <p class="mt-1 text-xs text-brand-cream/55">
                                    @if ($capacity > 0)
                                        {{ __(':pct% of the :cap job limit on :tier', ['pct' => $fillPercent, 'cap' => number_format($capacity), 'tier' => $tier->label]) }}
                                    @else
                                        {{ __('This tier does not cap queue depth.') }}
                                    @endif
                                </p>
                                @if ($failedJobs !== [])
                                    <p class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-brand-rust/15 px-2 py-0.5 text-2xs font-semibold text-brand-rust ring-1 ring-inset ring-brand-rust/25">
                                        <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ trans_choice(':count failed job|:count failed jobs', count($failedJobs), ['count' => count($failedJobs)]) }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- Pending / delayed / in flight are three different
                             situations — work waiting, work scheduled, and work a
                             worker is holding — and one number hides which. --}}
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3">
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Pending') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ $depth === null ? '—' : number_format($depth->pending) }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('claimable now') }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Delayed') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ $depth === null ? '—' : number_format($depth->delayed) }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('not yet due') }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('In flight') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ $depth === null ? '—' : number_format($depth->reserved) }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('held by a worker') }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Rate limit') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($tier->requestsPerMinute) }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('requests / min') }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Monthly') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">
                                    @if (! $billable)
                                        <span class="text-base text-brand-sage">{{ __('Included') }}</span>
                                    @elseif (! $billingEnabled)
                                        <span class="text-base text-brand-cream/70">{{ __('Free') }}</span>
                                    @else
                                        {{ $money($tier->priceCents) }}
                                    @endif
                                </dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ $billable && ! $billingEnabled ? __('during beta') : $tier->label }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Pushed today') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($jobsToday) }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __(':n in 30 days', ['n' => number_format($windowJobs)]) }}</dd>
                            </div>
                        </dl>

                        {{-- 30 days of pushes, from the daily usage rollup. --}}
                        <div class="min-w-0">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Jobs pushed') }}</p>
                                <span class="font-mono text-2xs text-brand-cream/40">{{ __('last 30 days') }}</span>
                            </div>
                            @if ($windowJobs === 0)
                                <p class="mt-3 text-xs text-brand-cream/45">{{ __('Nothing pushed yet — the chart fills in as your app enqueues work.') }}</p>
                            @else
                                <div class="mt-3 flex h-16 items-end gap-px" role="img" aria-label="{{ __('Jobs pushed per day over the last 30 days') }}">
                                    @foreach ($throughput as $index => $point)
                                        <div
                                            class="dply-bar flex-1 rounded-sm {{ $point['jobs'] > 0 ? 'bg-brand-sage' : 'bg-brand-cream/10' }}"
                                            style="height: {{ $point['jobs'] > 0 ? max(6, (int) round($point['jobs'] / $peak * 100)) : 4 }}%; animation-delay: {{ $index * 18 }}ms"
                                            title="{{ $point['date'] }}: {{ number_format($point['jobs']) }} {{ __('jobs') }}"
                                        ></div>
                                    @endforeach
                                </div>
                                <p class="mt-1.5 flex items-baseline justify-between text-2xs text-brand-cream/40">
                                    <span>{{ \Illuminate\Support\Carbon::parse($throughput[0]['date'])->isoFormat('D MMM') }}</span>
                                    <span>{{ __('peak :n/day', ['n' => number_format($peak)]) }}</span>
                                    <span>{{ __('today') }}</span>
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </x-slot:stats>

            <x-slot:tabs>
                <nav class="flex gap-1 px-3 sm:px-4" aria-label="{{ __('Queue sections') }}">
                    @foreach ([
                        'overview' => __('Overview'),
                        'workers' => __('Workers'),
                        'credentials' => __('Credentials'),
                        'failed' => __('Failed jobs'),
                    ] as $key => $label)
                        <button
                            type="button"
                            wire:click="$set('tab', '{{ $key }}')"
                            @class([
                                'border-b-2 px-3 py-2 text-sm font-medium transition',
                                'border-brand-ink text-brand-ink' => $tab === $key,
                                'border-transparent text-brand-moss hover:text-brand-ink' => $tab !== $key,
                            ])
                        >
                            {{ $label }}
                            @if ($key === 'failed' && $failedJobs !== [])
                                <span class="ml-1 rounded-full bg-red-100 px-1.5 text-xs font-semibold text-red-700">{{ count($failedJobs) }}</span>
                            @endif
                        </button>
                    @endforeach
                </nav>
            </x-slot:tabs>

            <div class="px-3 py-4 sm:px-4">
                {{-- Above the tabs, not inside Credentials: creating a queue
                     redirects here on the Overview tab, and a secret shown once
                     that is hiding behind a tab is a secret lost. --}}
                @if ($revealedSecret !== null)
                    <x-alert tone="success" class="mb-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold">{{ __('New secret — shown once, copy it now.') }}</p>
                                <p class="mt-1 text-xs">{{ __('dply stores this encrypted because SigV4 must recompute the signature, but it is never displayed again.') }}</p>
                            </div>
                            {{-- Until this is dismissed the plaintext rides in the
                                 Livewire snapshot on every subsequent request. --}}
                            <button type="button" wire:click="dismissSecret" class="shrink-0 text-xs font-semibold underline">
                                {{ __('I’ve stored it') }}
                            </button>
                        </div>
                        <code class="mt-2 block break-all rounded bg-white/60 p-2 font-mono text-xs">{{ $revealedSecret }}</code>
                    </x-alert>
                @endif

                @if (! $billable)
                    <x-alert tone="success" class="mb-4">
                        {{ __('This queue is included at no charge.') }}
                    </x-alert>
                @endif

                {{-- ============ OVERVIEW ============ --}}
                @if ($tab === 'overview')
                    <div class="space-y-5">
                        {{-- The throughput chart lives in the console band above;
                             a second copy here would be the same data twice. --}}
                        <div>
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Connect your app') }}</h3>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ __('Laravel speaks the SQS protocol out of the box, so there is no package to install. Add these to your .env:') }}
                            </p>
                            {{-- A real code block, not <x-cli-snippet>: that
                                 component only renders its `command`/`commands`
                                 props and silently drops slot content, so this
                                 block was showing as an empty "CLI commands"
                                 disclosure — the setup env was invisible. --}}
                            @php
                                $envBlock = "QUEUE_CONNECTION=dply\n"
                                    ."DPLY_QUEUE_URL={$endpoint}\n"
                                    .'DPLY_QUEUE_KEY='.($liveCredential?->accessKeyId() ?? 'your-access-key-id')."\n"
                                    .'DPLY_QUEUE_SECRET='.__('(shown once when minted)');
                            @endphp
                            <div
                                class="mt-2 overflow-hidden rounded-xl bg-brand-ink ring-1 ring-inset ring-brand-ink/20"
                                x-data="{ copied: false, async copyVal() { try { await navigator.clipboard.writeText(@js($envBlock)); this.copied = true; setTimeout(() => this.copied = false, 1400); } catch (e) {} } }"
                            >
                                <div class="flex items-center justify-between gap-2 border-b border-brand-cream/10 px-3 py-2">
                                    <span class="font-mono text-2xs uppercase tracking-[0.14em] text-brand-cream/40">env</span>
                                    <button type="button" x-on:click="copyVal()" class="inline-flex items-center gap-1 text-2xs font-semibold text-brand-cream/50 hover:text-brand-cream">
                                        <span x-show="! copied">{{ __('Copy') }}</span>
                                        <span x-show="copied" x-cloak class="text-brand-sage">{{ __('Copied') }}</span>
                                    </button>
                                </div>
                                <pre class="overflow-x-auto px-3 py-3 font-mono text-2xs leading-relaxed text-brand-cream/85"><code>{{ $envBlock }}</code></pre>
                            </div>

                            @if ($namespace->site_id === null)
                                {{-- An externally-hosted app has no dply-injected handler to
                                     register the connection for it, so it has to be said. --}}
                                <p class="mt-2 text-xs text-brand-moss">
                                    {{ __('Apps dply deploys get this wiring automatically. For an app hosted elsewhere, also add a `dply` connection to config/queue.php using the `sqs` driver with `\'endpoint\' => env(\'DPLY_QUEUE_URL\')` — the stock `sqs` block has no endpoint key and would route to real AWS.') }}
                                    <a href="{{ route('docs.markdown', 'queue') }}" class="font-medium text-brand-forest hover:underline">{{ __('Full setup guide') }}</a>
                                </p>
                            @endif

                            {{-- The two limits an operator would otherwise discover
                                 in production. Both are consequences of the design
                                 rather than bugs, so they belong next to the setup
                                 instructions, not in a changelog somewhere. --}}
                            <dl class="mt-4 space-y-2 rounded-lg bg-brand-sand/30 px-3 py-2.5 text-xs leading-relaxed text-brand-moss">
                                <div>
                                    <dt class="font-semibold text-brand-ink">{{ __('Delivery is not strictly FIFO') }}</dt>
                                    <dd class="mt-0.5">
                                        {{ __('Jobs are claimed in visibility order with SKIP LOCKED, so concurrent workers can finish out of order, and a released or retried job rejoins by its new visibility time. Order-sensitive work needs a chain or a batch, exactly as it would on SQS.') }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-brand-ink">{{ __('Horizon does not work with this connection') }}</dt>
                                    <dd class="mt-0.5">
                                        {{ __('Horizon is hard-wired to Laravel’s Redis queue and cannot read an SQS-protocol connection. The Failed jobs tab here is the replacement — that is why it exists.') }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        {{-- Drain throughput. The stock SQS driver asks for one
                             message per request and never long-polls, so a drain
                             costs two round trips per job and is capped by the
                             tier rate long before Postgres is troubled. This is
                             the opt-in that fixes the read half. --}}
                        @php
                            $drainSnippet = <<<'PHP'
                            // app/Queue/DplyQueue.php
                            use Illuminate\Queue\SqsQueue;
                            use Illuminate\Queue\Jobs\SqsJob;

                            class DplyQueue extends SqsQueue
                            {
                                /** Messages fetched but not yet handed to the worker. */
                                protected array $buffer = [];

                                public function pop($queue = null)
                                {
                                    $queue = $this->getQueue($queue);

                                    if ($this->buffer === []) {
                                        // Ten per request instead of one, and wait for work
                                        // instead of returning empty and sleeping.
                                        $response = $this->sqs->receiveMessage([
                                            'QueueUrl' => $queue,
                                            'AttributeNames' => ['ApproximateReceiveCount'],
                                            'MaxNumberOfMessages' => 10,
                                            'WaitTimeSeconds' => 5,
                                        ]);

                                        $this->buffer = $response['Messages'] ?? [];
                                    }

                                    if ($this->buffer === []) {
                                        return null;
                                    }

                                    return new SqsJob(
                                        $this->container,
                                        $this->sqs,
                                        array_shift($this->buffer),
                                        $this->connectionName,
                                        $queue,
                                    );
                                }
                            }
                            PHP;

                            $drainConnector = <<<'PHP'
                            // app/Providers/AppServiceProvider.php — in boot()
                            Queue::extend('dply', function () {
                                return new class extends SqsConnector {
                                    public function connect(array $config)
                                    {
                                        $config = $this->getDefaultConfiguration($config);

                                        if (! empty($config['key']) && ! empty($config['secret'])) {
                                            $config['credentials'] = Arr::only($config, ['key', 'secret', 'token']);
                                        }

                                        return new DplyQueue(
                                            new SqsClient($config),
                                            $config['queue'],
                                            $config['prefix'] ?? '',
                                            $config['suffix'] ?? '',
                                            $config['after_commit'] ?? null,
                                        );
                                    }
                                };
                            });
                            PHP;
                        @endphp

                        <div class="border-t border-brand-ink/10 pt-4" x-data="{ open: false }">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ __('Drain faster') }}
                                    </h3>
                                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">
                                        {{ __('Laravel’s stock SQS driver fetches one job per request and does not long-poll, so every job costs two round trips. Swapping in a batching queue class fetches ten at a time and waits for work — roughly a 10× cut in receive requests, with no change to your jobs.') }}
                                    </p>
                                </div>
                                <x-secondary-button type="button" x-on:click="open = ! open" class="text-xs">
                                    <span x-text="open ? @js(__('Hide')) : @js(__('Show the code'))"></span>
                                </x-secondary-button>
                            </div>

                            <div x-show="open" x-cloak class="mt-3 space-y-3">
                                @foreach ([
                                    ['label' => __('1. The queue class'), 'code' => $drainSnippet],
                                    ['label' => __('2. Register it as the `dply` driver'), 'code' => $drainConnector],
                                ] as $drainStep)
                                    <div>
                                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ $drainStep['label'] }}</p>
                                        <div
                                            class="mt-1 overflow-hidden rounded-xl bg-brand-ink ring-1 ring-inset ring-brand-ink/20"
                                            x-data="{ copied: false, async copyVal() { try { await navigator.clipboard.writeText(@js($drainStep['code'])); this.copied = true; setTimeout(() => this.copied = false, 1400); } catch (e) {} } }"
                                        >
                                            <div class="flex items-center justify-between gap-2 border-b border-brand-cream/10 px-3 py-2">
                                                <span class="font-mono text-2xs uppercase tracking-[0.14em] text-brand-cream/40">php</span>
                                                <button type="button" x-on:click="copyVal()" class="inline-flex items-center gap-1 text-2xs font-semibold text-brand-cream/50 hover:text-brand-cream">
                                                    <span x-show="! copied">{{ __('Copy') }}</span>
                                                    <span x-show="copied" x-cloak class="text-brand-sage">{{ __('Copied') }}</span>
                                                </button>
                                            </div>
                                            <pre class="overflow-x-auto px-3 py-3 font-mono text-2xs leading-relaxed text-brand-cream/85"><code>{{ $drainStep['code'] }}</code></pre>
                                        </div>
                                    </div>
                                @endforeach

                                {{-- Deletes are deliberately left per-job. Holding
                                     acks back to batch them risks the visibility
                                     timeout expiring mid-buffer and the job being
                                     redelivered — a correctness cost that is not
                                     worth the second half of the saving. --}}
                                <p class="rounded-lg bg-brand-sand/30 px-3 py-2 text-xs leading-relaxed text-brand-moss">
                                    {{ __('Deletes stay one-per-job on purpose: holding acks back to batch them risks a job’s visibility timeout expiring while it waits in the buffer, which redelivers work you already did. dply Queue does accept DeleteMessageBatch if you want to batch them yourself with a short flush window.') }}
                                </p>
                                <p class="text-xs leading-relaxed text-brand-moss">
                                    {{ __('With this in place a drain on :tier is bounded by the delete rate — about :rate jobs/second — rather than by the receive loop.', [
                                        'tier' => $tier->label,
                                        'rate' => number_format($tier->requestsPerMinute / 60, 0),
                                    ]) }}
                                </p>
                            </div>
                        </div>

                        <div class="border-t border-brand-ink/10 pt-4">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Capacity') }}</h3>
                            <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                <x-fact-row :label="__('Tier')">{{ $tier->label }}</x-fact-row>
                                <x-fact-row :label="__('Max depth')">{{ number_format($tier->maxQueueDepth) }} {{ __('jobs') }}</x-fact-row>
                                <x-fact-row :label="__('Rate limit')">{{ number_format($tier->requestsPerMinute) }} {{ __('req/min') }}</x-fact-row>
                                <x-fact-row :label="__('Attached site')">{{ $namespace->site?->name ?? __('None (external app)') }}</x-fact-row>
                            </dl>
                        </div>

                        @if ($canManage)
                            <div class="border-t border-brand-ink/10 pt-4">
                                <x-danger-button wire:click="confirmDelete">{{ __('Delete queue') }}</x-danger-button>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ============ WORKERS ============ --}}
                {{-- Its own Livewire component: the worker readout polls every
                     few seconds, and polling this page would re-run the depth
                     query and the failed-job reader at the same cadence. --}}
                @if ($tab === 'workers')
                    <div class="-mx-3 -mb-3 sm:-mx-4 sm:-mb-4">
                        @livewire('queue-fleet-panel', ['queueNamespace' => $this->namespace], key('fleet-panel-'.$this->namespace->id))
                    </div>
                @endif

                {{-- ============ CREDENTIALS ============ --}}
                @if ($tab === 'credentials')
                    <div class="space-y-4">
                        <p class="text-xs text-brand-moss">
                            {{ __('Two credentials can be live at once, because a .env only reaches your app on its next deploy — mint the new one, deploy, then revoke the old.') }}
                        </p>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                <thead>
                                    <tr class="text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">
                                        <th scope="col" class="py-2 pr-3">{{ __('Access key') }}</th>
                                        <th scope="col" class="px-3 py-2">{{ __('Name') }}</th>
                                        <th scope="col" class="px-3 py-2">{{ __('Last used') }}</th>
                                        <th scope="col" class="px-3 py-2">{{ __('Status') }}</th>
                                        <th scope="col" class="py-2 pl-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @php
                                        // The last live credential cannot be revoked — doing so
                                        // is an outage, not a rotation — so the control is
                                        // disabled rather than left to fail on click.
                                        $liveCount = $credentials->filter(
                                            fn ($c): bool => ! $c->isRevoked() && ! $c->isExpired()
                                        )->count();
                                    @endphp
                                    @foreach ($credentials as $credential)
                                        @php $isLive = ! $credential->isRevoked() && ! $credential->isExpired(); @endphp
                                        <tr wire:key="cred-{{ $credential->id }}">
                                            <td class="py-2.5 pr-3 font-mono text-xs text-brand-ink">{{ $credential->accessKeyId() }}</td>
                                            <td class="px-3 py-2.5 text-brand-moss">{{ $credential->name }}</td>
                                            <td class="px-3 py-2.5 text-brand-moss">
                                                {{ $credential->last_used_at?->diffForHumans() ?? __('Never') }}
                                            </td>
                                            <td class="px-3 py-2.5">
                                                @if ($credential->isRevoked())
                                                    <span class="text-xs text-brand-mist">{{ __('Revoked') }}</span>
                                                @elseif ($credential->isExpired())
                                                    <span class="text-xs text-amber-700">{{ __('Expired') }}</span>
                                                @else
                                                    <span class="text-xs font-medium text-brand-forest">{{ __('Live') }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2.5 pl-3 text-right">
                                                @if ($canManageCredentials && $isLive)
                                                    <button
                                                        type="button"
                                                        wire:click="confirmRevoke('{{ $credential->id }}')"
                                                        class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 hover:text-red-700"
                                                    >
                                                        <x-heroicon-o-no-symbol class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                        {{ __('Revoke') }}
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($canManageCredentials)
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="min-w-0 flex-1 sm:max-w-xs">
                                    <label for="credential-name" class="block text-xs font-medium text-brand-moss">{{ __('Name (optional)') }}</label>
                                    <input
                                        id="credential-name"
                                        type="text"
                                        wire:model="credentialName"
                                        class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        placeholder="{{ __('e.g. rotation-2026-08') }}"
                                    />
                                </div>
                                <button
                                    type="button"
                                    wire:click="mintCredential"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest"
                                >
                                    <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Mint new credential') }}
                                </button>
                                <p class="w-full text-xs text-brand-mist sm:w-auto">
                                    {{ __('Two live at most — a third would mean an earlier rotation was abandoned.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ============ FAILED JOBS ============ --}}
                @if ($tab === 'failed')
                    <div class="space-y-4">
                        @if (! $failedJobsAvailable)
                            <x-alert tone="warning">
                                {{ __('The job store could not be reached, so failed jobs cannot be listed right now.') }}
                            </x-alert>
                        @elseif ($failedJobs === [])
                            <x-empty-state
                                icon="heroicon-o-exclamation-triangle"
                                :title="__('No failed jobs recorded')"
                                :description="$ownsFailedJobs
                                    ? __('Nothing has failed on this queue.')
                                    : __('Laravel writes failed jobs to your own application database by default, so this list stays empty until your app is pointed at dply\'s failed-job store. This is not the same as having had no failures.')"
                            />
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                    <thead>
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">
                                            <th scope="col" class="py-2 pr-3">{{ __('Job') }}</th>
                                            <th scope="col" class="px-3 py-2">{{ __('Queue') }}</th>
                                            <th scope="col" class="px-3 py-2">{{ __('Failed') }}</th>
                                            <th scope="col" class="px-3 py-2">{{ __('Error') }}</th>
                                            <th scope="col" class="py-2 pl-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5">
                                        @foreach ($failedJobs as $job)
                                            <tr class="align-top">
                                                <td class="py-2.5 pr-3">
                                                    <button type="button" wire:click="inspectJob('{{ $job['id'] }}')" class="text-left font-medium text-brand-ink hover:text-brand-forest">
                                                        {{ $job['name'] }}
                                                    </button>
                                                    @if ($job['retried_at'] !== null)
                                                        <span class="ml-1 rounded-full bg-brand-sage/15 px-1.5 text-xs font-medium text-brand-forest">{{ __('Retried') }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2.5 text-brand-moss">{{ $job['queue'] }}</td>
                                                <td class="px-3 py-2.5 text-brand-moss">{{ $job['failed_at']?->diffForHumans() ?? __('—') }}</td>
                                                <td class="max-w-md truncate px-3 py-2.5 font-mono text-xs text-brand-moss" title="{{ $job['exception_summary'] }}">
                                                    {{ $job['exception_summary'] }}
                                                </td>
                                                <td class="py-2.5 pl-3 text-right whitespace-nowrap">
                                                    @if ($canManage && $job['retried_at'] === null)
                                                        <button type="button" wire:click="retryJob('{{ $job['id'] }}')" class="text-xs font-semibold text-brand-forest hover:underline">
                                                            {{ __('Retry') }}
                                                        </button>
                                                    @endif
                                                    @if ($canManage)
                                                        <button type="button" wire:click="forgetJob('{{ $job['id'] }}')" class="ml-2 text-xs font-semibold text-brand-mist hover:text-red-700">
                                                            {{ __('Delete') }}
                                                        </button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </x-profile-shell>
    </div>

    {{-- Tier-change modal --}}
    <x-modal name="queue-tier-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        @php
            $selected = $tiers[$selectedTier] ?? $tier;
            $isUpgrade = $billable && $selected->priceCents > $tier->priceCents;
        @endphp
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('Change capacity tier') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('A tier sets how deep this queue may get and how fast your app may call it.') }}
            </p>

            <div class="mt-4 space-y-2">
                @foreach ($tiers as $slug => $option)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-brand-ink/10 p-3 hover:bg-brand-sand/20">
                        <input type="radio" wire:model.live="selectedTier" value="{{ $slug }}" class="mt-1 border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="text-sm font-semibold text-brand-ink">{{ $option->label }}</span>
                                <span class="text-sm tabular-nums text-brand-ink">{{ $money($option->priceCents) }}/{{ __('mo') }}</span>
                            </span>
                            <span class="mt-0.5 block text-xs text-brand-moss">
                                {{ number_format($option->maxQueueDepth) }} {{ __('jobs deep') }} · {{ number_format($option->requestsPerMinute) }} {{ __('req/min') }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>

            @if ($isUpgrade)
                <label class="mt-3 flex items-start gap-2 rounded-lg bg-brand-sand/30 p-3">
                    <input type="checkbox" wire:model="confirmTierCharge" class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                    <span class="text-xs text-brand-moss">
                        {{ __('I understand this raises the monthly charge to :price.', ['price' => $money($selected->priceCents)]) }}
                    </span>
                </label>
            @endif

            @if ($selected->maxQueueDepth < $tier->maxQueueDepth)
                <x-alert tone="warning" class="mt-3">
                    {{ __('This is a smaller tier. Pushes are rejected once the queue is deeper than :depth jobs — if it is currently deeper than that, drain it before switching.', ['depth' => number_format($selected->maxQueueDepth)]) }}
                </x-alert>
            @endif

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelTierChange" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <button type="button" wire:click="changeTier" class="rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                    {{ __('Change tier') }}
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Failed-job detail modal --}}
    {{-- Revoke confirmation. Effective immediately — the resolver's cache entry
         is evicted by hash, so there is no TTL to wait out. --}}
    <x-modal name="queue-revoke-modal" :show="false" maxWidth="md" overlayClass="bg-brand-ink/30" focusable>
        @php
            $revoking = $revokingId !== null ? $credentials->firstWhere('id', $revokingId) : null;
            $liveAfter = $credentials->filter(fn ($c): bool => $c->isUsable())->count() - 1;
        @endphp
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">
                {{ __('Revoke :key?', ['key' => $revoking?->accessKeyId() ?? __('this credential')]) }}
            </h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Requests signed with it are rejected from the next one onward. Any app still holding this secret starts failing to reach the queue.') }}
            </p>

            @if ($revoking?->last_used_at !== null)
                {{-- The whole reason last_used_at is tracked: it is what tells an
                     operator the redeploy has landed and the old key is safe to
                     cut, instead of asking them to guess. --}}
                <x-alert tone="warning" class="mt-3">
                    {{ __('Still in use — last seen :when.', ['when' => $revoking->last_used_at->diffForHumans()]) }}
                </x-alert>
            @endif

            @if ($liveAfter < 1)
                <x-alert tone="warning" class="mt-3">
                    {{ __('This is the only live credential. Revoking it leaves nothing that can reach this queue until you mint another and redeploy.') }}
                </x-alert>
            @endif

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelRevoke" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <x-danger-button wire:click="revokeCredential">{{ __('Revoke credential') }}</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="queue-failed-job-modal" :show="false" maxWidth="2xl" overlayClass="bg-brand-ink/30" focusable>
        <div class="p-5 sm:p-6">
            @if ($inspectingJob !== null)
                <h2 class="text-base font-semibold text-brand-ink">{{ $inspectingJob['name'] }}</h2>
                <p class="mt-0.5 text-xs text-brand-moss">
                    {{ $inspectingJob['queue'] }} · {{ __('attempt :n', ['n' => $inspectingJob['attempts']]) }} ·
                    {{ $inspectingJob['failed_at']?->diffForHumans() ?? __('unknown time') }}
                </p>

                <pre class="mt-3 max-h-80 overflow-auto rounded-lg bg-brand-ink/5 p-3 font-mono text-xs text-brand-ink">{{ $inspectingJob['exception'] }}</pre>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeJob" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                        {{ __('Close') }}
                    </button>
                    @if ($canManage && $inspectingJob['retried_at'] === null)
                        <button type="button" wire:click="retryJob('{{ $inspectingJob['id'] }}')" class="rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                            {{ __('Retry job') }}
                        </button>
                    @endif
                </div>
            @endif
        </div>
    </x-modal>

    {{-- Delete modal --}}
    <x-modal name="queue-delete-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('Delete :name?', ['name' => $namespace->name]) }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Any jobs still in this queue are discarded, and apps using its credentials will start failing to enqueue. This cannot be undone.') }}
            </p>

            @if ($depth !== null && $depth->total() > 0)
                <x-alert tone="warning" class="mt-3">
                    {{ trans_choice(
                        ':count job is still queued and will be discarded.|:count jobs are still queued and will be discarded.',
                        $depth->total(),
                        ['count' => number_format($depth->total())],
                    ) }}
                </x-alert>
            @endif

            {{-- Typing the name, not just clicking through. This throws away
                 jobs the customer's app believes are still going to run, with
                 no undo and no trace — the friction is the point, and it has to
                 match the queue index or this page is the way around it. --}}
            <div class="mt-4">
                <label for="delete-confirmation" class="block text-xs font-medium text-brand-moss">
                    {{ __('Type :name to confirm', ['name' => $namespace->name]) }}
                </label>
                <input
                    id="delete-confirmation"
                    type="text"
                    wire:model="deleteConfirmation"
                    autocomplete="off"
                    class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    placeholder="{{ $namespace->name }}"
                />
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelDelete" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <x-danger-button wire:click="deleteNamespace">{{ __('Delete queue') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
