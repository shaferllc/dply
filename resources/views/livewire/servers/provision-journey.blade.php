@php
    $card = 'dply-card overflow-hidden';
    $progressPercent = $totalCount > 0 ? (int) round(($completedCount / $totalCount) * 100) : 0;

    // Two-phase progress so the headline never reads "100%" until BOTH
    // cloud + setup are done. Without this, the single combined counter
    // jumps from "6/6" to "4/23" the moment the setup script dispatches
    // and 18 step labels populate retroactively — looks to the operator
    // like provisioning restarted from the beginning. Splitting into
    // separate bars keeps each phase honest.
    $cloudKeys = ['queued', 'provisioning', 'ip', 'ssh'];
    $cloudSteps = collect($steps)->whereIn('key', $cloudKeys);
    $cloudDone = $cloudSteps->where('state', 'completed')->count();
    $cloudTotal = $cloudSteps->count() ?: count($cloudKeys);
    $cloudPercent = $cloudTotal > 0 ? (int) round(($cloudDone / $cloudTotal) * 100) : 0;

    // Setup steps are anything between the cloud-side keys and the
    // final 'ready' step — script_* hashes once the bash body has been
    // parsed, OR the 'setup' placeholder pre-dispatch.
    $setupSteps = collect($steps)->whereNotIn('key', array_merge($cloudKeys, ['ready']));
    $setupDone = $setupSteps->where('state', 'completed')->count();
    $setupTotal = $setupSteps->count();
    $setupActive = $setupSteps->where('state', 'active')->isNotEmpty();
    $setupStarted = $setupActive || $setupDone > 0;
    $setupPercent = $setupTotal > 0 ? (int) round(($setupDone / $setupTotal) * 100) : 0;

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

    // Wall-clock elapsed for the journey hero. Anchors on the server row's
    // created_at so the timer reflects the operator's full wait, not just
    // the most recent task run. Frozen at setup completion; otherwise
    // refreshes on every wire:poll.
    $journeyAnchor = $server->created_at;
    $journeyEndpoint = ($server->setup_status === \App\Models\Server::SETUP_STATUS_DONE && $server->setup_completed_at)
        ? $server->setup_completed_at
        : now();
    $journeyElapsedSeconds = $journeyAnchor ? max(0, (int) $journeyAnchor->diffInSeconds($journeyEndpoint)) : 0;
    $journeyElapsedHuman = $journeyAnchor
        ? \Illuminate\Support\Carbon::createFromTimestamp(0)
            ->addSeconds($journeyElapsedSeconds)
            ->diffForHumans(\Illuminate\Support\Carbon::createFromTimestamp(0), [
                'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                'parts' => 2,
                'short' => true,
            ])
        : null;
@endphp

@once
    <style>
        /* Animated stripe sheen for an actively-progressing bar (e.g. cloud bar mid-fill).
           The fill keeps its solid colour; we overlay a translucent diagonal-stripe gradient
           and march it left→right at a steady tempo. */
        @keyframes dply-progress-stripes {
            0%   { background-position: 0 0; }
            100% { background-position: 1.25rem 0; }
        }
        .dply-progress-fill-active {
            background-image: linear-gradient(
                45deg,
                rgba(255, 255, 255, 0.28) 25%,
                transparent 25%,
                transparent 50%,
                rgba(255, 255, 255, 0.28) 50%,
                rgba(255, 255, 255, 0.28) 75%,
                transparent 75%,
                transparent
            );
            background-size: 1.25rem 1.25rem;
            animation: dply-progress-stripes 1s linear infinite;
        }

        /* Indeterminate marching highlight on an empty track — used while we're waiting on
           a phase handoff (e.g. cloud done, setup not yet dispatched) so the rail doesn't
           look stalled. A narrow translucent emerald sweeps left→right across the track. */
        @keyframes dply-progress-indeterminate {
            0%   { background-position: -40% 0; }
            100% { background-position: 140% 0; }
        }
        .dply-progress-track-indeterminate {
            background-image: linear-gradient(
                90deg,
                transparent 0%,
                rgba(5, 150, 105, 0.45) 50%,
                transparent 100%
            );
            background-repeat: no-repeat;
            background-size: 35% 100%;
            animation: dply-progress-indeterminate 1.6s ease-in-out infinite;
        }

        /* Reduce-motion: disable the marching animations but keep colour cues. */
        @media (prefers-reduced-motion: reduce) {
            .dply-progress-fill-active,
            .dply-progress-track-indeterminate {
                animation: none;
            }
        }
    </style>
@endonce

