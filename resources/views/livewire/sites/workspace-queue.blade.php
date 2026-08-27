{{-- One card, a glance strip, and tabs — the same frame the Schedule
     workspace uses. Three sibling cards read as three unrelated features; the
     question is singular ("is my queue healthy"), so the surface is too. --}}
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
                    <x-secondary-button size="sm" type="button" wire:click="runCanary" wire:loading.attr="disabled" wire:target="runCanary">
                        <span wire:loading.remove wire:target="runCanary">{{ __('Test queue') }}</span>
                        <span wire:loading wire:target="runCanary">{{ __('Starting…') }}</span>
                    </x-secondary-button>
                    <x-primary-button size="sm" type="button" wire:click="refreshSnapshot" wire:loading.attr="disabled" wire:target="refreshSnapshot">
                        <span wire:loading.remove wire:target="refreshSnapshot">{{ __('Refresh') }}</span>
                        <span wire:loading wire:target="refreshSnapshot">{{ __('Queueing…') }}</span>
                    </x-primary-button>
                @endcan
            </x-slot:actions>
        </x-workspace-panel-head>

        @if ($queueConfigWarning)
            @php($suggested = $this->suggestedQueueDriver())
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-amber-50 px-4 py-3 sm:px-5">
                <div class="flex min-w-0 items-start gap-2.5">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                    <p class="text-xs leading-relaxed text-amber-900">
                        {{ $queueConfigWarning }}
                        @if ($suggested === null)
                            {{ __('Attach a Redis or database resource under Resources and this becomes a one-click switch.') }}
                        @endif
                    </p>
                </div>
                @can('update', $site)
                    @if ($suggested !== null)
                        <x-primary-button size="sm" type="button" class="shrink-0" wire:click="switchQueueDriver" wire:loading.attr="disabled" wire:target="switchQueueDriver">
                            <span wire:loading.remove wire:target="switchQueueDriver">{{ __('Switch to :d', ['d' => $suggested]) }}</span>
                            <span wire:loading wire:target="switchQueueDriver">{{ __('Switching…') }}</span>
                        </x-primary-button>
                    @endif
                @endcan
            </div>
        @endif

        @include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

        @php($ready = \App\Support\Sites\SiteQueueReadiness::isReady($readinessChecks))
        @if (! $ready)
            {{-- Four things must all hold for a job to run, and each fails
                 silently on its own. The checklist names which link is broken
                 instead of leaving someone to infer it from a depth of zero. --}}
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-3 sm:px-5">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Queue readiness') }}</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($readinessChecks as $check)
                        <li class="flex items-start gap-2">
                            @if ($check['status'] === 'ok')
                                <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />
                            @else
                                <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                            @endif
                            <p class="min-w-0 text-xs leading-relaxed {{ $check['status'] === 'ok' ? 'text-brand-moss' : 'text-brand-ink' }}">
                                <span class="font-semibold">{{ $check['label'] }}</span>
                                <span class="text-brand-moss">— {{ $check['detail'] }}</span>
                                @if ($check['key'] === 'deploy_restart' && $check['status'] !== 'ok')
                                    @can('update', $site)
                                        <button type="button" wire:click="enableDeployRestart" class="ml-1 font-semibold text-brand-forest hover:underline">{{ __('Turn on') }}</button>
                                    @endcan
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-workspace-panel-head
            dense
            icon="heroicon-o-chart-bar"
            :title="__('Queues at a glance')"
            :note="__('Counts for this site\'s queues and the workers draining them.')"
            class="border-b border-brand-ink/10"
        />

        <x-workspace-stat-strip class="border-b border-brand-ink/10" :stats="[
            ['label' => __('Queues'), 'value' => $queueStats['queues'], 'hint' => __('Seen in the last :h h', ['h' => $window_hours])],
            [
                'label' => __('Pending'),
                'value' => $queueStats['pending'],
                'tone' => $queueStats['pending'] > 0 ? 'warn' : null,
                'hint' => __('Waiting right now'),
            ],
            [
                'label' => __('Failed'),
                'value' => $queueStats['failed'],
                'tone' => $queueStats['failed'] > 0 ? 'warn' : null,
                'hint' => __('Across every queue'),
            ],
            [
                'label' => __('Workers'),
                'value' => $queueStats['workers'] + $queueStats['machines'],
                'tone' => ($queueStats['workers'] + $queueStats['machines']) > 0 ? 'ok' : null,
                'hint' => __('On this box and on pools'),
            ],
        ]" />

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Queue workspace sections')" scroll bare class="!mb-0 w-full">
                <x-server-workspace-tab id="queue-tab-queues" icon="heroicon-o-queue-list" :active="$queue_workspace_tab === 'queues'" wire:click="$set('queue_workspace_tab', 'queues')">
                    {{ __('Queues') }}
                    @if ($queueStats['queues'] > 0)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-2xs font-semibold leading-none tabular-nums">{{ $queueStats['queues'] }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab id="queue-tab-workers" icon="heroicon-o-cpu-chip" :active="$queue_workspace_tab === 'workers'" wire:click="$set('queue_workspace_tab', 'workers')">
                    {{ __('Workers') }}
                    @if ($queueStats['workers'] > 0)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-2xs font-semibold leading-none tabular-nums">{{ $queueStats['workers'] }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab id="queue-tab-jobs" icon="heroicon-o-list-bullet" :active="$queue_workspace_tab === 'jobs'" wire:click="$set('queue_workspace_tab', 'jobs')">
                    {{ __('Jobs') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="queue-tab-catalog" icon="heroicon-o-squares-2x2" :active="$queue_workspace_tab === 'catalog'" wire:click="showJobCatalog">
                    {{ __('Job classes') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="queue-tab-history" icon="heroicon-o-clock" :active="$queue_workspace_tab === 'history'" wire:click="$set('queue_workspace_tab', 'history')">
                    {{ __('History') }}
                    @if ($jobRuns->isNotEmpty())
                        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-2xs font-semibold leading-none tabular-nums">{{ $jobRuns->count() }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab id="queue-tab-fleet" icon="heroicon-o-square-3-stack-3d" :active="$queue_workspace_tab === 'fleet'" wire:click="$set('queue_workspace_tab', 'fleet')">
                    {{ __('Managed servers') }}
                    @if ($queueStats['machines'] > 0)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-2xs font-semibold leading-none tabular-nums">{{ $queueStats['machines'] }}</span>
                    @endif
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        @if ($queue_workspace_tab === 'queues')
            <x-server-workspace-tab-panel id="queue-panel-queues" labelled-by="queue-tab-queues" panel-class="min-w-0">
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
                            <button type="button" wire:click="inspectQueue(@js($queueName))" class="font-mono text-sm font-semibold text-brand-ink hover:text-brand-forest hover:underline">
                                {{ $queueName }}
                            </button>
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
            </x-server-workspace-tab-panel>
        @elseif ($queue_workspace_tab === 'workers')
            <x-server-workspace-tab-panel id="queue-panel-workers" labelled-by="queue-tab-workers" panel-class="min-w-0">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-4 py-3 sm:px-5">
                    <p class="text-xs text-brand-moss">{{ __('Supervisor programs on this site that consume jobs.') }}</p>
                    @can('update', $site)
                        <x-primary-button size="sm" type="button" wire:click="openCreate">
                            <x-heroicon-o-plus class="h-4 w-4" />
                            {{ __('Add worker') }}
                        </x-primary-button>
                    @endcan
                </div>
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
            </x-server-workspace-tab-panel>
        @elseif ($queue_workspace_tab === 'history')
            <x-server-workspace-tab-panel id="queue-panel-history" labelled-by="queue-tab-history" panel-class="min-w-0">
                @if ($jobRuns->isEmpty())
                    <div class="px-4 py-5 text-center sm:px-5">
                        <p class="text-sm font-medium text-brand-ink">{{ __('No job history yet.') }}</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                            {{ __('A finished job leaves nothing behind in the queue store, so history can only come from inside the app.') }}
                            @if (! $this->queueAgentEnabled())
                                {{ __('Install the queue agent below and it records from the next deploy.') }}
                            @else
                                {{ __('The agent is on — history appears once it is deployed and a job runs.') }}
                            @endif
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($jobRuns as $run)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 sm:px-5" wire:key="run-{{ $run->id }}">
                                <div class="min-w-0">
                                    <p class="font-mono text-xs font-semibold {{ $run->status === 'failed' ? 'text-rose-700' : 'text-brand-ink' }}">{{ $run->name }}</p>
                                    @if ($run->status === 'failed' && $run->message)
                                        <p class="mt-0.5 truncate text-2xs text-rose-700">{{ $run->exception }}: {{ $run->message }}</p>
                                    @endif
                                </div>
                                <p class="shrink-0 text-2xs text-brand-mist">
                                    @if ($run->source && $run->source !== 'agent')
                                        {{-- One history, four origins. Without the badge a job
                                             that ran on a pool — or a probe dply dispatched
                                             itself — is indistinguishable from the app's own
                                             work on this box. --}}
                                        <span class="rounded-full bg-brand-sand/70 px-1.5 py-0.5 font-semibold text-brand-forest">{{ $run->source }}</span>
                                        &middot;
                                    @endif
                                    @if ($run->queue){{ $run->queue }} &middot; @endif
                                    @if ($run->duration_ms !== null){{ $run->duration_ms }} ms &middot; @endif
                                    {{ $run->ran_at->diffForHumans() }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-server-workspace-tab-panel>
        @elseif ($queue_workspace_tab === 'jobs')
            <x-server-workspace-tab-panel id="queue-panel-jobs" labelled-by="queue-tab-jobs" panel-class="min-w-0">
                @php($inspected = $this->inspectedJobs())
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-4 py-3 sm:px-5">
                    <p class="text-xs text-brand-moss">
                        @if ($inspect_queue !== '')
                            {{ __('Waiting on') }} <span class="font-mono font-semibold text-brand-ink">{{ $inspect_queue }}</span>
                            @if ($inspected && $inspected['read_at'])
                                <span class="text-brand-mist">· {{ __('read :when', ['when' => \Illuminate\Support\Carbon::parse($inspected['read_at'])->diffForHumans()]) }}</span>
                            @endif
                        @else
                            {{ __('Pick a queue on the Queues tab to see what is waiting on it.') }}
                        @endif
                    </p>
                    @if ($inspect_queue !== '')
                        <x-secondary-button size="sm" type="button" wire:click="inspectQueue(@js($inspect_queue))">{{ __('Re-read') }}</x-secondary-button>
                    @endif
                </div>

                @if ($inspect_queue === '')
                    <div class="px-4 py-5 text-center text-xs text-brand-moss sm:px-5">{{ __('No queue selected.') }}</div>
                @elseif ($inspected === null)
                    {{-- Dispatched but nothing cached yet: this is a queued SSH read,
                         so "reading" is the honest state rather than "empty". --}}
                    <div class="flex items-center justify-center gap-2 px-4 py-5 text-xs text-brand-moss sm:px-5">
                        <x-spinner size="sm" /> {{ __('Reading the queue…') }}
                    </div>
                @elseif ($inspected['error'])
                    <div class="px-4 py-5 text-center sm:px-5">
                        <p class="text-sm font-medium text-brand-ink">{{ __('Cannot list jobs on this driver.') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ $inspected['error'] }}</p>
                    </div>
                @elseif ($inspected['jobs'] === [])
                    <div class="px-4 py-5 text-center sm:px-5">
                        <p class="text-sm font-medium text-brand-ink">{{ __('Nothing waiting.') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ __('Only jobs still WAITING are listed. A job that already ran deletes its own row, so a fast queue looks empty — that is health, not absence.') }}
                        </p>
                        @if (! ($this->queueAgentEnabled()))
                            <p class="mt-1 text-2xs text-brand-mist">
                                {{ __('Completed jobs, durations and throughput need the in-app agent — nothing outside the app can see them.') }}
                            </p>
                        @endif
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($inspected['jobs'] as $job)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 sm:px-5">
                                <p class="min-w-0 font-mono text-xs font-semibold text-brand-ink">{{ $job->name }}</p>
                                <p class="text-2xs text-brand-mist">
                                    @if ($job->attempts > 1)
                                        <span class="font-semibold text-amber-800">{{ __('attempt :n', ['n' => $job->attempts]) }}</span> ·
                                    @endif
                                    @if ($job->waitingSeconds !== null)
                                        {{ __('waiting :s s', ['s' => $job->waitingSeconds]) }}
                                    @else
                                        {{ __('queued') }}
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                    @if ($inspected['truncated'])
                        <div class="border-t border-brand-ink/10 px-4 py-2 text-2xs text-brand-mist sm:px-5">
                            {{ __('Showing the first :n. The backlog is longer.', ['n' => \App\Jobs\CollectSiteQueueJobsJob::LIMIT]) }}
                        </div>
                    @endif
                @endif
            </x-server-workspace-tab-panel>
        @elseif ($queue_workspace_tab === 'catalog')
            <x-server-workspace-tab-panel id="queue-panel-catalog" labelled-by="queue-tab-catalog" panel-class="min-w-0">
                @php($catalog = $this->jobCatalog())
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-4 py-3 sm:px-5">
                    <p class="min-w-0 text-xs text-brand-moss">
                        {{ __('Every queued job class in the deployed code.') }}
                        @if ($catalog && $catalog['read_at'])
                            <span class="text-brand-mist">· {{ __('scanned :when', ['when' => \Illuminate\Support\Carbon::parse($catalog['read_at'])->diffForHumans()]) }}</span>
                        @endif
                    </p>
                    <div class="flex shrink-0 items-center gap-2">
                        @if ($catalog && $catalog['jobs'] !== [])
                            <x-text-input type="search" wire:model.live.debounce.300ms="catalog_filter" class="h-8 w-40 text-xs" :placeholder="__('Filter…')" :aria-label="__('Filter job classes')" />
                        @endif
                        <x-secondary-button size="sm" type="button" wire:click="refreshJobCatalog">{{ __('Re-scan') }}</x-secondary-button>
                    </div>
                </div>

                @if ($catalog === null)
                    {{-- A queued SSH read: "scanning" is the honest state, not "empty". --}}
                    <div class="flex items-center justify-center gap-2 px-4 py-5 text-xs text-brand-moss sm:px-5">
                        <x-spinner size="sm" /> {{ __('Reading the application…') }}
                    </div>
                @elseif ($catalog['error'])
                    <div class="px-4 py-5 text-center sm:px-5">
                        <p class="text-sm font-medium text-brand-ink">{{ __('Could not read the application.') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ $catalog['error'] }}</p>
                    </div>
                @elseif ($catalog['jobs'] === [])
                    <div class="px-4 py-5 text-center sm:px-5">
                        <p class="text-sm font-medium text-brand-ink">
                            {{ $catalog_filter !== '' ? __('Nothing matches that filter.') : __('No queued job classes found.') }}
                        </p>
                        @if ($catalog_filter === '')
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Only classes implementing ShouldQueue in the app\'s own namespaces count — vendor code is excluded on purpose.') }}</p>
                        @endif
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($catalog['jobs'] as $job)
                            @php($dispatchable = ! in_array($job['kind'], ['mail', 'notification', 'broadcast'], true))
                            @php($needsArgs = (int) $job['required_args'] > 0)
                            {{-- scalar_args is absent from catalogues scanned before it
                                 existed; treat a missing flag as "askable" so an old
                                 cache degrades to the remote guard rather than to a
                                 disabled button. --}}
                            @php($askable = $dispatchable && ($job['scalar_args'] ?? true))
                            <li class="px-4 py-2.5 sm:px-5" wire:key="jc-{{ md5($job['class']) }}">
                              <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate font-mono text-xs font-semibold text-brand-ink" title="{{ $job['class'] }}">{{ class_basename($job['class']) }}</p>
                                    <p class="mt-0.5 truncate text-2xs text-brand-mist">
                                        {{ $job['class'] }}
                                        @if ($job['signature'])<span class="text-brand-moss">({{ $job['signature'] }})</span>@endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2 text-2xs text-brand-mist">
                                    @if ($job['kind'] !== 'job')
                                        <span class="rounded-full bg-brand-sand/70 px-1.5 py-0.5 font-semibold text-brand-forest">{{ $job['kind'] }}</span>
                                    @endif
                                    @if ($job['queue'])<span class="font-mono">{{ $job['queue'] }}</span>@endif
                                    @if ($job['tries'] !== null)<span>{{ __(':n tries', ['n' => $job['tries']]) }}</span>@endif
                                    @if ($job['unique'])<span>{{ __('unique') }}</span>@endif
                                    @can('update', $site)
                                        @if (! $dispatchable)
                                            <span class="text-brand-mist">{{ __('not dispatchable') }}</span>
                                        @elseif (! $askable)
                                            {{-- A job wanting an Order cannot be reached from here at
                                                 all: dply can pass numbers and strings, not models. --}}
                                            <span class="text-brand-mist">{{ __('needs objects') }}</span>
                                        @elseif ($needsArgs)
                                            <x-secondary-button size="sm" type="button" wire:click="promptDispatchArgs(@js($job['class']))">
                                                {{ $dispatch_class === $job['class'] ? __('Cancel') : __('Run with args…') }}
                                            </x-secondary-button>
                                        @else
                                            {{-- This runs the real job. The confirm is the only thing
                                                 between a curious click and real production work. --}}
                                            <x-secondary-button size="sm" type="button"
                                                wire:click="dispatchTestJob(@js($job['class']))"
                                                wire:confirm="{{ __('Dispatch :class for real? Whatever this job does in production, it will do now.', ['class' => class_basename($job['class'])]) }}">
                                                {{ __('Run') }}
                                            </x-secondary-button>
                                        @endif
                                    @endcan
                                </div>
                              </div>

                              @if ($dispatch_class === $job['class'])
                                <div class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-brand-sand/30 px-3 py-2">
                                    <x-text-input wire:model="dispatch_args" class="h-8 min-w-0 flex-1 font-mono text-xs"
                                        placeholder='[12, "now"]' :aria-label="__('Constructor arguments as JSON')" />
                                    <x-secondary-button size="sm" type="button"
                                        wire:click="dispatchTestJob(@js($job['class']))"
                                        wire:confirm="{{ __('Dispatch :class for real? Whatever this job does in production, it will do now.', ['class' => class_basename($job['class'])]) }}">
                                        {{ __('Run') }}
                                    </x-secondary-button>
                                    <p class="w-full text-2xs text-brand-mist">
                                        {{ __('Positional, JSON: :sig', ['sig' => $job['signature'] ?: '—']) }}
                                    </p>
                                    @error('dispatch_args')<p class="w-full text-2xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                </div>
                              @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($catalog['truncated'])
                        <div class="border-t border-brand-ink/10 px-4 py-2 text-2xs text-brand-mist sm:px-5">
                            {{ __('Showing the first :n classes.', ['n' => \App\Jobs\CollectSiteJobClassesJob::LIMIT]) }}
                        </div>
                    @endif
                @endif
            </x-server-workspace-tab-panel>
        @else
            <x-server-workspace-tab-panel id="queue-panel-fleet" labelled-by="queue-tab-fleet" panel-class="min-w-0">
                @if ($pools->isEmpty())
                    <div class="px-4 py-5 text-center sm:px-5">
                        <p class="text-sm font-medium text-brand-ink">{{ __('No managed worker servers attached.') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ __('Attach a pool to drain this site\'s queues on dedicated machines.') }}
                            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'worker-fleet']) }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Add one') }}</a>
                        </p>
                    </div>
                @else
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
                @endif
            </x-server-workspace-tab-panel>
        @endif

        @if (config('dply.queue_insights.enabled'))
            <div class="flex flex-wrap items-start justify-between gap-3 border-t border-brand-ink/10 px-4 py-3 sm:px-5">
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Job timing and throughput') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                        {{ __('Depth, waiting jobs and failures are read from the queue store already. Duration, success and per-class throughput need dply/queue-insights inside the app — a completed job deletes its own row, so nothing outside can see it.') }}
                    </p>
                </div>
                @can('update', $site)
                    <x-secondary-button size="sm" type="button" wire:click="toggleQueueAgent" class="shrink-0">
                        {{ $this->queueAgentEnabled() ? __('Disable agent') : __('Install on next deploy') }}
                    </x-secondary-button>
                @endcan
            </div>
        @endif

        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-4 py-3 sm:px-5">
            <x-cli-snippet tone="stub" />
        </div>
    </section>
</div>
