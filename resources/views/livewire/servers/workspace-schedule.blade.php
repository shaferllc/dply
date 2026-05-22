@php
    use App\Services\Servers\SchedulerHealthEvaluator;

    $card = 'dply-card overflow-hidden';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $input = 'block w-full rounded-lg border border-brand-ink/20 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-2 focus:ring-brand-forest/30';

    /**
     * Health-state → visual chip mapping. Centralised here so the per-card
     * loop and the summary strip stay visually consistent.
     */
    $chipForHealth = static function (?string $health): array {
        return match ($health) {
            SchedulerHealthEvaluator::STATE_HEALTHY => [
                'label' => __('Healthy'),
                'classes' => 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200',
                'dot' => 'bg-emerald-500',
            ],
            SchedulerHealthEvaluator::STATE_WAITING => [
                'label' => __('Waiting for first tick'),
                'classes' => 'bg-sky-50 text-sky-800 ring-1 ring-sky-200',
                'dot' => 'bg-sky-500',
            ],
            SchedulerHealthEvaluator::STATE_AMBER => [
                'label' => __('Behind schedule'),
                'classes' => 'bg-amber-50 text-amber-900 ring-1 ring-amber-200',
                'dot' => 'bg-amber-500',
            ],
            SchedulerHealthEvaluator::STATE_RED => [
                'label' => __('Not ticking'),
                'classes' => 'bg-red-50 text-red-800 ring-1 ring-red-200',
                'dot' => 'bg-red-500',
            ],
            SchedulerHealthEvaluator::STATE_PAUSED => [
                'label' => __('Paused'),
                'classes' => 'bg-brand-sand/50 text-brand-mist ring-1 ring-brand-ink/10',
                'dot' => 'bg-brand-mist',
            ],
            default => [
                'label' => __('Unknown'),
                'classes' => 'bg-brand-sand/50 text-brand-mist ring-1 ring-brand-ink/10',
                'dot' => 'bg-brand-mist',
            ],
        };
    };
@endphp

