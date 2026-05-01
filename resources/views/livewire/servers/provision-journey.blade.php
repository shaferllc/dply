@php
    $card = 'dply-card overflow-hidden';
    $progressPercent = $totalCount > 0 ? (int) round(($completedCount / $totalCount) * 100) : 0;
    $journeyAlerts = [];
    if (! empty($infrastructureAlerts['digitalocean_gone'] ?? null)) {
        $journeyAlerts['digitalocean_gone'] = $infrastructureAlerts['digitalocean_gone'];
    } elseif (! empty($infrastructureAlerts['digitalocean_unknown'] ?? null)) {
        $journeyAlerts['digitalocean_unknown'] = $infrastructureAlerts['digitalocean_unknown'];
    }
    if (! empty($infrastructureAlerts['ssh_unreachable'] ?? null)) {
        $journeyAlerts['ssh_unreachable'] = $infrastructureAlerts['ssh_unreachable'];
    }
    $allStepsDone = $totalCount > 0 && $completedCount >= $totalCount;
    $hasJourneyAlerts = $journeyAlerts !== [];
@endphp

<div
    @if ($shouldPoll)
        wire:poll.5s
    @endif
    x-data
>
    <x-server-workspace-layout
        :server="$server"
        active="overview"
        :title="__('Server creation')"
        :description="__('Track provisioning and setup until this server is ready.')"
        :show-navigation="false"
        doc-route="docs.create-first-server"
        :doc-label="__('Provisioning guide')"
        page-header-toolbar="true"
        page-header-compact="true"
    >
        <x-slot name="headerLeading">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                <x-heroicon-o-rocket-launch class="h-7 w-7 text-brand-ink" aria-hidden="true" />
            </span>
        </x-slot>

        @include('livewire.servers.partials.workspace-flashes')

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(17rem,20rem)] lg:gap-8">
            @if ($hasJourneyAlerts)
                <div class="col-span-full overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/5">
                    @foreach ($journeyAlerts as $key => $row)
                        @continue(empty($row))
                        <div @class([
                            'flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-start sm:gap-4 sm:px-5 sm:py-4',
                            'border-t border-brand-ink/10' => ! $loop->first,
                            'bg-amber-50/95' => $key === 'digitalocean_gone',
                            'bg-slate-50/90' => $key === 'digitalocean_unknown',
                            'bg-orange-50/95' => $key === 'ssh_unreachable',
                        ])>
                            <div class="flex min-w-0 flex-1 gap-3">
                                @if ($key === 'digitalocean_gone')
                                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-700" />
                                @elseif ($key === 'digitalocean_unknown')
                                    <x-heroicon-o-question-mark-circle class="mt-0.5 h-5 w-5 shrink-0 text-slate-600" />
                                @else
                                    <x-heroicon-o-exclamation-circle class="mt-0.5 h-5 w-5 shrink-0 text-orange-800" />
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p @class([
                                        'text-sm font-semibold leading-snug',
                                        'text-amber-950' => $key === 'digitalocean_gone',
                                        'text-brand-ink' => $key === 'digitalocean_unknown',
                                        'text-orange-950' => $key === 'ssh_unreachable',
                                    ])>{{ $row['headline'] }}</p>
                                    <p @class([
                                        'mt-1 text-sm leading-relaxed',
                                        'text-amber-950/90' => $key === 'digitalocean_gone',
                                        'text-brand-moss' => $key === 'digitalocean_unknown',
                                        'text-orange-950/88' => $key === 'ssh_unreachable',
                                    ])>{{ $row['detail'] }}</p>
                                </div>
                            </div>
                            @can('delete', $server)
                                <div class="flex shrink-0 flex-wrap gap-2 sm:ml-auto sm:justify-end sm:pt-0.5">
                                    <button
                                        type="button"
                                        wire:click="openRemoveServerModal"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-800 shadow-sm transition-colors hover:border-red-300 hover:bg-red-50"
                                    >
                                        <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Remove from Dply…') }}
                                    </button>
                                </div>
                            @endcan
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- lg grid: left column = journey then artifacts (stacked); right column = sidebar spanning both rows — avoids col-span-full artifacts row sitting under a tall journey + short sidebar. Row indices depend on optional alerts row. --}}
            <section @class([
                $card,
                'min-w-0 sm:p-8 lg:col-start-1',
                'lg:row-start-2' => $hasJourneyAlerts,
                'lg:row-start-1' => ! $hasJourneyAlerts,
            ])>
                <div class="flex flex-col gap-6 border-b border-brand-ink/10 px-5 pb-6 pt-6 sm:px-8 sm:pb-8 sm:pt-8">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Provision journey') }}</p>
                            <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">{{ __('Installation tasks (:done/:total)', ['done' => $completedCount, 'total' => $totalCount]) }}</h2>
                            <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss">
                                {{ __('Provisioning and stack setup update automatically here.') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                        @if ($canCancelProvision)
                            <button
                                type="button"
                                wire:click="openCancelProvisionModal"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 shadow-sm transition-colors hover:border-red-300 hover:bg-red-100"
                            >
                                <x-heroicon-o-x-circle class="h-4 w-4" />
                                {{ __('Cancel build') }}
                            </button>
                        @endif
                        @if (\App\Jobs\RunSetupScriptJob::shouldDispatch($server) && $server->setup_status !== \App\Models\Server::SETUP_STATUS_RUNNING)
                            <button
                                type="button"
                                wire:click="rerunSetup"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage"
                            >
                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                                {{ __('Resume install') }}
                            </button>
                        @endif
                        @if ($server->status === \App\Models\Server::STATUS_READY && ! in_array($server->setup_status, [\App\Models\Server::SETUP_STATUS_PENDING, \App\Models\Server::SETUP_STATUS_RUNNING], true))
                            <a
                                href="{{ route('servers.overview', $server) }}"
                                wire:navigate
                                class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                            >
                                {{ __('Open server workspace') }}
                            </a>
                        @endif
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm font-medium text-brand-ink">{{ __('Progress') }}</span>
                            <span class="text-sm tabular-nums text-brand-moss">{{ __(':done of :total steps', ['done' => $completedCount, 'total' => $totalCount]) }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-brand-sand/80">
                                <div class="h-full rounded-full bg-emerald-600 transition-[width] duration-300" style="width: {{ $progressPercent }}%"></div>
                            </div>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-brand-forest">{{ $progressPercent }}%</span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-6 px-5 py-6 sm:px-8 sm:py-8">
                    @if ($failedStep)
                        <div class="rounded-2xl border border-red-200/90 bg-red-50/90 px-4 py-4 sm:px-5">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-red-900">{{ $failedStep['label'] }}</p>
                                    @if ($failedStep['detail'])
                                        <p class="mt-1 text-sm leading-6 text-red-800">{{ $failedStep['detail'] }}</p>
                                    @endif
                                    @if ($failedStep['output'])
                                        <details class="mt-4 rounded-xl border border-red-200 bg-white/80 p-4">
                                            <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-red-700">
                                                <div class="flex items-center justify-between gap-3">
                                                    <span>{{ __('Captured output') }}</span>
                                                    <x-heroicon-o-chevron-down class="h-4 w-4" />
                                                </div>
                                            </summary>
                                            <pre class="mt-3 whitespace-pre-wrap font-mono text-xs leading-6 text-red-900">{{ $failedStep['output'] }}</pre>
                                        </details>
                                    @endif
                                </div>
                                <span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-red-800">{{ __('Failed') }}</span>
                            </div>
                        </div>
                    @elseif ($activeStep)
                        <div class="rounded-2xl border border-sky-200/80 bg-gradient-to-br from-sky-50/95 to-white px-4 py-4 sm:px-5">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full border-[3px] border-sky-200 border-t-sky-600" aria-hidden="true"></span>
                                        <p class="text-base font-semibold text-brand-ink sm:text-lg">{{ $activeStep['label'] }}</p>
                                    </div>
                                    @if ($activeStep['detail'])
                                        <p class="mt-3 text-sm leading-6 text-brand-moss whitespace-pre-line">{{ $activeStep['detail'] }}</p>
                                    @endif
                                    @if ($stallState)
                                        <div class="mt-3 rounded-xl border border-brand-ink/10 bg-white/90 p-4">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Run timing') }}</p>
                                            <p class="mt-2 text-sm text-brand-ink">{{ $stallState['eta'] }}</p>
                                            <p class="mt-1 text-sm text-brand-moss">{{ $stallState['last_output'] }}</p>
                                            @if ($stallState['warning'])
                                                <p class="mt-2 text-sm font-medium text-amber-700">{{ $stallState['warning'] }}</p>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($activeStep['output'])
                                        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-white/90 p-4">
                                            <div class="flex items-center justify-between gap-3">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Live step output') }}</p>
                                                @if ($taskUpdatedAt)
                                                    <p class="text-[11px] text-brand-mist">{{ __('updated :ago', ['ago' => $taskUpdatedAt->diffForHumans()]) }}</p>
                                                @endif
                                            </div>
                                            <pre
                                                x-data
                                                x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                                                x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                                                class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap font-mono text-xs leading-6 text-brand-ink"
                                            >{{ $activeStep['output'] }}</pre>
                                        </div>
                                    @endif
                                    @if ($task && $liveTaskOutput && (! $activeStep['output'] || trim($activeStep['output']) !== trim($liveTaskOutput)))
                                        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-white/90 p-4">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Live task output (tail)') }}</p>
                                                <p class="text-[11px] text-brand-mist">
                                                    {{ __(':count lines', ['count' => $liveTaskOutputLineCount]) }}
                                                    @if ($taskUpdatedAt)
                                                        · {{ __('updated :ago', ['ago' => $taskUpdatedAt->diffForHumans()]) }}
                                                    @endif
                                                </p>
                                            </div>
                                            <pre
                                                x-data
                                                x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                                                x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                                                class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap break-all font-mono text-[11px] leading-relaxed text-brand-ink"
                                            >{{ $liveTaskOutput }}</pre>
                                        </div>
                                    @elseif ($task && ! $activeStep['output'])
                                        <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-white/70 p-4">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Live task output') }}</p>
                                            <p class="mt-2 text-sm text-brand-moss">{{ __('No output received from the server yet. The bootstrap script may still be initialising or the webhook callback is unreachable from the target host.') }}</p>
                                            @if ($taskUpdatedAt)
                                                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Last task update :ago', ['ago' => $taskUpdatedAt->diffForHumans()]) }}</p>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                @if ($activeStep['duration'])
                                    <span class="shrink-0 text-sm font-medium text-brand-moss">{{ $activeStep['duration'] }}</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-sand/10">
                        <details class="group border-b border-brand-ink/10 last:border-b-0" @if($pendingSteps->isNotEmpty()) open @endif>
                            <summary class="cursor-pointer list-none px-4 py-3.5 sm:px-5">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-semibold text-brand-ink">{{ __('Up next (:count)', ['count' => $pendingSteps->count()]) }}</span>
                                    <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition-transform group-open:rotate-180" />
                                </div>
                            </summary>
                            @if ($pendingSteps->isNotEmpty())
                                <ul class="space-y-0 border-t border-brand-ink/10 bg-white/80 px-4 py-2 sm:px-5">
                                    @foreach ($pendingSteps as $step)
                                        <li class="flex gap-3 border-b border-brand-ink/5 py-3 last:border-b-0">
                                            <span class="mt-1.5 inline-flex h-2 w-2 shrink-0 rounded-full bg-brand-mist ring-4 ring-brand-sand/40" aria-hidden="true"></span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-medium text-brand-ink">{{ $step['label'] }}</p>
                                                @if ($step['detail'])
                                                    <p class="mt-0.5 text-sm text-brand-moss">{{ $step['detail'] }}</p>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="border-t border-brand-ink/10 bg-white/60 px-4 py-3 text-sm text-brand-moss sm:px-5">{{ __('No queued steps.') }}</p>
                            @endif
                        </details>

                        <details class="group" @if($completedSteps->isNotEmpty() && ($pendingSteps->isEmpty() || $allStepsDone)) open @endif>
                            <summary class="cursor-pointer list-none px-4 py-3.5 sm:px-5">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-semibold text-brand-ink">{{ __('Completed (:count)', ['count' => $completedSteps->count()]) }}</span>
                                    <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition-transform group-open:rotate-180" />
                                </div>
                            </summary>
                            @if ($completedSteps->isNotEmpty())
                                <ul class="space-y-0 border-t border-brand-ink/10 bg-white/90 px-4 py-2 sm:px-5">
                                    @foreach ($completedSteps as $step)
                                        <li class="flex gap-3 border-b border-brand-ink/5 py-3 last:border-b-0">
                                            <span class="mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white shadow-sm">
                                                <x-heroicon-o-check class="h-3 w-3" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                    <p class="text-sm font-medium text-brand-forest">{{ $step['label'] }}</p>
                                                    @if ($step['duration'])
                                                        <span class="text-xs tabular-nums text-brand-moss">{{ $step['duration'] }}</span>
                                                    @endif
                                                </div>
                                                @if ($step['detail'])
                                                    <p class="mt-0.5 text-sm text-brand-moss whitespace-pre-line">{{ $step['detail'] }}</p>
                                                @endif
                                                @if ($step['output'])
                                                    <details class="mt-2 rounded-lg border border-emerald-100/80 bg-emerald-50/50 p-3">
                                                        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Output') }}</summary>
                                                        <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap font-mono text-[11px] leading-relaxed text-brand-ink">{{ $step['output'] }}</pre>
                                                    </details>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </details>
                    </div>
                </div>
            </section>

            <aside @class([
                'w-full space-y-6 self-start lg:col-start-2 lg:max-w-none',
                'lg:row-start-2 lg:row-span-2' => $artifacts->isNotEmpty() && $hasJourneyAlerts,
                'lg:row-start-1 lg:row-span-2' => $artifacts->isNotEmpty() && ! $hasJourneyAlerts,
                'lg:row-start-2' => $artifacts->isEmpty() && $hasJourneyAlerts,
                'lg:row-start-1' => $artifacts->isEmpty() && ! $hasJourneyAlerts,
            ])>
                <section class="{{ $card }} p-5 sm:p-6">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Server summary') }}</h3>
                    <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                            <dd class="mt-0.5 font-semibold capitalize text-brand-ink">{{ $server->status }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Provider') }}</dt>
                            <dd class="mt-0.5 font-medium text-brand-ink">{{ $server->provider->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                            <dd class="mt-0.5 font-medium text-brand-ink">{{ $server->region ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Size') }}</dt>
                            <dd class="mt-0.5 font-medium text-brand-ink">{{ $server->size ?: '—' }}</dd>
                        </div>
                        @if ($server->setup_status)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Setup') }}</dt>
                                <dd class="mt-0.5 font-medium capitalize text-brand-ink">{{ $server->setup_status }}</dd>
                            </div>
                        @endif
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('IP address') }}</dt>
                            <dd class="mt-0.5 break-all font-mono text-xs font-medium text-brand-ink">{{ $server->ip_address ?: '—' }}</dd>
                        </div>
                    </dl>
                    @can('delete', $server)
                        <div class="mt-5 border-t border-brand-ink/10 pt-4">
                            <p class="text-xs leading-relaxed text-brand-moss">
                                {{ __('If the machine is gone or you want to abandon this install, remove the server record from Dply (and tear down linked cloud resources when applicable).') }}
                            </p>
                            <button
                                type="button"
                                wire:click="openRemoveServerModal"
                                class="mt-3 text-sm font-medium text-red-700 hover:text-red-900"
                            >
                                {{ __('Remove or schedule removal…') }}
                            </button>
                        </div>
                    @endcan
                </section>

                @if (app()->environment('local'))
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Setup diagnostics') }}</h3>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('Structured entries are written to the app log with keys like server.provision.* — filter logs by server_id or grep server.provision.') }}
                        </p>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="text-brand-moss">{{ __('Server ID') }}</dt>
                                <dd class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $server->id }}</dd>
                            </div>
                            <div>
                                <dt class="text-brand-moss">{{ __('Poll') }}</dt>
                                <dd class="mt-1 font-medium text-brand-ink">{{ $shouldPoll ? __('On (5s)') : __('Off') }}</dd>
                            </div>
                            @php($tid = $server->meta['provision_task_id'] ?? null)
                            @php($rid = $server->meta['provision_run_id'] ?? null)
                            <div>
                                <dt class="text-brand-moss">{{ __('Provision task') }}</dt>
                                <dd class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $tid ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-brand-moss">{{ __('Provision run') }}</dt>
                                <dd class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $rid ?: '—' }}</dd>
                            </div>
                            @if ($task)
                                <div>
                                    <dt class="text-brand-moss">{{ __('TaskRunner status') }}</dt>
                                    <dd class="mt-1 font-medium text-brand-ink">{{ $task->status->value }}</dd>
                                </div>
                            @endif
                        </dl>
                    </section>
                @endif

                @if (app()->environment('local') && ! empty($localDevShellHints))
                    @php($hints = $localDevShellHints)
                    <section
                        class="{{ $card }} p-6"
                        x-data="{ copied: null }"
                    >
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Local shell & Docker') }}</h3>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('The product does not embed a browser SSH session here; use your terminal on the machine where Docker runs, or point an optional web terminal URL below.') }}
                        </p>

                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-3">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Fake cloud (local API bypass)') }}</dt>
                                <dd class="mt-1 text-brand-ink">
                                    @if ($hints['fake_cloud_enabled'])
                                        <span class="font-medium text-emerald-800">{{ __('Enabled') }}</span>
                                        <span class="text-brand-moss">
                                            —
                                            <a href="{{ route('docs.markdown', 'local-development') }}" wire:navigate class="font-medium text-sky-800 underline decoration-sky-800/30 hover:text-sky-950">{{ __('Local development') }}</a>
                                            {{ __('in the docs describes fake cloud targets and the SSH compose workflow.') }}
                                        </span>
                                    @else
                                        <span class="font-medium text-amber-800">{{ __('Off') }}</span>
                                        <span class="text-brand-moss">— {{ __('Set DPLY_FAKE_CLOUD_PROVISION=true (and APP_ENV=local) or provisioning will use real accounts (e.g. DigitalOcean).') }}</span>
                                    @endif
                                </dd>
                                <dd class="mt-2 text-xs text-brand-moss">
                                    {{ __('This server’s provider credential:') }}
                                    <span class="font-mono text-brand-ink">{{ $hints['is_fake_server'] ? __('fake / local target') : __('real provider account') }}</span>
                                </dd>
                            </div>

                            @if ($hints['ssh'] !== '')
                                <div>
                                    <dt class="text-brand-moss">{{ __('SSH to this host (from your Mac/PC)') }}</dt>
                                    <dd class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <pre class="min-w-0 flex-1 overflow-x-auto rounded-lg bg-brand-ink/5 p-2 font-mono text-xs text-brand-ink">{{ $hints['ssh'] }}</pre>
                                        <button
                                            type="button"
                                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/30"
                                            @click="navigator.clipboard.writeText(@js($hints['ssh'])); copied = 'ssh'; clearTimeout(window._dplyJourneyCopyT); window._dplyJourneyCopyT = setTimeout(() => copied = null, 2000)"
                                        >
                                            <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                                            <span x-show="copied !== 'ssh'">{{ __('Copy') }}</span>
                                            <span x-cloak x-show="copied === 'ssh'" class="text-emerald-700">{{ __('Copied') }}</span>
                                        </button>
                                    </dd>
                                </div>
                            @endif

                            <div>
                                <dt class="text-brand-moss">{{ __('Shell inside the SSH dev container (Docker on your host)') }}</dt>
                                <dd class="mt-1 text-xs text-brand-moss">{{ __('Use this on the same machine that runs Docker Compose, not on the remote VM.') }}</dd>
                                <dd class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <pre class="min-w-0 flex-1 overflow-x-auto rounded-lg bg-brand-ink/5 p-2 font-mono text-xs text-brand-ink">{{ $hints['docker_exec'] }}</pre>
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/30"
                                        @click="navigator.clipboard.writeText(@js($hints['docker_exec'])); copied = 'docker'; clearTimeout(window._dplyJourneyCopyT); window._dplyJourneyCopyT = setTimeout(() => copied = null, 2000)"
                                    >
                                        <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                                        <span x-show="copied !== 'docker'">{{ __('Copy') }}</span>
                                        <span x-cloak x-show="copied === 'docker'" class="text-emerald-700">{{ __('Copied') }}</span>
                                    </button>
                                </dd>
                            </div>

                            @if (! empty($hints['web_terminal_url']))
                                <div>
                                    <dt class="text-brand-moss">{{ __('Web terminal (optional)') }}</dt>
                                    <dd class="mt-2">
                                        <a
                                            href="{{ $hints['web_terminal_url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-sm font-medium text-sky-800 underline decoration-sky-800/30 hover:text-sky-950"
                                        >
                                            {{ __('Open configured web terminal') }}
                                        </a>
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('Set DPLY_DEV_SSH_WEB_TERMINAL_URL (e.g. ttyd running locally).') }}</p>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </section>
                @endif

                @if ($task)
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Setup task') }}</h3>
                        <div class="mt-4 space-y-3 text-sm">
                            <p class="text-brand-moss">{{ __('Status') }}: <span class="font-medium text-brand-ink">{{ ucfirst($task->status->value) }}</span></p>
                            @if ($task->started_at)
                                <p class="text-brand-moss">{{ __('Started') }}: <span class="font-medium text-brand-ink">{{ $task->started_at->diffForHumans() }}</span></p>
                            @endif
                            <div class="min-w-0 rounded-xl bg-brand-sand/20 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Recent output') }}</p>
                                <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all font-mono text-xs text-brand-ink">{{ $task->tailOutput(6) ?: __('No task output yet.') }}</pre>
                            </div>
                        </div>
                    </section>
                @endif

                @if ($run)
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Provision run') }}</h3>
                        <div class="mt-4 space-y-3 text-sm">
                            <p class="text-brand-moss">{{ __('Attempt') }}: <span class="font-medium text-brand-ink">#{{ $run->attempt }}</span></p>
                            <p class="text-brand-moss">{{ __('Run status') }}: <span class="font-medium text-brand-ink">{{ ucfirst($run->status) }}</span></p>
                            @if ($failureClassification)
                                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-red-700">{{ __('Failure classification') }}</p>
                                    <p class="mt-1 text-sm font-semibold text-red-900">{{ $failureClassification['label'] }}</p>
                                    <p class="mt-1 text-sm text-red-800">{{ $failureClassification['detail'] }}</p>
                                </div>
                            @endif
                            @if ($run->rollback_status)
                                <p class="text-brand-moss">{{ __('Rollback') }}: <span class="font-medium text-brand-ink">{{ str_replace('_', ' ', ucfirst($run->rollback_status)) }}</span></p>
                            @endif
                            @if ($run->summary)
                                <p class="rounded-xl bg-brand-sand/20 p-4 text-brand-ink">{{ $run->summary }}</p>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($verificationChecks !== [])
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Verification results') }}</h3>
                        <div class="mt-4 space-y-3">
                            @foreach ($verificationChecks as $check)
                                <div class="rounded-xl border {{ $check['status'] === 'ok' ? 'border-emerald-100 bg-emerald-50/70' : 'border-red-200 bg-red-50/70' }} px-4 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-brand-ink">{{ $check['label'] }}</p>
                                            @if ($check['detail'])
                                                <p class="mt-1 text-sm {{ $check['status'] === 'ok' ? 'text-brand-moss' : 'text-red-800' }}">{{ $check['detail'] }}</p>
                                            @endif
                                        </div>
                                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $check['status'] === 'ok' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">
                                            {{ $check['status'] === 'ok' ? __('Passed') : __('Needs attention') }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($repairGuidance)
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Repair guidance') }}</h3>
                        <p class="mt-4 text-sm leading-6 text-brand-moss">{{ $repairGuidance['summary'] }}</p>
                        <div class="mt-4 space-y-2">
                            @foreach ($repairGuidance['actions'] as $action)
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3 text-sm text-brand-ink">{{ $action }}</div>
                            @endforeach
                        </div>
                        @if ($repairGuidance['commands'] !== [])
                            <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Suggested commands') }}</p>
                                <pre class="mt-2 whitespace-pre-wrap font-mono text-xs leading-6 text-brand-ink">{{ implode("\n", $repairGuidance['commands']) }}</pre>
                            </div>
                        @endif
                    </section>
                @endif

                @if ($stackSummary)
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Installed stack') }}</h3>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div><dt class="text-brand-moss">{{ __('Role') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $stackSummary['role'] ?: '—' }}</dd></div>
                            <div><dt class="text-brand-moss">{{ __('Web server') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $stackSummary['webserver'] ?: '—' }}</dd></div>
                            <div><dt class="text-brand-moss">{{ __('PHP') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $stackSummary['php_version'] ?: '—' }}</dd></div>
                            <div><dt class="text-brand-moss">{{ __('Database') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $stackSummary['database'] ?: '—' }}</dd></div>
                            <div><dt class="text-brand-moss">{{ __('Cache') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $stackSummary['cache_service'] ?: '—' }}</dd></div>
                            <div><dt class="text-brand-moss">{{ __('Deploy user') }}</dt><dd class="mt-1 font-medium text-brand-ink">{{ $stackSummary['deploy_user'] ?: '—' }}</dd></div>
                        </dl>
                        @if ($stackSummary['expected_services'] !== [])
                            <div class="mt-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Expected services') }}</p>
                                <p class="mt-2 text-sm text-brand-ink">{{ implode(', ', $stackSummary['expected_services']) }}</p>
                            </div>
                        @endif
                        @if ($stackSummary['paths'] !== [])
                            <div class="mt-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Deploy paths') }}</p>
                                <div class="mt-2 space-y-2">
                                    @foreach ($stackSummary['paths'] as $label => $path)
                                        <div class="text-sm text-brand-ink"><span class="font-medium">{{ ucfirst($label) }}:</span> <span class="font-mono">{{ $path }}</span></div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($stackSummary['config_files'] !== [])
                            <div class="mt-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Config files') }}</p>
                                <div class="mt-2 space-y-2">
                                    @foreach ($stackSummary['config_files'] as $file)
                                        <div class="text-sm font-mono text-brand-ink">{{ $file }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                @endif
            </aside>

            @if ($artifacts->isNotEmpty())
                <section @class([
                    $card,
                    'min-w-0 overflow-hidden p-6 sm:p-8 lg:col-start-1',
                    'lg:row-start-3' => $hasJourneyAlerts,
                    'lg:row-start-2' => ! $hasJourneyAlerts,
                ])>
                    <h3 class="text-lg font-semibold text-brand-ink">{{ __('Provision artifacts') }}</h3>
                    <p class="mt-1 max-w-prose text-sm text-brand-moss">{{ __('Rendered configs and metadata from this provision run. Scroll horizontally on wide files.') }}</p>
                    <div class="mt-6 space-y-6">
                        @foreach ($artifacts as $artifact)
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:p-5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $artifact->label }}</p>
                                    <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ str_replace('_', ' ', $artifact->type) }}</span>
                                </div>
                                @if ($artifact->content)
                                    <div class="mt-3 max-h-[min(28rem,70vh)] min-w-0 overflow-auto rounded-lg bg-white/90 ring-1 ring-brand-ink/10">
                                        <pre class="block w-max min-w-full p-4 font-mono text-xs leading-relaxed text-brand-ink whitespace-pre">{{ $artifact->content }}</pre>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        <x-slot name="modals">
            @if ($showCancelProvisionModal)
                <div
                    class="fixed inset-0 z-50 overflow-y-auto"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="cancel-provision-title"
                >
                    <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeCancelProvisionModal"></div>
                    <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10">
                        <div class="w-full max-w-xl dply-modal-panel" @click.stop>
                            <div class="border-b border-zinc-100 px-6 py-6 sm:px-8 sm:py-7">
                                <h2 id="cancel-provision-title" class="text-lg font-semibold text-brand-ink">{{ __('Cancel server build') }}</h2>
                                <p class="mt-3 text-sm leading-relaxed text-brand-moss">
                                    {{ __('Stop the current server build if it is still running. You can keep the server for another attempt, or continue into the remove flow if you want to delete the server entirely.') }}
                                </p>
                            </div>
                            <div class="space-y-5 px-6 py-7 sm:px-8 sm:py-8">
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-5 text-sm text-brand-moss">
                                    <p class="font-medium text-brand-ink">{{ __('Current status') }}</p>
                                    <p class="mt-2">{{ __('Server') }}: <span class="font-medium text-brand-ink">{{ ucfirst($server->status) }}</span></p>
                                    <p class="mt-1">{{ __('Setup') }}: <span class="font-medium text-brand-ink">{{ ucfirst($server->setup_status) }}</span></p>
                                    @if ($task)
                                        <p class="mt-1">{{ __('Task') }}: <span class="font-medium text-brand-ink">{{ ucfirst($task->status->value) }}</span></p>
                                    @endif
                                </div>

                                @if ($task && $task->status->isActive())
                                    <button
                                        type="button"
                                        wire:click="cancelProvision"
                                        wire:loading.attr="disabled"
                                        wire:target="cancelProvision,cancelProvisionAndOpenDelete"
                                        class="group flex w-full items-start justify-between rounded-2xl border border-brand-ink/10 bg-white px-5 py-4 text-left shadow-sm transition-colors hover:border-brand-sage hover:bg-brand-sand/20 disabled:cursor-wait disabled:opacity-60"
                                    >
                                        <span>
                                            <span class="block text-sm font-semibold text-brand-ink">{{ __('Cancel build and keep server') }}</span>
                                            <span class="mt-1 block text-sm text-brand-moss">
                                                <span wire:loading.remove wire:target="cancelProvision">{{ __('Stop the active provisioning task and leave the server in place so you can inspect it or rerun setup later.') }}</span>
                                                <span wire:loading wire:target="cancelProvision">{{ __('Stopping the build — sending kill signal to the server…') }}</span>
                                            </span>
                                        </span>
                                        <span class="mt-0.5 shrink-0">
                                            <x-heroicon-o-pause-circle wire:loading.remove wire:target="cancelProvision" class="h-5 w-5 text-brand-ink" />
                                            <svg wire:loading wire:target="cancelProvision" class="h-5 w-5 animate-spin text-brand-ink" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                            </svg>
                                        </span>
                                    </button>
                                @endif

                                @can('delete', $server)
                                    <button
                                        type="button"
                                        wire:click="cancelProvisionAndOpenDelete"
                                        wire:loading.attr="disabled"
                                        wire:target="cancelProvision,cancelProvisionAndOpenDelete"
                                        class="group flex w-full items-start justify-between rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-left shadow-sm transition-colors hover:border-red-300 hover:bg-red-100 disabled:cursor-wait disabled:opacity-60"
                                    >
                                        <span>
                                            <span class="block text-sm font-semibold text-red-900">{{ __('Cancel build and remove server') }}</span>
                                            <span class="mt-1 block text-sm text-red-800">
                                                <span wire:loading.remove wire:target="cancelProvisionAndOpenDelete">{{ __('Stop the build first, then continue into the existing removal confirmation flow to delete the server and any linked provider resource.') }}</span>
                                                <span wire:loading wire:target="cancelProvisionAndOpenDelete">{{ __('Stopping the build, then opening the remove confirmation…') }}</span>
                                            </span>
                                        </span>
                                        <span class="mt-0.5 shrink-0">
                                            <x-heroicon-o-trash wire:loading.remove wire:target="cancelProvisionAndOpenDelete" class="h-5 w-5 text-red-800" />
                                            <svg wire:loading wire:target="cancelProvisionAndOpenDelete" class="h-5 w-5 animate-spin text-red-800" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                            </svg>
                                        </span>
                                    </button>
                                @endcan
                            </div>
                            <div class="flex justify-end border-t border-zinc-100 bg-zinc-50/80 px-6 py-5 sm:px-8 sm:py-6">
                                <button type="button" wire:click="closeCancelProvisionModal" class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-brand-ink hover:bg-zinc-50 sm:px-6">
                                    {{ __('Close') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @include('livewire.servers.partials.remove-server-modal', [
                'open' => $showRemoveServerModal,
                'serverName' => $server->name,
                'serverId' => $server->id,
                'deletionSummary' => $deletionSummary,
            ])
        </x-slot>
    </x-server-workspace-layout>
</div>
