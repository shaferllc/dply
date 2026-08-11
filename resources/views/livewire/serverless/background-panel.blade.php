@php
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
@endphp

{{-- Embedded strip inside the Overview card — no card of its own. --}}
<div class="border-t border-brand-ink/10">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-clock"
        :title="__('Scheduler & queue')"
        :count="$enabled ? __('on') : __('off')"
        :note="__('A function has no long-running process. dply runs the Laravel scheduler on a one-minute cron, and drains queued jobs with concurrent invocations as work arrives.')"
    />

    <div class="space-y-3 px-3 py-3 sm:px-4">
        @unless ($deployed)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                {{ __('Deploy the function first — background work is skipped until it has an invocation URL.') }}
            </div>
        @endunless

        {{-- Both failing backends are silent: `sync` never enqueues, and
             SQLite loses jobs between containers. The pump refuses to drain
             either, so this explains the refusal rather than letting the
             panel look healthy while nothing happens. --}}
        @if ($enabled && $queueBlocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
                <p class="font-semibold">
                    {{ __('Queued jobs will not run — dply is not draining this function.') }}
                </p>
                <p class="mt-0.5">{{ $queueReason }}</p>
                <p class="mt-0.5">
                    {{ __('A function drains its queue with several concurrent invocations, so the queue has to live somewhere all of them can reach.') }}
                </p>

                {{-- dply Queue leads: it is created on the spot, where Redis
                     needs a cluster the operator has to already be paying
                     for. Redis stays offered when one is online — an org
                     without dply Queue still needs the old way out. --}}
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @if ($canUseDply)
                        <button type="button" wire:click="useDplyQueue" wire:loading.attr="disabled" wire:target="useDplyQueue"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-amber-900 px-2.5 py-1 text-2xs font-semibold text-amber-50 shadow-sm hover:bg-amber-950 disabled:opacity-60">
                            <x-heroicon-m-bolt class="h-3.5 w-3.5" aria-hidden="true" />
                            <span wire:loading.remove wire:target="useDplyQueue">{{ __('Use dply Queue') }}</span>
                            <span wire:loading wire:target="useDplyQueue">{{ __('Creating…') }}</span>
                        </button>
                    @endif

                    @if ($canUseRedis)
                        <button type="button" wire:click="useProvisionedRedis" wire:loading.attr="disabled" wire:target="useProvisionedRedis"
                                @class([
                                    'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-2xs font-semibold shadow-sm disabled:opacity-60',
                                    'bg-amber-900 text-amber-50 hover:bg-amber-950' => ! $canUseDply,
                                    'bg-amber-100 text-amber-900 ring-1 ring-inset ring-amber-300 hover:bg-amber-200' => $canUseDply,
                                ])>
                            <x-heroicon-m-bolt class="h-3.5 w-3.5" aria-hidden="true" />
                            <span wire:loading.remove wire:target="useProvisionedRedis">{{ __('Use the provisioned Redis') }}</span>
                            <span wire:loading wire:target="useProvisionedRedis">{{ __('Wiring…') }}</span>
                        </button>
                    @endif

                    @unless ($canUseDply || $canUseRedis)
                        <span class="text-2xs">
                            {{ __('Provision a Redis cache for this function on the Resources tab, and dply will point the queue at it automatically.') }}
                        </span>
                    @endunless
                </div>

                @if ($canUseDply)
                    <p class="mt-1.5 text-2xs">
                        {{ __('dply Queue is a managed queue dply hosts for this function — nothing to provision, and it works on the next deploy.') }}
                    </p>
                @endif
            </div>
        @elseif ($enabled && $queueState === 'unknown')
            {{-- Not blocked: an unrecognised driver backed by a real service
                 is perfectly valid, and refusing it would be worse than the
                 risk. Say so rather than pretending to have checked. --}}
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
                {{ $queueReason }}
            </div>
        @endif

        {{-- The managed queue itself. Shown whenever a namespace exists, even
             if the connection has since been pointed elsewhere by hand — the
             jobs in it are real either way, and silently hiding them is how
             an operator loses a backlog. --}}
        @if ($managedQueue)
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('dply Queue') }}</p>
                        <span @class([
                            'inline-flex items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em]',
                            'bg-brand-sand/60 text-brand-moss' => $managedQueue['status'] === 'active',
                            'bg-amber-100 text-amber-900' => $managedQueue['status'] !== 'active',
                        ])>{{ $managedQueue['status'] }}</span>
                    </div>

                    @if ($managedQueue['depth'])
                        <p class="text-2xs text-brand-mist">
                            {{ __(':pending pending · :delayed delayed · :reserved in flight', [
                                'pending' => number_format($managedQueue['depth']['pending']),
                                'delayed' => number_format($managedQueue['depth']['delayed']),
                                'reserved' => number_format($managedQueue['depth']['reserved']),
                            ]) }}
                        </p>
                    @else
                        <p class="text-2xs text-brand-mist">{{ __('Depth unavailable right now.') }}</p>
                    @endif
                </div>

                <p class="mt-1 truncate font-mono text-2xs text-brand-moss" title="{{ $managedQueue['endpoint'] }}">
                    {{ $managedQueue['endpoint'] }}
                </p>

                @unless ($usingManagedQueue)
                    <p class="mt-1.5 text-2xs text-amber-900">
                        {{ __('QUEUE_CONNECTION is set to :connection, not `dply`, so this queue is not being used. Anything still in it will not drain.', ['connection' => $queueConnection ?: __('unset')]) }}
                    </p>
                @endunless
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Background processing') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">
                    {{ __('Runs schedule:run every minute and drains queued jobs. Set QUEUE_CONNECTION to database or redis in the Environment panel.') }}
                </p>
            </div>
            <button type="button" wire:click="toggle" wire:loading.attr="disabled" class="{{ $btnOutline }} shrink-0">
                {{ $enabled ? __('Disable') : __('Enable') }}
            </button>
        </div>

        {{-- Concurrency — the throughput dial. Only meaningful while queue
             processing is on, so it stays hidden until then. --}}
        @if ($queueEnabled)
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <label for="max_concurrency" class="block text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">
                            {{ __('Queue concurrency') }}
                        </label>
                        <p class="mt-0.5 max-w-xl text-xs text-brand-moss">
                            {{ __('How many queue drains may run against this function at once. Higher clears a backlog faster; each concurrent drain is a billed invocation. dply scales up to this only while there is a backlog, and back to zero when the queue empties.') }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <input
                            id="max_concurrency"
                            type="number"
                            min="1"
                            max="{{ $maxConcurrencyCeiling }}"
                            step="1"
                            wire:model="max_concurrency"
                            class="w-20 rounded-lg border-brand-ink/15 py-1 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        />
                        <button type="button" wire:click="saveConcurrency" wire:loading.attr="disabled" wire:target="saveConcurrency" class="{{ $btnOutline }}">
                            <span wire:loading.remove wire:target="saveConcurrency">{{ __('Save') }}</span>
                            <span wire:loading wire:target="saveConcurrency">{{ __('Saving…') }}</span>
                        </button>
                    </div>
                </div>

                @error('max_concurrency')
                    <p class="mt-1 text-2xs font-medium text-rose-700">{{ $message }}</p>
                @enderror

                <p class="mt-1.5 text-2xs text-brand-mist">
                    {{ trans_choice(
                        '{0}No drains running right now.|{1}:count drain running right now.|[2,*]:count drains running right now.',
                        $activeSlots,
                        ['count' => $activeSlots],
                    ) }}
                    {{ __('Ceiling :max.', ['max' => $maxConcurrencyCeiling]) }}
                </p>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Keep warm') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">
                    {{ __('Ping the function every minute so requests rarely hit a cold start. Unnecessary while background processing is on — that already keeps it warm.') }}
                </p>
            </div>
            <button type="button" wire:click="toggleKeepWarm" wire:loading.attr="disabled" class="{{ $btnOutline }} shrink-0">
                {{ $keepWarm ? __('Disable') : __('Enable') }}
            </button>
        </div>
    </div>

    {{-- Failed jobs. A function has no CLI, so `queue:failed` can never be
         run against it — these are mirrored out of the function by the
         handler as they fail, and re-queued from here. --}}
    <x-workspace-panel-head
        dense
        class="border-y border-brand-ink/10"
        icon="heroicon-o-exclamation-triangle"
        :tone="$this->failedJobCount > 0 ? 'danger' : null"
        :title="__('Failed jobs')"
        :count="$this->failedJobCount > 0 ? $this->failedJobCount : null"
        :note="__('Reported by the function as they fail. Retrying re-queues the job in the app and starts a drain immediately.')"
    >
        @if ($this->failedJobCount > 0)
            <x-slot:actions>
                <button type="button" wire:click="retryFailedJobs" wire:loading.attr="disabled" wire:target="retryFailedJobs" class="{{ $btnOutline }}">
                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" wire:loading.class="animate-spin" wire:target="retryFailedJobs" aria-hidden="true" />
                    {{ __('Retry all') }}
                </button>
                <button type="button" wire:click="clearFailedJobs" wire:loading.attr="disabled" class="{{ $btnOutline }} text-rose-700 ring-rose-200 hover:bg-rose-50">
                    <x-heroicon-m-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Clear') }}
                </button>
            </x-slot:actions>
        @endif
    </x-workspace-panel-head>

    @if ($this->failedJobs->isEmpty())
        <div class="px-3 py-4 text-center text-xs text-brand-moss sm:px-4">
            {{ __('No failed jobs recorded.') }}
        </div>
    @else
        <ol class="divide-y divide-brand-ink/10">
            @foreach ($this->failedJobs as $job)
                <li wire:key="failed-{{ $job->id }}" class="group px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4">
                    <div class="flex items-start gap-2.5">
                        <span @class([
                            'mt-1 flex h-2 w-2 shrink-0 rounded-full ring-4',
                            'bg-brand-mist ring-brand-sand/50' => $job->wasRetried(),
                            'bg-rose-500 ring-rose-100' => ! $job->wasRetried(),
                        ])></span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="truncate font-mono text-xs font-semibold text-brand-ink" title="{{ $job->job_class }}">{{ $job->shortJobClass() }}</span>
                                @if ($job->queue)
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/50 px-1.5 py-0.5 text-2xs font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $job->queue }}</span>
                                @endif
                                @if ($job->wasRetried())
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('re-queued') }}</span>
                                @endif
                                @if ($job->failed_at)
                                    <span class="ml-auto whitespace-nowrap text-2xs text-brand-mist" title="{{ $job->failed_at->toIso8601String() }}">{{ $job->failed_at->diffForHumans() }}</span>
                                @endif
                            </div>

                            <p class="mt-0.5 truncate font-mono text-2xs text-rose-700" title="{{ \Illuminate\Support\Str::limit((string) $job->exception_excerpt, 1500) }}">{{ $job->headline() }}</p>

                            <div class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                                @if ($job->uuid && ! $job->wasRetried())
                                    <button type="button" wire:click="retryFailedJobs('{{ $job->uuid }}')" wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-1 text-2xs font-semibold text-brand-forest hover:text-brand-sage">
                                        <x-heroicon-m-arrow-path class="h-3 w-3" aria-hidden="true" />
                                        {{ __('Retry') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="dismissFailedJob('{{ $job->id }}')" wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-1 text-2xs font-semibold text-brand-mist hover:text-rose-700">
                                    <x-heroicon-m-x-mark class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Dismiss') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($this->failedJobCount > $this->failedJobs->count())
            <div class="border-t border-brand-ink/10 px-3 py-2 text-2xs text-brand-mist sm:px-4">
                {{ __('Showing the :shown most recent of :total.', ['shown' => $this->failedJobs->count(), 'total' => $this->failedJobCount]) }}
            </div>
        @endif
    @endif
</div>
