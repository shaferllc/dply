{{-- Rendered as a workspace SECTION, so no page wrapper and no breadcrumb —
     the Settings chrome around it owns both, and duplicating them here is what
     made this read as a detached page. --}}
<div class="space-y-4">
    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-queue-list"
            :title="__('Queue')"
            :note="$lastCapturedAt
                ? __('Depth is sampled every five minutes. Last reading :when.', ['when' => $lastCapturedAt->diffForHumans()])
                : __('Depth is sampled every five minutes. No reading yet — the first sweep will fill this in.')"
        >
            <x-slot:actions>
                @can('update', $site)
                    <x-primary-button size="sm" type="button" wire:click="refreshSnapshot" wire:loading.attr="disabled" wire:target="refreshSnapshot">
                        <span wire:loading.remove wire:target="refreshSnapshot">{{ __('Refresh') }}</span>
                        <span wire:loading wire:target="refreshSnapshot">{{ __('Queueing…') }}</span>
                    </x-primary-button>
                @endcan
            </x-slot:actions>
        </x-workspace-panel-head>

        @if ($failedTotal !== null)
            <div class="border-b border-brand-ink/10 px-4 py-3 sm:px-5">
                <p class="text-xs text-brand-moss">
                    {{ trans_choice(':count failed job|:count failed jobs', (int) $failedTotal, ['count' => (int) $failedTotal]) }}
                    <span class="text-brand-mist">· {{ __('across every queue on this site') }}</span>
                </p>
            </div>
        @endif

        @if ($queueSuggestions !== [])
            {{-- Inside the card, above the list: a suggestion is about this
                 queue's state, not a separate concern needing its own panel. --}}
            <div class="border-b border-brand-ink/10 px-4 py-3 sm:px-5">
                <x-site-daemon-suggestions :suggestions="$queueSuggestions" :dismissed-count="0" />
            </div>
        @endif

        @if ($queues->isEmpty())
            <div class="px-4 py-5 text-center sm:px-5">
                {{-- Creation lives on THIS page now, so pointing at Workers was
                     sending people to the one place that no longer does it. --}}
                <p class="text-sm font-medium text-brand-ink">{{ __('No queues yet.') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Add a worker below and its queue appears here after the next sweep.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($queues as $queueName => $data)
                    @php($latest = $data['latest'])
                    <li class="px-4 py-3.5 sm:px-5" wire:key="queue-{{ $queueName }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $queueName }}</p>
                            @if ($latest)
                                <p class="text-xs text-brand-mist">
                                    {{ __('source') }} {{ $latest->source }} ·
                                    {{ trans_choice(':count sample|:count samples', $data['samples'], ['count' => $data['samples']]) }}
                                </p>
                            @endif
                        </div>

                        @if ($latest === null)
                            {{-- Declared by a worker but never sampled: the sweep has not
                                 reached this site yet, or could not read it. Saying so beats
                                 rendering a zero that looks like a healthy empty queue. --}}
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Declared by a worker, not sampled yet.') }}</p>
                        @else
                            <dl class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div>
                                    <dt class="text-2xs uppercase tracking-wide text-brand-mist">{{ __('Pending') }}</dt>
                                    <dd class="text-sm font-semibold text-brand-ink">{{ $latest->pending ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs uppercase tracking-wide text-brand-mist">{{ __('Peak :h h', ['h' => $window_hours]) }}</dt>
                                    <dd class="text-sm font-semibold text-brand-ink">{{ $data['peak_pending'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs uppercase tracking-wide text-brand-mist">{{ __('Oldest wait') }}</dt>
                                    <dd class="text-sm font-semibold text-brand-ink">
                                        {{ $latest->oldest_pending_age_s !== null ? $latest->oldest_pending_age_s.'s' : '—' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-2xs uppercase tracking-wide text-brand-mist">{{ __('Processes') }}</dt>
                                    <dd class="text-sm font-semibold text-brand-ink">{{ $latest->worker_processes ?? '—' }}</dd>
                                </div>
                            </dl>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($pools->isNotEmpty())
        <section class="dply-card min-w-0 overflow-hidden p-0">
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-square-3-stack-3d"
                :title="__('Managed worker servers')"
                :note="__('Pools attached to this site. They run this app and drain the same queues.')"
            />
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($pools as $pool)
                    @php($horizon = is_array($pool->meta['horizon'] ?? null) ? $pool->meta['horizon'] : null)
                    <li class="px-4 py-3.5 sm:px-5" wire:key="pool-{{ $pool->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-brand-ink">{{ $pool->name }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">
                                    {{ trans_choice(':count machine|:count machines', (int) $pool->desired_count, ['count' => (int) $pool->desired_count]) }}
                                    @if ((int) $pool->max_size > 0)
                                        <span class="text-brand-mist">{{ __('of :max max', ['max' => (int) $pool->max_size]) }}</span>
                                    @endif
                                    @if ($horizon && ($horizon['status'] ?? null))
                                        · {{ __('Horizon') }} {{ $horizon['status'] }}
                                    @endif
                                </p>
                            </div>
                            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'worker-fleet']) }}"
                               wire:navigate
                               class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Manage fleet') }}</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-cpu-chip"
            :title="__('Queue workers')"
            :note="__('Supervisor programs on this site that consume jobs. Other daemons live under Workers.')"
        >
            <x-slot:actions>
                @can('update', $site)
                    <x-primary-button size="sm" type="button" wire:click="openCreate">
                        <x-heroicon-o-plus class="h-4 w-4" />
                        {{ __('Add worker') }}
                    </x-primary-button>
                @endcan
            </x-slot:actions>
        </x-workspace-panel-head>

        @if ($showCreate)
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-3.5 sm:px-5">
                {{-- A queue is not a resource you create — it exists the moment
                     something enqueues to the name. What you create is the worker
                     that drains it, so the form asks for exactly that. --}}
                <form wire:submit="createWorker" class="space-y-3">
                    <div>
                        <x-input-label for="new_placement" :value="__('Run on')" />
                        <select id="new_placement" wire:model.live="new_placement" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm sm:max-w-sm">
                            <option value="server">{{ __('This server (Supervisor)') }}</option>
                            @foreach ($pools as $pool)
                                <option value="{{ $pool->id }}">{{ __('Managed worker server — :name', ['name' => $pool->name]) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('new_placement')" class="mt-1" />
                        @if ($pools->isEmpty())
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ __('No managed worker servers attached.') }}
                                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'worker-fleet']) }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Add one') }}</a>
                            </p>
                        @endif
                    </div>

                    @if ($new_placement !== 'server')
                        {{-- A pool already runs this app and drains its queues, so the
                             only meaningful "add a worker" there is another machine.
                             Saying so beats a form whose fields quietly do nothing. --}}
                        <p class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('This adds one more machine to that pool. It already runs this app, so it drains the same queues with the app\'s own worker config — the options below apply to on-box workers only.') }}
                        </p>
                    @else
                        <div class="grid gap-3 sm:grid-cols-4">
                            <div class="sm:col-span-2">
                                <x-input-label for="new_queue" :value="__('Queue name')" />
                                <x-text-input id="new_queue" wire:model="new_queue" class="mt-1 block w-full font-mono text-sm" placeholder="default" />
                                <x-input-error :messages="$errors->get('new_queue')" class="mt-1" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Comma-separated drains several in priority order, e.g. high,default.') }}</p>
                            </div>
                            <div>
                                <x-input-label for="new_connection" :value="__('Connection')" />
                                <x-text-input id="new_connection" wire:model="new_connection" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('default') }}" />
                                <x-input-error :messages="$errors->get('new_connection')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="new_processes" :value="__('Processes')" />
                                <x-text-input id="new_processes" type="number" min="1" max="50" wire:model="new_processes" class="mt-1 block w-full text-sm" />
                                <x-input-error :messages="$errors->get('new_processes')" class="mt-1" />
                            </div>
                        </div>

                        <details class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                            <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Worker options') }}</summary>
                            <div class="mt-3 grid gap-3 sm:grid-cols-4">
                                @foreach ([
                                    ['new_tries', __('Tries'), 1, 100, __('Attempts before a job is marked failed.')],
                                    ['new_timeout', __('Timeout (s)'), 5, 3600, __('Kill a job that runs longer than this.')],
                                    ['new_sleep', __('Sleep (s)'), 0, 300, __('Pause when the queue is empty.')],
                                    ['new_memory', __('Memory (MB)'), 32, 4096, __('Restart above this footprint.')],
                                    ['new_max_time', __('Max time (s)'), 0, 86400, __('Exit and restart, so deploys reach the worker. 0 never exits.')],
                                    ['new_backoff', __('Backoff (s)'), 0, 86400, __('Wait before retrying a failed job.')],
                                    ['new_max_jobs', __('Max jobs'), 1, 1000000, __('Recycle after N jobs.')],
                                    ['new_rest', __('Rest (s)'), 0, 60, __('Pause between jobs.')],
                                ] as [$field, $label, $min, $max, $hint])
                                    <div>
                                        <x-input-label :for="$field" :value="$label" />
                                        <x-text-input :id="$field" type="number" :min="$min" :max="$max" wire:model="{{ $field }}" class="mt-1 block w-full text-sm" />
                                        <p class="mt-1 text-2xs leading-4 text-brand-mist">{{ $hint }}</p>
                                        <x-input-error :messages="$errors->get($field)" class="mt-1" />
                                    </div>
                                @endforeach
                            </div>

                            <label for="new_stop_when_empty" class="mt-3 flex items-start gap-2.5">
                                <input id="new_stop_when_empty" type="checkbox" wire:model="new_stop_when_empty" class="mt-0.5 h-4 w-4 shrink-0 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40" />
                                <span class="text-xs leading-5 text-brand-ink">
                                    {{ __('Stop when empty') }}
                                    <span class="mt-0.5 block text-2xs text-brand-moss">{{ __('Drain and exit instead of waiting. For burst work — a steady queue wants this off.') }}</span>
                                </span>
                            </label>
                        </details>
                    @endif

                    <div class="flex flex-wrap items-center gap-2">
                        <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="createWorker">
                            <span wire:loading.remove wire:target="createWorker">{{ $new_placement === 'server' ? __('Create worker') : __('Add a machine') }}</span>
                            <span wire:loading wire:target="createWorker">{{ __('Creating…') }}</span>
                        </x-primary-button>
                        <x-secondary-button size="sm" type="button" wire:click="closeCreate">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </form>
            </div>
        @endif

        @if ($workers->isEmpty())
            <div class="px-4 py-5 text-center sm:px-5">
                <p class="text-sm font-medium text-brand-ink">{{ __('No queue workers on this site.') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Nothing is consuming jobs — anything queued will sit until a worker runs.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($workers as $worker)
                    <li class="px-4 py-3.5 sm:px-5" wire:key="worker-{{ $worker->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $worker->slug }}</p>
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200/70' => $worker->is_active,
                                'bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10' => ! $worker->is_active,
                            ])>
                                {{ $worker->is_active ? __('Active') : __('Stopped') }}
                            </span>
                            <span class="text-xs text-brand-mist">{{ __('numprocs') }} {{ $worker->numprocs }}</span>
                        </div>
                        <p class="mt-1 break-all font-mono text-xs leading-relaxed text-brand-mist">{{ $worker->command }}</p>

                        @can('update', $site)
                            <div class="mt-2 flex flex-wrap items-center gap-1.5" wire:loading.class="opacity-60" wire:target="startWorker,stopWorker,restartWorker,deleteWorker">
                                @if ($worker->is_active)
                                    <x-secondary-button size="sm" type="button" wire:click="stopWorker('{{ $worker->id }}')">{{ __('Stop') }}</x-secondary-button>
                                @else
                                    <x-secondary-button size="sm" type="button" wire:click="startWorker('{{ $worker->id }}')">{{ __('Start') }}</x-secondary-button>
                                @endif
                                <x-secondary-button size="sm" type="button" wire:click="restartWorker('{{ $worker->id }}')">{{ __('Restart') }}</x-secondary-button>
                                <x-danger-button size="sm" type="button"
                                    wire:click="deleteWorker('{{ $worker->id }}')"
                                    wire:confirm="{{ __('Remove this worker? Jobs on its queue will sit unprocessed until another worker drains them.') }}">
                                    {{ __('Remove') }}
                                </x-danger-button>
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-4 py-2.5 text-xs text-brand-moss sm:px-5">
            {{ __('These are managed here. Non-queue daemons for this site live under') }}
            <a href="{{ route('sites.daemons', ['server' => $server, 'site' => $site]) }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Workers') }}</a>{{ __('.') }}
        </div>
    </section>
</div>