<div
    @if ($shouldPoll)
        {{-- Polling cadence + visibility gating to keep the journey
             page from thrashing when the operator has multiple tabs
             open or has switched away.
             - 10s base interval (was 5s — 2× headroom against Livewire
               request stacking with multiple tabs open)
             - .visible modifier uses IntersectionObserver, which both
               pauses the poll when the journey panel is scrolled out
               of view AND when the browser tab is hidden (browsers
               throttle IO entries on backgrounded tabs, so polling
               effectively stops within ~250ms of tab-switch). --}}
        wire:poll.10s.visible
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

            {{-- Orphaned fake-cloud server banner. Server was created
                 during a fake-cloud session that's now disabled, so its
                 stored ip_address (127.0.0.1) and ssh_port (2222) point
                 at a docker container that's likely not running. The
                 "100% complete" stats below are a stale artifact from
                 the fake-cloud routing that quick-marked steps done.
                 Operators can't recover this — the row has no real
                 provider credentials. Steer them to delete + recreate. --}}
            @if (! empty($isOrphanedFakeServer))
                <div class="col-span-full overflow-hidden rounded-2xl border-2 border-amber-300 bg-amber-50 shadow-sm">
                    <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-700" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold leading-snug text-amber-950">{{ __('This server is an orphan from a prior fake-cloud session') }}</p>
                            <p class="mt-1 text-sm leading-relaxed text-amber-950/90">
                                {{ __('It was created while DPLY_FAKE_CLOUD_PROVISION was enabled, so its IP (:ip) and SSH port (:port) point at a local docker container — not a real server. The "completed" tasks below are stale state from the fake-cloud routing.', [
                                    'ip' => $server->ip_address ?: '127.0.0.1',
                                    'port' => $server->ssh_port ?: 2222,
                                ]) }}
                            </p>
                            <p class="mt-2 text-sm leading-relaxed text-amber-950/90">
                                <strong>{{ __('Fix:') }}</strong>
                                {{ __('Remove this server row, then create a fresh one from /servers/create. The new row will provision against your real provider account.') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

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
                            @php
                                $autoRetryAt = isset($server->meta['auto_retry_at']) ? \Illuminate\Support\Carbon::parse($server->meta['auto_retry_at']) : null;
                                $autoRetryAttempt = $server->meta['auto_retry_attempt'] ?? null;
                                $autoRetryMax = $server->meta['auto_retry_max'] ?? null;
                                $autoRetryPending = $autoRetryAt && $autoRetryAt->isFuture();
                                $journeyHasFailed = $server->setup_status === \App\Models\Server::SETUP_STATUS_FAILED || $server->status === \App\Models\Server::STATUS_ERROR;
                                $journeyIsDone = $server->status === \App\Models\Server::STATUS_READY && $server->setup_status === \App\Models\Server::SETUP_STATUS_DONE;
                            @endphp
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Provision journey') }}</p>
                                @if ($journeyHasFailed)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-red-800 ring-1 ring-red-200">
                                        <x-heroicon-s-x-mark class="h-3 w-3" />
                                        {{ __('Failed') }}
                                    </span>
                                @elseif ($autoRetryPending)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
                                        <x-heroicon-o-arrow-path class="h-3 w-3" />
                                        {{ __('Retrying') }}
                                    </span>
                                @elseif ($journeyIsDone)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                        <x-heroicon-s-check class="h-3 w-3" />
                                        {{ __('Ready') }}
                                    </span>
                                @endif
                                @if ($totalCount > 0)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/60 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink/80 ring-1 ring-brand-ink/10" title="{{ __('Total steps completed across cloud + setup phases') }}">
                                        <x-heroicon-m-list-bullet class="h-3 w-3" />
                                        {{ __(':done / :total steps', ['done' => $completedCount, 'total' => $totalCount]) }}
                                    </span>
                                @endif
                                @if ($journeyElapsedHuman)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/60 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink/80 ring-1 ring-brand-ink/10" title="{{ $journeyIsDone ? __('Total provision time') : __('Time elapsed since this server row was created') }}">
                                        <x-heroicon-m-clock class="h-3 w-3" />
                                        {{ $journeyIsDone ? __('Took :elapsed', ['elapsed' => $journeyElapsedHuman]) : __('Elapsed :elapsed', ['elapsed' => $journeyElapsedHuman]) }}
                                    </span>
                                @endif
                            </div>
                            {{-- Headline reports per-phase progress so it doesn't jump
                                 backwards when the setup script dispatches and total
                                 step count grows. The combined :done/:total is still
                                 useful for log signatures internally; the operator
                                 sees phased numbers up here. --}}
                            <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">
                                @if ($setupStarted)
                                    {{ __('Server setup (:done/:total)', ['done' => $setupDone, 'total' => $setupTotal]) }}
                                @else
                                    {{ __('Cloud provisioning (:done/:total)', ['done' => $cloudDone, 'total' => $cloudTotal]) }}
                                @endif
                            </h2>
                            <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss">
                                @if ($journeyHasFailed)
                                    {{ __('Provisioning hit an error. Review the failure details below, then click Resume install — it re-runs the full script, but already-completed steps (installed packages, written configs) are detected and skipped quickly.') }}
                                @elseif ($autoRetryPending && $autoRetryAttempt && $autoRetryMax)
                                    {{ __('A transient failure was detected. Auto-retrying — attempt :n of :max, starting :when.', [
                                        'n' => $autoRetryAttempt,
                                        'max' => $autoRetryMax,
                                        'when' => $autoRetryAt->diffForHumans(),
                                    ]) }}
                                @else
                                    {{ __('Provisioning and stack setup update automatically here.') }}
                                @endif
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
                        {{-- Resume install only makes sense when setup
                             actually failed and is waiting for the operator
                             to retry. shouldDispatch was previously used
                             alone — but it returns true on DONE servers
                             too (its job is "can we kick off a setup?",
                             not "is one needed?"), causing the button to
                             render alongside Open server workspace on a
                             healthy completed server. Gating on FAILED
                             specifically is the actual intent. --}}
                        @if ($server->setup_status === \App\Models\Server::SETUP_STATUS_FAILED && \App\Jobs\RunSetupScriptJob::shouldDispatch($server))
                            <button
                                type="button"
                                wire:click="openResumeInstallModal"
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

                    <div class="space-y-4">
                        {{-- Phase 1: cloud-side provisioning (DO API → IP → SSH up) --}}
                        <div>
                            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                <span class="inline-flex items-center gap-2 text-sm font-medium text-brand-ink">
                                    <x-heroicon-m-cloud class="h-4 w-4 text-brand-moss" />
                                    {{ __('Cloud provisioning') }}
                                </span>
                                <span class="text-sm tabular-nums text-brand-moss">{{ __(':done of :total', ['done' => $cloudDone, 'total' => $cloudTotal]) }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="dply-progress-track h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-brand-sand/80 {{ $cloudPercent > 0 && $cloudPercent < 100 ? 'dply-progress-track-idle' : '' }}">
                                    <div class="dply-progress-fill h-full rounded-full bg-sky-600 transition-[width] duration-300 {{ $cloudPercent > 0 && $cloudPercent < 100 ? 'dply-progress-fill-active' : '' }}" style="width: {{ $cloudPercent }}%"></div>
                                </div>
                                <span class="shrink-0 text-sm font-semibold tabular-nums text-sky-700">{{ $cloudPercent }}%</span>
                            </div>
                        </div>

                        {{-- Phase 2: server setup (the 18-step installer running on the droplet via SSH).
                             "Pending" until the cloud phase finishes + the setup script dispatches; once
                             the script is in flight, the bar fills from 0 → 100 of N script steps. --}}
                        <div>
                            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                <span class="inline-flex items-center gap-2 text-sm font-medium text-brand-ink">
                                    <x-heroicon-m-wrench-screwdriver class="h-4 w-4 text-brand-moss" />
                                    {{ __('Server setup') }}
                                </span>
                                @if ($setupStarted)
                                    <span class="text-sm tabular-nums text-brand-moss">{{ __(':done of :total', ['done' => $setupDone, 'total' => $setupTotal]) }}</span>
                                @else
                                    <span class="text-sm tabular-nums text-brand-mist">{{ __('Waiting for cloud phase') }}</span>
                                @endif
                            </div>
                            @php
                                // While we're waiting on the cloud→setup handoff (cloud at 100%, setup not yet
                                // started) the empty tan rail looked stalled. Render an indeterminate marching
                                // sheen on the *track* so the user sees movement during the dispatch gap.
                                $setupIndeterminate = ! $setupStarted && $cloudPercent >= 100;
                                $setupActive = $setupStarted && $setupPercent < 100;
                            @endphp
                            <div class="flex items-center gap-3">
                                <div class="dply-progress-track h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-brand-sand/80 {{ $setupIndeterminate ? 'dply-progress-track-indeterminate' : ($setupActive ? 'dply-progress-track-idle' : '') }}">
                                    <div class="dply-progress-fill h-full rounded-full bg-emerald-600 transition-[width] duration-300 {{ $setupActive ? 'dply-progress-fill-active' : '' }}" style="width: {{ $setupStarted ? $setupPercent : 0 }}%"></div>
                                </div>
                                <span class="shrink-0 text-sm font-semibold tabular-nums {{ $setupStarted ? 'text-brand-forest' : 'text-brand-mist' }}">{{ $setupStarted ? $setupPercent.'%' : '—' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-6 px-5 py-6 sm:px-8 sm:py-8">
                    @if ($failedStep)
                        <div class="rounded-2xl border-2 border-red-300 bg-red-50/95 px-5 py-5 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600 text-white">
                                            <x-heroicon-s-x-mark class="h-4 w-4" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-base font-semibold text-red-900 sm:text-lg">{{ __('Provisioning failed at: :step', ['step' => $failedStep['label']]) }}</p>
                                            @if ($failureReason)
                                                @php
                                                    $reasonClipboard = $failureReason['headline'];
                                                    if ($failureReason['exit_code'] !== null) {
                                                        $reasonClipboard = '[exit '.$failureReason['exit_code'].'] '.$reasonClipboard;
                                                    }
                                                    if (count($failureReason['context']) > 1) {
                                                        $reasonClipboard .= "\n\n".implode("\n", $failureReason['context']);
                                                    }
                                                @endphp
                                                <div class="mt-2 rounded-xl border border-red-300 bg-white/80 px-4 py-3">
                                                    <div class="flex items-start justify-between gap-3">
                                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-red-700">
                                                            {{ __('Reason') }}
                                                            @if ($failureReason['exit_code'] !== null)
                                                                <span class="ml-1 font-normal normal-case text-red-600/80">· {{ __('exit code :code', ['code' => $failureReason['exit_code']]) }}</span>
                                                            @endif
                                                        </p>
                                                        <button
                                                            type="button"
                                                            x-data="{ copied: false }"
                                                            x-on:click="navigator.clipboard.writeText(@js($reasonClipboard)); copied = true; setTimeout(() => copied = false, 1500)"
                                                            class="shrink-0 rounded-md border border-red-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700 hover:border-red-300 hover:bg-red-50"
                                                        >
                                                            <span x-show="!copied">{{ __('Copy') }}</span>
                                                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                                        </button>
                                                    </div>
                                                    <p class="mt-1 break-words font-mono text-sm leading-6 text-red-900">{{ $failureReason['headline'] }}</p>
                                                    @if (count($failureReason['context']) > 1)
                                                        <details class="mt-2">
                                                            <summary class="cursor-pointer list-none text-[11px] font-semibold uppercase tracking-wide text-red-700">
                                                                <span class="inline-flex items-center gap-1.5">
                                                                    <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                                                                    {{ __('Last :n lines', ['n' => count($failureReason['context'])]) }}
                                                                </span>
                                                            </summary>
                                                            <pre class="mt-2 whitespace-pre-wrap break-all font-mono text-[11px] leading-5 text-red-900">{{ implode("\n", $failureReason['context']) }}</pre>
                                                        </details>
                                                    @endif
                                                </div>
                                            @else
                                                <p class="mt-1 text-sm leading-6 text-red-800">{{ $failedStep['detail'] ?: __('The setup script aborted before this step finished. The server is in an unknown state — review the captured output and the rollback summary below before retrying.') }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    @if ($rollbackSummary)
                                        <div class="mt-4 rounded-xl border border-red-200 bg-white/80 p-4">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <p class="text-xs font-semibold uppercase tracking-wide text-red-700">{{ __('Automatic rollback') }}</p>
                                                    @if ($rollbackSummary['triggered'])
                                                        <p class="mt-1 text-sm font-medium text-red-900">
                                                            @if ($rollbackSummary['total'] > 0)
                                                                {{ __('Rollback ran and reverted :count change(s) on the server.', ['count' => $rollbackSummary['total']]) }}
                                                            @else
                                                                {{ __('Rollback ran. No file changes needed reverting (failure happened before any backups were recorded).') }}
                                                            @endif
                                                        </p>
                                                    @else
                                                        <p class="mt-1 text-sm text-amber-800">{{ __('No rollback marker was emitted — the failure may have skipped the trap. Server state is uncertain.') }}</p>
                                                    @endif
                                                </div>
                                                @if ($rollbackSummary['triggered'])
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-700 ring-1 ring-emerald-200">
                                                        <x-heroicon-m-check-circle class="h-3.5 w-3.5" />
                                                        {{ __('Restored') }}
                                                    </span>
                                                @endif
                                            </div>

                                            @if ($rollbackSummary['restored'] !== [] || $rollbackSummary['removed'] !== [])
                                                <details class="mt-3">
                                                    <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-red-700">
                                                        <span class="inline-flex items-center gap-1.5">
                                                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                                                            {{ __('What was reverted') }}
                                                            <span class="font-normal text-red-600/80">
                                                                ·
                                                                @if ($rollbackSummary['restored'] !== []) {{ __(':n restored', ['n' => count($rollbackSummary['restored'])]) }}@endif
                                                                @if ($rollbackSummary['restored'] !== [] && $rollbackSummary['removed'] !== []) , @endif
                                                                @if ($rollbackSummary['removed'] !== []) {{ __(':n removed', ['n' => count($rollbackSummary['removed'])]) }}@endif
                                                            </span>
                                                        </span>
                                                    </summary>
                                                    <ul class="mt-2 space-y-1 text-xs leading-5 text-red-900">
                                                        @foreach ($rollbackSummary['restored'] as $path)
                                                            <li class="flex items-start gap-2">
                                                                <x-heroicon-m-arrow-uturn-left class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                                                                <code class="break-all font-mono text-[11px]">/{{ $path }}</code>
                                                                <span class="text-[10px] uppercase tracking-wide text-emerald-700">{{ __('restored') }}</span>
                                                            </li>
                                                        @endforeach
                                                        @foreach ($rollbackSummary['removed'] as $path)
                                                            <li class="flex items-start gap-2">
                                                                <x-heroicon-m-trash class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600" />
                                                                <code class="break-all font-mono text-[11px]">/{{ $path }}</code>
                                                                <span class="text-[10px] uppercase tracking-wide text-amber-700">{{ __('removed') }}</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($failedStep['output'])
                                        <div x-data="{ open: false, copied: false }" class="mt-4 overflow-hidden rounded-xl border border-red-200/70 bg-slate-950 shadow-inner">
                                            <div class="flex items-center justify-between gap-3 border-b border-white/5 bg-red-950/50 px-4 py-2.5">
                                                <button
                                                    type="button"
                                                    x-on:click="open = !open"
                                                    class="flex flex-1 items-center justify-between gap-3 text-[11px] font-semibold uppercase tracking-wider text-red-300"
                                                >
                                                    <span>{{ __('Captured step output') }}</span>
                                                    <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                                                </button>
                                                <button
                                                    type="button"
                                                    x-on:click.stop="navigator.clipboard.writeText(@js($failedStep['output'])); copied = true; setTimeout(() => copied = false, 1500)"
                                                    class="shrink-0 rounded-md border border-white/10 bg-slate-800/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-200 hover:bg-slate-700/80"
                                                >
                                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                                    <span x-show="copied" x-cloak class="text-emerald-300">{{ __('Copied') }}</span>
                                                </button>
                                            </div>
                                            <pre x-show="open" x-cloak class="max-h-96 overflow-auto whitespace-pre-wrap break-words px-4 py-3 font-mono text-[12px] leading-5 text-red-200 selection:bg-emerald-500/30">{{ $failedStep['output'] }}</pre>
                                        </div>
                                    @endif
                                </div>
                                <span class="shrink-0 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-red-800">{{ __('Failed') }}</span>
                            </div>
                        </div>
                    @elseif ($activeStep)
                        <div class="rounded-2xl border border-sky-200/80 bg-gradient-to-br from-sky-50/95 to-white px-4 py-4 sm:px-5">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="inline-flex h-7 w-7 animate-spin items-center justify-center rounded-full border-[3px] border-sky-200 border-t-sky-600" aria-hidden="true"></span>
                                            <p class="text-base font-semibold text-brand-ink sm:text-lg">{{ $activeStep['label'] }}</p>
                                        </div>
                                        @if ($activeStep['duration'])
                                            <span class="shrink-0 text-sm font-medium text-brand-moss">{{ $activeStep['duration'] }}</span>
                                        @endif
                                    </div>
                                    @if ($activeStep['detail'])
                                        @php
                                            // Script steps (key prefixed `script_`) and the
                                            // 'setup' placeholder render the bash tail as
                                            // their detail — that's terminal output and
                                            // belongs in a code block. Non-script steps
                                            // (queued / provisioning / ip / ssh / ready)
                                            // carry descriptive prose like "IP assigned:
                                            // 138.x.y.z" and stay as a regular paragraph.
                                            $isStreamingDetail = str_starts_with($activeStep['key'] ?? '', 'script_') || ($activeStep['key'] ?? '') === 'setup';
                                        @endphp
                                        @if ($isStreamingDetail)
                                            <pre class="mt-3 max-h-32 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-brand-ink/10 bg-slate-950 px-3 py-2 font-mono text-[12px] leading-5 text-slate-200 selection:bg-emerald-500/30">{{ $activeStep['detail'] }}</pre>
                                        @else
                                            <p class="mt-3 text-sm leading-6 text-brand-moss whitespace-pre-line">{{ $activeStep['detail'] }}</p>
                                        @endif
                                    @endif
                                    @if ($stallState)
                                        <div class="mt-3 rounded-xl border border-brand-ink/10 bg-white/90 p-4">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Run timing') }}</p>
                                            <p class="mt-2 flex flex-wrap items-baseline gap-2 text-sm text-brand-ink">
                                                <span>{{ $stallState['eta'] }}</span>
                                                @if (! empty($stallState['eta_samples']))
                                                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-ink/80 ring-1 ring-brand-ink/10" title="{{ __('Computed from your previous server provisions for this org.') }}">
                                                        {{ trans_choice('from :count previous run|from :count previous runs', $stallState['eta_samples'], ['count' => $stallState['eta_samples']]) }}
                                                    </span>
                                                @endif
                                            </p>
                                            <p class="mt-1 text-sm text-brand-moss">{{ $stallState['running_for'] }}</p>
                                            @if ($stallState['last_output'])
                                                <p class="mt-1 text-sm text-brand-moss">{{ $stallState['last_output'] }}</p>
                                            @endif
                                            @if ($stallState['warning'])
                                                <p class="mt-2 text-sm font-medium text-amber-700">{{ $stallState['warning'] }}</p>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($activeStep['output'])
                                        {{-- Collapsed by default. The streaming output
                                             can be visually noisy (apt fetch chatter,
                                             dpkg progress) and most operators only
                                             want to peek at it when something looks
                                             off. Use <details>; the auto-scroll wiring
                                             on the pre still works because Alpine's
                                             x-effect re-fires every poll as long as
                                             the details is open.
                                             wire:key keeps Livewire from re-creating
                                             the element across polls; @toggle pins
                                             open-state in Alpine so morphdom doesn't
                                             reset it. --}}
                                        <details
                                            wire:key="active-step-output"
                                            wire:ignore.self
                                            x-data="{ open: false, copied: false, copy() { navigator.clipboard?.writeText(this.$refs.pre.textContent); this.copied = true; clearTimeout(this._t); this._t = setTimeout(() => this.copied = false, 1500); } }"
                                            x-bind:open="open"
                                            @toggle.stop="open = $event.target.open; open && $nextTick(() => $refs.pre.scrollTop = $refs.pre.scrollHeight)"
                                            class="group mt-4 overflow-hidden rounded-xl border border-brand-ink/10 bg-slate-950 shadow-inner"
                                        >
                                            <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-white/5 bg-slate-900/80 px-4 py-2.5">
                                                <span class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                    <x-heroicon-o-chevron-right class="h-3.5 w-3.5 transition-transform group-open:rotate-90" />
                                                    {{ __('Live step output') }}
                                                </span>
                                                <div class="flex items-center gap-2">
                                                    @if ($taskUpdatedAt)
                                                        <p class="text-[11px] text-slate-500">{{ __('updated :ago', ['ago' => $taskUpdatedAt->diffForHumans()]) }}</p>
                                                    @endif
                                                    <button type="button" x-on:click.stop.prevent="copy()" class="inline-flex items-center gap-1 rounded-md border border-white/10 bg-slate-800/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-200 shadow-sm transition hover:bg-slate-700/80">
                                                        <template x-if="!copied"><span class="inline-flex items-center gap-1"><x-heroicon-o-clipboard class="h-3 w-3" />{{ __('Copy') }}</span></template>
                                                        <template x-if="copied"><span class="inline-flex items-center gap-1 text-emerald-300"><x-heroicon-m-check class="h-3 w-3" />{{ __('Copied') }}</span></template>
                                                    </button>
                                                </div>
                                            </summary>
                                            <pre
                                                x-ref="pre"
                                                x-effect="open && $nextTick(() => $refs.pre.scrollTop = $refs.pre.scrollHeight)"
                                                class="max-h-96 overflow-auto whitespace-pre-wrap break-words px-4 py-3 font-mono text-[12px] leading-5 text-slate-200 selection:bg-emerald-500/30"
                                            >{{ $activeStep['output'] }}</pre>
                                        </details>
                                    @endif
                                    @if ($task && $liveTaskOutput && $activeStep['output'])
                                        {{-- Step-specific output is the primary view; offer the raw task tail
                                             behind a toggle for users who want the full firehose. --}}
                                        <details
                                            x-data="{ copied: false, copy() { navigator.clipboard?.writeText(this.$refs.pre.textContent); this.copied = true; clearTimeout(this._t); this._t = setTimeout(() => this.copied = false, 1500); } }"
                                            class="group mt-4 overflow-hidden rounded-xl border border-brand-ink/10 bg-slate-950 shadow-inner"
                                        >
                                            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3 border-b border-white/5 bg-slate-900/80 px-4 py-2.5">
                                                <span class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                    <x-heroicon-o-chevron-right class="h-3.5 w-3.5 transition-transform group-open:rotate-90" />
                                                    {{ __('Show full task tail') }}
                                                </span>
                                                <span class="flex items-center gap-2 text-[11px] text-slate-500">
                                                    <span>
                                                        {{ __(':count lines', ['count' => $liveTaskOutputLineCount]) }}
                                                        @if ($taskUpdatedAt)
                                                            · {{ __('updated :ago', ['ago' => $taskUpdatedAt->diffForHumans()]) }}
                                                        @endif
                                                    </span>
                                                    <button type="button" x-on:click.stop.prevent="copy()" class="inline-flex items-center gap-1 rounded-md border border-white/10 bg-slate-800/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-200 shadow-sm transition hover:bg-slate-700/80">
                                                        <template x-if="!copied"><span class="inline-flex items-center gap-1"><x-heroicon-o-clipboard class="h-3 w-3" />{{ __('Copy') }}</span></template>
                                                        <template x-if="copied"><span class="inline-flex items-center gap-1 text-emerald-300"><x-heroicon-m-check class="h-3 w-3" />{{ __('Copied') }}</span></template>
                                                    </button>
                                                </span>
                                            </summary>
                                            <pre
                                                x-ref="pre"
                                                x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                                                x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                                                class="max-h-96 overflow-auto whitespace-pre-wrap break-words px-4 py-3 font-mono text-[12px] leading-5 text-slate-200 selection:bg-emerald-500/30"
                                            >{{ $liveTaskOutput }}</pre>
                                        </details>
                                    @elseif ($task && $liveTaskOutput)
                                        {{-- No step-specific output yet — collapsed
                                             tail is still the operator's escape hatch
                                             when the bootstrap script hasn't emitted
                                             [dply-step] markers yet. --}}
                                        <details
                                            wire:key="live-task-tail"
                                            wire:ignore.self
                                            x-data="{ open: false, copied: false, copy() { navigator.clipboard?.writeText(this.$refs.pre.textContent); this.copied = true; clearTimeout(this._t); this._t = setTimeout(() => this.copied = false, 1500); } }"
                                            x-bind:open="open"
                                            @toggle.stop="open = $event.target.open; open && $nextTick(() => $refs.pre.scrollTop = $refs.pre.scrollHeight)"
                                            class="group mt-4 overflow-hidden rounded-xl border border-brand-ink/10 bg-slate-950 shadow-inner"
                                        >
                                            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3 border-b border-white/5 bg-slate-900/80 px-4 py-2.5">
                                                <span class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                    <x-heroicon-o-chevron-right class="h-3.5 w-3.5 transition-transform group-open:rotate-90" />
                                                    {{ __('Live task output (tail)') }}
                                                </span>
                                                <div class="flex items-center gap-2">
                                                    <p class="text-[11px] text-slate-500">
                                                        {{ __(':count lines', ['count' => $liveTaskOutputLineCount]) }}
                                                        @if ($taskUpdatedAt)
                                                            · {{ __('updated :ago', ['ago' => $taskUpdatedAt->diffForHumans()]) }}
                                                        @endif
                                                    </p>
                                                    <button type="button" x-on:click.stop.prevent="copy()" class="inline-flex items-center gap-1 rounded-md border border-white/10 bg-slate-800/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-200 shadow-sm transition hover:bg-slate-700/80">
                                                        <template x-if="!copied"><span class="inline-flex items-center gap-1"><x-heroicon-o-clipboard class="h-3 w-3" />{{ __('Copy') }}</span></template>
                                                        <template x-if="copied"><span class="inline-flex items-center gap-1 text-emerald-300"><x-heroicon-m-check class="h-3 w-3" />{{ __('Copied') }}</span></template>
                                                    </button>
                                                </div>
                                            </summary>
                                            <pre
                                                x-ref="pre"
                                                x-effect="open && $nextTick(() => $refs.pre.scrollTop = $refs.pre.scrollHeight)"
                                                class="max-h-96 overflow-auto whitespace-pre-wrap break-words px-4 py-3 font-mono text-[12px] leading-5 text-slate-200 selection:bg-emerald-500/30"
                                            >{{ $liveTaskOutput }}</pre>
                                        </details>
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
                            </div>
                        </div>
                    @endif

                    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-sand/10">
                        {{--
                            Disclosure state is owned by Alpine, NOT the
                            server. The earlier `@if(...) open @endif`
                            attribute was re-emitted every Livewire poll
                            and the morph applied it on top of whatever
                            the user had toggled — so any manual collapse
                            bounced back open (or vice-versa) every 10s.

                            Two pieces fix this:
                              1. wire:ignore.self — Livewire's morpher
                                 skips THIS element's attributes during
                                 the patch, but still walks its children
                                 (so the step list updates normally).
                              2. Alpine `:open` + @toggle — the open
                                 boolean lives in Alpine state initialised
                                 from the server only on first render,
                                 then mutated locally as the user clicks.
                            wire:key gives Livewire a stable identity so
                            the element is never replaced wholesale.
                        --}}
                        <details
                            wire:key="provision-pending-disclosure"
                            wire:ignore.self
                            x-data="{ open: @js($pendingSteps->isNotEmpty()) }"
                            x-bind:open="open"
                            @toggle.stop="open = $event.target.open"
                            class="group border-b border-brand-ink/10 last:border-b-0"
                        >
                            <summary class="cursor-pointer list-none px-4 py-3.5 sm:px-5">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-semibold text-brand-ink">{{ __('Up next (:count)', ['count' => $pendingSteps->count()]) }}</span>
                                    <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition-transform group-open:rotate-180" />
                                </div>
                            </summary>
                            @if ($pendingSteps->isNotEmpty())
                                <ul class="space-y-0 border-t border-brand-ink/10 bg-white/80 px-4 py-2 sm:px-5">
                                    @foreach ($pendingSteps as $step)
                                        @php
                                            $stepEta = $step['eta'] ?? null;
                                            $stepEtaSeconds = is_array($stepEta) ? (int) ($stepEta['seconds'] ?? 0) : 0;
                                            $stepEtaSamples = is_array($stepEta) ? (int) ($stepEta['samples'] ?? 0) : 0;
                                            $stepEtaLabel = null;
                                            if ($stepEtaSeconds > 0) {
                                                if ($stepEtaSeconds < 60) {
                                                    $stepEtaLabel = '~'.$stepEtaSeconds.'s';
                                                } else {
                                                    $minutes = intdiv($stepEtaSeconds, 60);
                                                    $remainder = $stepEtaSeconds % 60;
                                                    $stepEtaLabel = $minutes < 10 && $remainder > 0
                                                        ? sprintf('~%dm %ds', $minutes, $remainder)
                                                        : '~'.$minutes.'m';
                                                }
                                            }
                                        @endphp
                                        <li class="flex gap-3 border-b border-brand-ink/5 py-3 last:border-b-0">
                                            <span class="mt-1.5 inline-flex h-2 w-2 shrink-0 rounded-full bg-brand-mist ring-4 ring-brand-sand/40" aria-hidden="true"></span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center justify-between gap-3">
                                                    <p class="text-sm font-medium text-brand-ink">{{ $step['label'] }}</p>
                                                    @if ($stepEtaLabel)
                                                        <span class="shrink-0 rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-ink/80 ring-1 ring-brand-ink/10" title="{{ trans_choice('Avg from :count previous run|Avg from :count previous runs', $stepEtaSamples, ['count' => $stepEtaSamples]) }}">
                                                            {{ $stepEtaLabel }}
                                                        </span>
                                                    @endif
                                                </div>
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

                        <details
                            wire:key="provision-completed-disclosure"
                            wire:ignore.self
                            x-data="{ open: @js($completedSteps->isNotEmpty() && ($pendingSteps->isEmpty() || $allStepsDone)) }"
                            x-bind:open="open"
                            @toggle.stop="open = $event.target.open"
                            class="group"
                        >
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
                                                    <details class="group mt-2 overflow-hidden rounded-lg border border-brand-ink/10 bg-slate-950 shadow-inner">
                                                        <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-white/5 bg-slate-900/80 px-3 py-2">
                                                            <span class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                                <x-heroicon-o-chevron-right class="h-3.5 w-3.5 transition-transform group-open:rotate-90" />
                                                                {{ __('Output') }}
                                                            </span>
                                                        </summary>
                                                        <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-words px-3 py-2 font-mono text-[12px] leading-5 text-slate-200 selection:bg-emerald-500/30">{{ $step['output'] }}</pre>
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
                @php
                    $isFullyReady = $server->status === \App\Models\Server::STATUS_READY
                        && $server->setup_status === \App\Models\Server::SETUP_STATUS_DONE;
                    $statusBadge = match (true) {
                        $server->status === \App\Models\Server::STATUS_ERROR => 'bg-red-100 text-red-900 ring-red-300',
                        $isFullyReady => 'bg-emerald-100 text-emerald-900 ring-emerald-300',
                        $server->status === \App\Models\Server::STATUS_READY => 'bg-amber-100 text-amber-900 ring-amber-300', // setup still in flight
                        $server->status === \App\Models\Server::STATUS_PROVISIONING => 'bg-amber-100 text-amber-900 ring-amber-300',
                        $server->status === \App\Models\Server::STATUS_PENDING => 'bg-sky-100 text-sky-900 ring-sky-300',
                        default => 'bg-brand-sand text-brand-ink ring-brand-ink/15',
                    };
                    $statusLabel = ($server->status === \App\Models\Server::STATUS_READY && ! $isFullyReady)
                        ? __('Provisioning')
                        : ucfirst((string) $server->status);
                    $setupBadge = match ($server->setup_status) {
                        \App\Models\Server::SETUP_STATUS_DONE => 'bg-emerald-100 text-emerald-900 ring-emerald-300',
                        \App\Models\Server::SETUP_STATUS_FAILED => 'bg-red-100 text-red-900 ring-red-300',
                        \App\Models\Server::SETUP_STATUS_RUNNING => 'bg-amber-100 text-amber-900 ring-amber-300',
                        \App\Models\Server::SETUP_STATUS_PENDING => 'bg-sky-100 text-sky-900 ring-sky-300',
                        default => 'bg-brand-sand text-brand-ink ring-brand-ink/15',
                    };
                @endphp
                <section class="{{ $card }} p-5 sm:p-6" x-data="{ copied: false }">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Server summary') }}</h3>
                    <dl class="mt-4 grid grid-cols-1 gap-x-4 gap-y-4 text-sm sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $statusBadge }}">
                                    {{ $statusLabel }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Provider') }}</dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center rounded-md border border-brand-ink/12 bg-brand-sand/30 px-2 py-0.5 text-xs font-semibold text-brand-ink">
                                    {{ $server->provider->label() }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                            <dd class="mt-1">
                                <code class="rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-xs text-brand-ink">{{ $server->region ?: '—' }}</code>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Size') }}</dt>
                            <dd class="mt-1">
                                <code class="break-all rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-xs text-brand-ink">{{ $server->size ?: '—' }}</code>
                            </dd>
                        </div>
                        @if ($server->setup_status)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Setup') }}</dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $setupBadge }}">
                                        {{ ucfirst(str_replace('_', ' ', (string) $server->setup_status)) }}
                                    </span>
                                </dd>
                            </div>
                        @endif
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('IP address') }}</dt>
                            <dd class="mt-1 flex min-w-0 items-center gap-2">
                                <code class="min-w-0 flex-1 truncate rounded-md bg-brand-sand/40 px-2 py-1 font-mono text-xs text-brand-ink">
                                    {{ $server->ip_address ?: '—' }}
                                </code>
                                @if ($server->ip_address)
                                    <button type="button"
                                        title="{{ __('Copy IP') }}"
                                        @click.prevent="navigator.clipboard.writeText(@js($server->ip_address)); copied = true; setTimeout(() => copied = false, 1500)"
                                        class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold text-brand-moss hover:bg-brand-sand/40">
                                        <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                                    </button>
                                @endif
                            </dd>
                        </div>
                    </dl>
                    @can('delete', $server)
                        <div class="mt-6 border-t border-brand-ink/10 pt-4">
                            <p class="text-xs leading-relaxed text-brand-moss">
                                {{ __('If the machine is gone or you want to abandon this install, remove the server record from Dply (and tear down linked cloud resources when applicable).') }}
                            </p>
                            <button
                                type="button"
                                wire:click="openRemoveServerModal"
                                class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-red-300 bg-red-50 px-4 py-2 text-sm font-semibold text-red-800 shadow-sm transition-colors hover:border-red-400 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-300 focus:ring-offset-2 sm:w-auto"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                {{ __('Remove or schedule removal') }}
                            </button>
                        </div>
                    @endcan
                </section>

                @if (app()->environment('local'))
                    @php
                        $tid = $server->meta['provision_task_id'] ?? null;
                        $rid = $server->meta['provision_run_id'] ?? null;
                        $taskStatusValue = $task?->status->value;
                        // Colour-coded chip per TaskRunner status so a quick glance tells
                        // you whether the bash provision is still running, succeeded, or
                        // failed without parsing the word.
                        $taskStatusBadge = match ($taskStatusValue) {
                            'running' => 'bg-amber-100 text-amber-900 ring-amber-300',
                            'completed', 'succeeded', 'success' => 'bg-emerald-100 text-emerald-900 ring-emerald-300',
                            'failed', 'errored' => 'bg-red-100 text-red-900 ring-red-300',
                            'cancelled', 'canceled' => 'bg-slate-100 text-slate-700 ring-slate-300',
                            'queued', 'pending' => 'bg-sky-100 text-sky-900 ring-sky-300',
                            default => 'bg-brand-sand text-brand-ink ring-brand-ink/15',
                        };
                    @endphp
                    <section class="{{ $card }} p-6" x-data="{ copied: null }">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Setup diagnostics') }}</h3>
                            <span class="text-[10px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('Local env') }}</span>
                        </div>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('Structured entries are written to the app log with keys like server.provision.* — filter logs by server_id or grep server.provision.') }}
                        </p>
                        <dl class="mt-4 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 bg-brand-sand/20 text-sm">
                            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Server ID') }}</dt>
                                <dd class="flex min-w-0 items-center gap-2">
                                    <code class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink">{{ $server->id }}</code>
                                    <button type="button"
                                        title="{{ __('Copy') }}"
                                        @click.prevent="navigator.clipboard.writeText(@js((string) $server->id)); copied = 'server'; setTimeout(() => copied = null, 1500)"
                                        class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold text-brand-moss hover:bg-brand-sand/40">
                                        <span x-text="copied === 'server' ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                                    </button>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Provision task') }}</dt>
                                <dd class="flex min-w-0 items-center gap-2">
                                    @if ($tid)
                                        <code class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink">{{ $tid }}</code>
                                        <button type="button"
                                            title="{{ __('Copy') }}"
                                            @click.prevent="navigator.clipboard.writeText(@js((string) $tid)); copied = 'task'; setTimeout(() => copied = null, 1500)"
                                            class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold text-brand-moss hover:bg-brand-sand/40">
                                            <span x-text="copied === 'task' ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                                        </button>
                                    @else
                                        <span class="text-brand-mist">—</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Provision run') }}</dt>
                                <dd class="flex min-w-0 items-center gap-2">
                                    @if ($rid)
                                        <code class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink">{{ $rid }}</code>
                                        <button type="button"
                                            title="{{ __('Copy') }}"
                                            @click.prevent="navigator.clipboard.writeText(@js((string) $rid)); copied = 'run'; setTimeout(() => copied = null, 1500)"
                                            class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold text-brand-moss hover:bg-brand-sand/40">
                                            <span x-text="copied === 'run' ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                                        </button>
                                    @else
                                        <span class="text-brand-mist">—</span>
                                    @endif
                                </dd>
                            </div>
                            @if ($task)
                                <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('TaskRunner status') }}</dt>
                                    <dd>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $taskStatusBadge }}">
                                            {{ $taskStatusValue }}
                                        </span>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </section>
                @endif

                @if (app()->environment('local') && ! empty($localDevShellHints))
                    @php
                        $hints = $localDevShellHints;
                    @endphp
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

                {{-- The "Setup task" sidebar panel (status / started / Recent
                     output preview + fullscreen modal) was removed: the journey
                     section above already shows live status, elapsed time, and
                     the dark-terminal Live step output / task tail — keeping a
                     duplicate light-styled preview just confused the page. --}}

                @if ($run)
                    @php
                        // Map run-level statuses to colour-coded ring chips so a glance
                        // tells you whether the bash provision is still in flight,
                        // succeeded, or failed.
                        $runStatusBadge = match ($run->status) {
                            'running' => 'bg-amber-100 text-amber-900 ring-amber-300',
                            'completed', 'succeeded' => 'bg-emerald-100 text-emerald-900 ring-emerald-300',
                            'failed', 'errored' => 'bg-red-100 text-red-900 ring-red-300',
                            'rolled_back' => 'bg-slate-100 text-slate-700 ring-slate-300',
                            'cancelled', 'canceled' => 'bg-slate-100 text-slate-700 ring-slate-300',
                            default => 'bg-brand-sand text-brand-ink ring-brand-ink/15',
                        };
                        $rollbackBadge = match ($run->rollback_status) {
                            'pending' => 'bg-amber-100 text-amber-900 ring-amber-300',
                            'running' => 'bg-amber-100 text-amber-900 ring-amber-300',
                            'completed' => 'bg-emerald-100 text-emerald-900 ring-emerald-300',
                            'failed' => 'bg-red-100 text-red-900 ring-red-300',
                            default => 'bg-brand-sand text-brand-ink ring-brand-ink/15',
                        };

                        // Wall-clock duration: start → end if terminal, else start → now.
                        $runStartedAt = $run->started_at ?? $run->created_at;
                        $runEndedAt = $run->completed_at;
                        $runDurationSeconds = $runStartedAt
                            ? (int) abs($runStartedAt->diffInSeconds($runEndedAt ?? now(), true))
                            : null;
                        $runDurationHuman = $runDurationSeconds === null
                            ? null
                            : \Illuminate\Support\Carbon::createFromTimestamp(0)
                                ->addSeconds($runDurationSeconds)
                                ->diffForHumans(\Illuminate\Support\Carbon::createFromTimestamp(0), [
                                    'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                                    'parts' => 2,
                                    'short' => true,
                                ]);

                        $artifactCount = $run->artifacts ? $run->artifacts->count() : 0;
                        $isTerminal = in_array($run->status, ['completed', 'succeeded', 'failed', 'errored', 'rolled_back', 'cancelled', 'canceled'], true);
                    @endphp
                    <section class="{{ $card }} p-6">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Provision run') }}</h3>
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $runStatusBadge }}">
                                {{ ucfirst(str_replace('_', ' ', (string) $run->status)) }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('A single execution of the bash provision script. Each retry creates a new attempt.') }}
                        </p>

                        <dl class="mt-4 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 bg-brand-sand/15 text-sm">
                            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Attempt') }}</dt>
                                <dd class="font-mono text-sm font-semibold text-brand-ink">#{{ $run->attempt }}</dd>
                            </div>
                            @if ($runStartedAt)
                                <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Started') }}</dt>
                                    <dd class="text-sm text-brand-ink">
                                        <time datetime="{{ $runStartedAt->toIso8601String() }}" title="{{ $runStartedAt->timezone(config('app.timezone'))->toDayDateTimeString() }}">
                                            {{ $runStartedAt->diffForHumans() }}
                                        </time>
                                    </dd>
                                </div>
                            @endif
                            @if ($runDurationHuman !== null)
                                <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ $isTerminal ? __('Total time') : __('Running for') }}</dt>
                                    <dd class="font-mono text-sm text-brand-ink">{{ $runDurationHuman }}</dd>
                                </div>
                            @endif
                            @if ($runEndedAt)
                                <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Finished') }}</dt>
                                    <dd class="text-sm text-brand-ink">
                                        <time datetime="{{ $runEndedAt->toIso8601String() }}" title="{{ $runEndedAt->timezone(config('app.timezone'))->toDayDateTimeString() }}">
                                            {{ $runEndedAt->diffForHumans() }}
                                        </time>
                                    </dd>
                                </div>
                            @endif
                            @if ($run->rollback_status)
                                <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Rollback') }}</dt>
                                    <dd>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $rollbackBadge }}">
                                            {{ str_replace('_', ' ', ucfirst($run->rollback_status)) }}
                                        </span>
                                    </dd>
                                </div>
                            @endif
                            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Artifacts') }}</dt>
                                <dd class="text-sm text-brand-ink">
                                    {{ trans_choice(':count artifact|:count artifacts', $artifactCount, ['count' => $artifactCount]) }}
                                </dd>
                            </div>
                        </dl>

                        @if ($failureClassification)
                            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-red-700">{{ __('Failure classification') }}</p>
                                <p class="mt-1 text-sm font-semibold text-red-900">{{ $failureClassification['label'] }}</p>
                                <p class="mt-1 text-sm text-red-800">{{ $failureClassification['detail'] }}</p>
                            </div>
                        @endif

                        @if ($run->summary)
                            <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/25 px-4 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Summary') }}</p>
                                <p class="mt-1 text-sm leading-6 text-brand-ink">{{ $run->summary }}</p>
                            </div>
                        @endif
                    </section>
                @endif

                @if ($verificationChecks !== [])
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Verification results') }}</h3>
                        <div class="mt-4 space-y-3">
                            @foreach ($verificationChecks as $check)
                                <div class="rounded-xl border {{ $check['status'] === 'ok' ? 'border-emerald-100 bg-emerald-50/70' : 'border-red-200 bg-red-50/70' }} px-4 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-brand-ink">{{ $check['label'] }}</p>
                                            @if ($check['detail'])
                                                <p class="mt-1 text-sm {{ $check['status'] === 'ok' ? 'text-brand-moss' : 'text-red-800' }}">{{ $check['detail'] }}</p>
                                            @endif
                                        </div>
                                        <span class="ml-auto inline-flex shrink-0 items-center self-start whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $check['status'] === 'ok' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">
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

                {{-- Divergence banner: fires when the reconciled snapshot
                     reports a different database engine than the wizard
                     requested (e.g., low-memory mode swapped MySQL → SQLite).
                     The wizard request lives in $requestedDatabase; the
                     installed reality lives in $installedStack. We use the
                     `installedStackDiverges` precomputed bool from the
                     component to keep view logic minimal. --}}
                @if ($installedStackDiverges)
                    <section class="{{ $card }} overflow-hidden border-amber-200 p-0">
                        <div class="border-b border-amber-200 bg-amber-50/70 px-5 py-4 sm:px-6">
                            <div class="flex items-start gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-amber-900">{{ __('Server stack differs from your request') }}</p>
                                    <p class="mt-1 text-sm text-amber-800">
                                        {{ __('You picked :requested, but the droplet was too small to run it safely. The provisioning script substituted :installed to keep the server functional.', [
                                            'requested' => $requestedDatabase ?? '—',
                                            'installed' => $installedStack->database ?? '—',
                                        ]) }}
                                    </p>
                                    @if ($installedStack->lowMemoryMode && $installedStack->totalMemoryMb)
                                        <p class="mt-1 text-xs text-amber-700">
                                            {{ __('Detected :memMb MB total RAM (low-memory mode threshold: 1024 MB). Re-provision on a 2 GB+ droplet to install :requested.', [
                                                'memMb' => $installedStack->totalMemoryMb,
                                                'requested' => $requestedDatabase ?? '—',
                                            ]) }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </section>
                @endif

                @if ($stackSummary)
                    <section class="{{ $card }} overflow-hidden p-0">
                        <div class="border-b border-brand-ink/10 bg-gradient-to-br from-brand-sand/30 via-white to-brand-cream/40 px-5 py-5 sm:px-6">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest ring-1 ring-brand-forest/20">
                                    <x-heroicon-o-cube class="h-6 w-6" aria-hidden="true" />
                                </span>
                                <div>
                                    <h3 class="text-lg font-semibold text-brand-ink">{{ __('Installed stack') }}</h3>
                                    <p class="text-xs text-brand-moss">{{ __('What this provision installed and where it lives.') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="px-5 py-5 sm:px-6">
                            <dl class="divide-y divide-brand-ink/5 rounded-xl border border-brand-ink/10 bg-white/70">
                                @foreach ($stackTiles as $tile)
                                    <div class="flex items-center gap-3 px-3 py-2.5 first:rounded-t-xl last:rounded-b-xl">
                                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-sand/40 text-brand-forest">
                                            <x-dynamic-component :component="$tile['icon']" class="h-4 w-4" aria-hidden="true" />
                                        </span>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $tile['label'] }}</dt>
                                        <dd class="ml-auto min-w-0 truncate text-right text-sm font-semibold text-brand-ink">{{ $tile['value'] ?: '—' }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            @if ($stackSummary['expected_services'] !== [])
                                <div class="mt-5">
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Expected services') }}</p>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach ($stackSummary['expected_services'] as $service)
                                            <span class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-800">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                {{ $service }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if ($stackSummary['paths'] !== [])
                                <div class="mt-5">
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Deploy paths') }}</p>
                                    <dl class="mt-2 space-y-1.5">
                                        @foreach ($stackSummary['paths'] as $label => $path)
                                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 rounded-lg bg-brand-sand/15 px-3 py-2">
                                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ ucfirst($label) }}</dt>
                                                <dd class="break-all font-mono text-[11px] text-brand-ink">{{ $path }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif

                            @if ($stackSummary['config_files'] !== [])
                                <div class="mt-5">
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Config files') }}</p>
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($stackSummary['config_files'] as $file)
                                            <li class="flex items-start gap-2 rounded-lg bg-brand-sand/15 px-3 py-2">
                                                <x-heroicon-o-document-text class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist" />
                                                <code class="break-all font-mono text-[11px] text-brand-ink">{{ $file }}</code>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
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
                    <div class="mt-6 space-y-3">
                        @foreach ($artifacts as $artifact)
                            {{-- Each artifact is a collapsed disclosure by default;
                                 the content (rendered configs, JSON metadata) is dense
                                 and operators usually scan the list of labels first
                                 before drilling into specifics. wire:ignore.self +
                                 Alpine open state keeps each one's expand/collapse
                                 stable across Livewire polls. --}}
                            <details
                                wire:key="artifact-{{ $artifact->id ?? $loop->index }}"
                                wire:ignore.self
                                x-data="{ open: false }"
                                x-bind:open="open"
                                @toggle.stop="open = $event.target.open"
                                class="group overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-sand/10"
                            >
                                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                                    <span class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-moss transition-transform group-open:rotate-90" />
                                        {{ $artifact->label }}
                                    </span>
                                    <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ str_replace('_', ' ', $artifact->type) }}</span>
                                </summary>
                                @if ($artifact->content)
                                    <div class="max-h-[min(28rem,70vh)] min-w-0 overflow-auto border-t border-brand-ink/10 bg-slate-950">
                                        <pre class="block w-max min-w-full px-4 py-3 font-mono text-[12px] leading-5 text-slate-200 whitespace-pre selection:bg-emerald-500/30">{{ $artifact->content }}</pre>
                                    </div>
                                @endif
                            </details>
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

            @if ($showResumeInstallModal)
                <div
                    class="fixed inset-0 z-50 overflow-y-auto"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="resume-install-title"
                >
                    <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeResumeInstallModal"></div>
                    <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10">
                        <div class="w-full max-w-xl dply-modal-panel" @click.stop>
                            <div class="border-b border-zinc-100 px-6 py-6 sm:px-8 sm:py-7">
                                <h2 id="resume-install-title" class="text-lg font-semibold text-brand-ink">{{ __('Resume install') }}</h2>
                                <p class="mt-3 text-sm leading-relaxed text-brand-moss">
                                    {{ __('Re-runs the bootstrap script from the top. Already-installed packages and applied configs are detected and skipped quickly, so a re-run after a transient failure (e.g. a PPA timeout) usually finishes in seconds rather than minutes.') }}
                                </p>
                            </div>
                            <div class="space-y-4 px-6 py-7 sm:px-8 sm:py-8">
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-5 text-sm text-brand-moss">
                                    <p class="font-medium text-brand-ink">{{ __('What re-runs') }}</p>
                                    <ul class="mt-2 space-y-1 list-disc pl-5">
                                        <li>{{ __('apt-get update + repository setup') }}</li>
                                        <li>{{ __('Package installs (skip if already installed)') }}</li>
                                        <li>{{ __('Webserver / PHP / database configuration writes') }}</li>
                                        <li>{{ __('Service enable + start') }}</li>
                                    </ul>
                                </div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-5 text-sm text-brand-moss">
                                    <p class="font-medium text-brand-ink">{{ __('Server state') }}</p>
                                    <p class="mt-2">{{ __('Server') }}: <span class="font-medium text-brand-ink">{{ ucfirst($server->status) }}</span></p>
                                    <p class="mt-1">{{ __('Setup') }}: <span class="font-medium text-brand-ink">{{ ucfirst($server->setup_status) }}</span></p>
                                </div>
                            </div>
                            <div class="flex flex-wrap justify-end gap-3 border-t border-zinc-100 bg-zinc-50/80 px-6 py-5 sm:px-8 sm:py-6">
                                <button
                                    type="button"
                                    wire:click="closeResumeInstallModal"
                                    class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-brand-ink hover:bg-zinc-50 sm:px-6"
                                >
                                    {{ __('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="rerunSetup"
                                    wire:loading.attr="disabled"
                                    wire:target="rerunSetup"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-3 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest sm:px-6 disabled:cursor-wait disabled:opacity-60"
                                >
                                    <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="rerunSetup" />
                                    <svg wire:loading wire:target="rerunSetup" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    <span wire:loading.remove wire:target="rerunSetup">{{ __('Resume install') }}</span>
                                    <span wire:loading wire:target="rerunSetup">{{ __('Queueing…') }}</span>
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