<x-server-workspace-layout
    :server="$server"
    active="schedule"
    :title="__('Schedule')"
    :description="__('Framework schedulers running on this server. Tracks tick health for each scheduler; nudges you when one stops firing.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($contextSite)
        <div class="mb-4 flex items-center justify-between rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm">
            <p class="text-brand-ink">
                <span class="font-semibold">{{ __('Filtered to site:') }}</span>
                {{ $contextSite->name }}
            </p>
            <a href="{{ route('servers.schedule', $server) }}" wire:navigate class="text-xs font-semibold text-brand-ink underline">{{ __('Clear filter') }}</a>
        </div>
    @endif

    {{-- ─── Summary strip (Q11 c) ───────────────────────────────────────────
         Lets operators landing from an Insights notification spot the
         problem in <2s. Counts always reflect the whole server even when
         the page is filtered to a single site, so the strip stays useful
         as a horizon scan. --}}
    @php
        $totalSchedulers = $stats['tracked_total'] + $stats['paused'] + $stats['unmonitored'];
        $hasStale = ($stats['amber'] + $stats['red']) > 0;
    @endphp
    <section class="dply-card p-5">
        <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
            <div>
                <p class="text-2xl font-semibold text-brand-ink">{{ $totalSchedulers }}</p>
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ trans_choice('{0} no schedulers|{1} scheduler|[2,*] schedulers', $totalSchedulers) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                @if ($stats['healthy'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-800 ring-1 ring-emerald-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ $stats['healthy'] }} {{ __('healthy') }}
                    </span>
                @endif
                @if ($stats['waiting'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2.5 py-1 font-semibold text-sky-800 ring-1 ring-sky-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                        {{ $stats['waiting'] }} {{ __('waiting') }}
                    </span>
                @endif
                @if ($stats['amber'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 font-semibold text-amber-900 ring-1 ring-amber-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                        {{ $stats['amber'] }} {{ __('behind') }}
                    </span>
                @endif
                @if ($stats['red'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 font-semibold text-red-800 ring-1 ring-red-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                        {{ $stats['red'] }} {{ __('not ticking') }}
                    </span>
                @endif
                @if ($stats['paused'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/50 px-2.5 py-1 font-semibold text-brand-mist ring-1 ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-mist"></span>
                        {{ $stats['paused'] }} {{ __('paused') }}
                    </span>
                @endif
                @if ($stats['unmonitored'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50/50 px-2.5 py-1 font-semibold text-amber-900 ring-1 ring-amber-200">
                        <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                        {{ $stats['unmonitored'] }} {{ __('unmonitored') }}
                    </span>
                @endif
            </div>
        </div>
        @if ($hasStale)
            <p class="mt-3 text-xs text-brand-moss">
                {{ __('One or more schedulers haven\'t ticked recently. Click the card to investigate or use Run now to verify.') }}
            </p>
        @endif
    </section>

    {{-- ─── Per-site cards (Q11 c + d1) ───────────────────────────────────
         One card per (site, scheduler_kind) — the operator's mental model.
         Drill-down (per-task data) lands in milestone 3; for now each card
         is read-only with the health chip + cadence info. --}}
    @if (empty($cards))
        <div class="{{ $card }} p-8 text-center">
            <x-heroicon-o-clock class="mx-auto h-10 w-10 text-brand-mist" />
            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No sites on this server yet.') }}</p>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Add a site, then enable its scheduler from this page.') }}</p>
        </div>
    @else
        <div class="grid gap-3">
            @foreach ($cards as $cardData)
                @php
                    $site = $cardData['site'];
                    $state = $cardData['state'];
                    $chip = $chipForHealth($cardData['health']);
                @endphp
                <article class="{{ $card }} p-5" wire:key="card-{{ $site->id }}-{{ $cardData['kind'] ?? 'none' }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-semibold text-brand-ink">{{ $site->name }}</p>
                                @if ($cardData['kind'])
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                        {{ $cardData['kind'] }}
                                    </span>
                                @endif
                            </div>

                            @if ($state === 'no_scheduler')
                                <p class="mt-1 text-xs text-brand-mist italic">{{ __('No scheduler enabled.') }}</p>
                            @elseif ($state === 'detected_unmonitored')
                                <p class="mt-1 text-xs text-amber-900">
                                    <x-heroicon-m-exclamation-triangle class="inline h-3 w-3" />
                                    {{ __('Detected but unmonitored — this scheduler is firing but Dply isn\'t tracking its health. Enable monitoring to wrap it.') }}
                                </p>
                            @else
                                <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-brand-moss">
                                    <span class="font-mono">{{ $cardData['cron_expression'] }}</span>
                                    @if ($cardData['last_tick_at'])
                                        <span>·</span>
                                        <span title="{{ $cardData['last_tick_at']->toIso8601String() }}">{{ __('last tick :ago', ['ago' => $cardData['last_tick_at']->diffForHumans()]) }}</span>
                                    @endif
                                    @if ($cardData['next_run_at'] && $state !== 'paused')
                                        <span>·</span>
                                        <span title="{{ $cardData['next_run_at']->toIso8601String() }}">{{ __('next :time', ['time' => $cardData['next_run_at']->diffForHumans()]) }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if ($cardData['health'] !== null)
                            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $chip['classes'] }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $chip['dot'] }}"></span>
                                {{ $chip['label'] }}
                            </span>
                        @endif
                    </div>

                    {{-- Per-card actions (2B). Only wrapper-managed schedulers
                         get the action row — detected-but-unmonitored ones
                         show a single "Enable monitoring" CTA which lands in
                         2C alongside preflight. --}}
                    @if ($state === 'tracked' || $state === 'paused')
                        @php
                            $heartbeatId = $cardData['heartbeat']->id;
                            $isEditing = array_key_exists($heartbeatId, $editing_cadence);
                            $isPaused = $state === 'paused';
                            $runInFlight = in_array($heartbeatId, $run_now_in_flight, true);
                        @endphp
                        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-brand-ink/8 pt-3">
                            {{-- Run now --}}
                            <button
                                type="button"
                                wire:click="runNow('{{ $heartbeatId }}')"
                                wire:loading.attr="disabled"
                                wire:target="runNow('{{ $heartbeatId }}')"
                                @disabled($runInFlight || $isPaused)
                                class="{{ $btnSecondary }}"
                                title="{{ $isPaused ? __('Resume first — paused schedulers can\'t Run now.') : __('Fire schedule:run once. Coordinates with the wrapper\'s lock; 5-minute timeout.') }}"
                            >
                                <x-heroicon-o-play class="h-3.5 w-3.5" />
                                {{ $runInFlight ? __('Queued…') : __('Run now') }}
                            </button>

                            {{-- Pause / Resume --}}
                            <button
                                type="button"
                                wire:click="togglePause('{{ $heartbeatId }}')"
                                wire:loading.attr="disabled"
                                wire:target="togglePause('{{ $heartbeatId }}')"
                                class="{{ $btnSecondary }}"
                            >
                                @if ($isPaused)
                                    <x-heroicon-o-play class="h-3.5 w-3.5" />
                                    {{ __('Resume') }}
                                @else
                                    <x-heroicon-o-pause class="h-3.5 w-3.5" />
                                    {{ __('Pause') }}
                                @endif
                            </button>

                            {{-- Edit cadence --}}
                            @if ($isEditing)
                                <div class="flex items-center gap-1.5">
                                    <input
                                        type="text"
                                        wire:model="editing_cadence.{{ $heartbeatId }}"
                                        wire:keydown.enter="saveCadence('{{ $heartbeatId }}')"
                                        class="block rounded-lg border border-brand-ink/20 bg-white px-2 py-1 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-2 focus:ring-brand-forest/30"
                                        size="11"
                                        placeholder="* * * * *"
                                    />
                                    <button type="button" wire:click="saveCadence('{{ $heartbeatId }}')" class="{{ $btnSecondary }}" title="{{ __('Save (Enter)') }}">
                                        <x-heroicon-o-check class="h-3.5 w-3.5" />
                                    </button>
                                    <button type="button" wire:click="cancelEditCadence('{{ $heartbeatId }}')" class="{{ $btnSecondary }}" title="{{ __('Cancel') }}">
                                        <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            @else
                                <button type="button" wire:click="startEditCadence('{{ $heartbeatId }}')" class="{{ $btnSecondary }}">
                                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                    {{ __('Edit cadence') }}
                                </button>
                            @endif

                            <span class="grow"></span>

                            {{-- Disable Monitoring (right-aligned, destructive). Distinct
                                 from Pause: stops tracking, scheduler keeps firing. --}}
                            <button
                                type="button"
                                wire:click="disableMonitoring('{{ $heartbeatId }}')"
                                wire:confirm="{{ __('Stop monitoring this scheduler? The scheduler keeps running on the server; we\'ll just stop tracking it and any open Insights findings will close as no-longer-tracked.') }}"
                                class="inline-flex items-center justify-center gap-2 rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-red-800 shadow-sm hover:bg-red-50 transition-colors"
                            >
                                <x-heroicon-o-eye-slash class="h-3.5 w-3.5" />
                                {{ __('Stop monitoring') }}
                            </button>
                        </div>
                    @elseif ($state === 'detected_unmonitored')
                        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-brand-ink/8 pt-3 text-xs text-brand-mist italic">
                            <span>{{ __('Enable monitoring — wiring lands in 2C.') }}</span>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    {{-- ─── Enable scheduler for a site ──────────────────────────────────
         Existing form preserved as-is (creates a bare cron entry). The
         preflight + wrapper-invocation rewrite lands in milestone 2C; for
         now this stays the only way to add a new scheduler. --}}
    @if ($sites->isNotEmpty())
        <section class="{{ $card }}">
            <header class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Enable scheduler for a site') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Creates a cron entry under the site\'s system user. Pre-flight validation + wrapper-managed entries land in a follow-on milestone.') }}</p>
            </header>
            @if (! empty($preflight_results))
                {{-- Preflight result panel shown after a refused Enable. Lists
                     every check with pass/warn/fail status so the operator
                     knows precisely what to fix. Cleared on next attempt. --}}
                <div class="border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Preflight results') }}</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($preflight_results as $check)
                            @php
                                $statusChip = match ($check['status']) {
                                    'pass' => ['classes' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'label' => __('pass')],
                                    'warn' => ['classes' => 'bg-amber-50 text-amber-900 ring-amber-200', 'label' => __('warn')],
                                    default => ['classes' => 'bg-red-50 text-red-800 ring-red-200', 'label' => __('fail')],
                                };
                            @endphp
                            <li class="flex flex-wrap items-baseline gap-2 text-xs">
                                <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 font-semibold uppercase tracking-wide ring-1 {{ $statusChip['classes'] }}">{{ $statusChip['label'] }}</span>
                                <span class="font-mono text-brand-mist">{{ $check['key'] }}</span>
                                <span class="text-brand-moss">{{ $check['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form wire:submit="enableSchedulerForSite" class="grid gap-3 p-5 sm:grid-cols-4">
                <select wire:model="enable_site_id" class="{{ $input }} sm:col-span-2">
                    <option value="">{{ __('Pick a site…') }}</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}">{{ $site->name }}</option>
                    @endforeach
                </select>
                <select wire:model="enable_framework" class="{{ $input }} sm:col-span-1">
                    <option value="laravel">{{ __('Laravel (schedule:run)') }}</option>
                    <option value="rails">{{ __('Rails (whenever)') }}</option>
                </select>
                <input type="text" wire:model="enable_cron_expression" class="{{ $input }} font-mono sm:col-span-1" placeholder="* * * * *" />
                <button type="submit" class="{{ $btnPrimary }} sm:col-span-4 sm:justify-self-start" @disabled(! $opsReady)>
                    {{ __('Enable scheduler') }}
                </button>
            </form>
            <div class="border-t border-brand-ink/10 px-5 py-3 text-xs text-brand-moss">
                <p>{{ __('Prefer a long-running daemon? ') }}<a href="{{ route('servers.daemons', $server) }}?preset=laravel-schedule" wire:navigate class="font-semibold text-brand-ink underline">{{ __('Add a schedule:work supervisor program') }}</a>{{ __(' instead.') }}</p>
            </div>
        </section>
    @endif

    {{-- CLI equivalents — same idea as Cron / Daemons / Backups pages. --}}
    <x-cli-snippet :commands="[
        ['label' => __('List all cron jobs (server)'), 'command' => 'dply:server:cron:list '.$server->id],
        ['label' => __('Add a schedule:run cron entry for a site'), 'command' => 'dply:site:cron:add {site_slug} \'* * * * *\' \'php artisan schedule:run\''],
    ]" />
</x-server-workspace-layout>
