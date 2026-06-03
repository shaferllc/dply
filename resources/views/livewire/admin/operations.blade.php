@php
    $card = 'dply-card-compact';
    $mini = 'text-xs font-medium uppercase tracking-wide text-brand-mist';
    $kv = 'flex flex-wrap items-baseline justify-between gap-2 border-b border-brand-ink/5 py-2 last:border-0';
    $pillOk = 'inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800';
    $pillBad = 'inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-800';
    $pillNeutral = 'inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700';
@endphp

<div>
    <x-page-header
        :title="__('Operations')"
        :description="__('Runtime probes, queue health, log tail, exports, and cache maintenance. Horizon and Pulse require separate worker processes.')"
        flush
        compact
    />

    <section class="mb-8">
        <div class="flex flex-wrap gap-2">
            <a href="{{ $horizonUrl }}" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">
                <x-heroicon-o-queue-list class="h-5 w-5 text-brand-moss" />
                {{ __('Horizon') }}
            </a>
            <a href="{{ $pulseUrl }}" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">
                <x-heroicon-o-chart-bar class="h-5 w-5 text-brand-moss" />
                {{ __('Laravel Pulse') }}
            </a>
            @if ($reverbHealthUrl)
                <a href="{{ $reverbHealthUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-signal class="h-5 w-5 text-brand-moss" />
                    {{ __('Reverb health') }}
                </a>
            @endif
        </div>
    </section>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => __('Queue pending'), 'value' => $counts['pending_jobs']],
            ['label' => __('Failed jobs'), 'value' => $counts['failed_jobs']],
            ['label' => __('Task runner pending'), 'value' => $counts['task_runner_tasks_pending']],
            ['label' => __('Task runner failed'), 'value' => $counts['task_runner_failed']],
        ] as $stat)
            <div class="{{ $card }}">
                <p class="{{ $mini }}">{{ $stat['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($stat['value']) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <div class="{{ $card }} space-y-1">
            <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Runtime & optimization') }}</h2>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('PHP') }}</span><span class="font-mono text-xs">{{ $system['php'] }}</span></div>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('Laravel') }}</span><span class="font-mono text-xs">{{ $system['laravel'] }}</span></div>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('Debug') }}</span><span class="{{ $system['debug'] ? $pillBad : $pillOk }}">{{ $system['debug'] ? __('On') : __('Off') }}</span></div>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('Config cached') }}</span><span class="{{ $system['config_cached'] ? $pillOk : $pillNeutral }}">{{ $system['config_cached'] ? __('Yes') : __('No') }}</span></div>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('Routes cached') }}</span><span class="{{ $system['routes_cached'] ? $pillOk : $pillNeutral }}">{{ $system['routes_cached'] ? __('Yes') : __('No') }}</span></div>
        </div>
        <div class="{{ $card }} space-y-1">
            <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Connectivity & drivers') }}</h2>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('Database') }}</span><span class="{{ $system['db_ok'] ? $pillOk : $pillBad }}">{{ $system['db_ok'] ? __('Reachable') : __('Unreachable') }}</span></div>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('Redis') }}</span>
                @if ($system['redis_ok'] === true)<span class="{{ $pillOk }}">{{ __('OK') }}</span>
                @elseif ($system['redis_ok'] === false)<span class="{{ $pillBad }}">{{ __('Failed') }}</span>
                @else<span class="{{ $pillNeutral }}">{{ __('Unknown') }}</span>@endif
            </div>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('Queue') }}</span><span class="font-mono text-xs">{{ $system['queue_connection'] }}</span></div>
            <div class="{{ $kv }}"><span class="text-brand-moss">{{ __('Cache') }}</span><span class="font-mono text-xs">{{ $system['cache_store'] }}</span></div>
            @if ($system['disk'])
                <p class="mt-3 rounded-lg bg-zinc-50 px-3 py-2 text-xs">{{ __(':free free of :total (:pct% used)', ['free' => $system['disk']['free'], 'total' => $system['disk']['total'], 'pct' => $system['disk']['used_percent']]) }}</p>
            @endif
        </div>
    </div>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <div class="{{ $card }}">
            <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Data exports') }}</h2>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="downloadAuditCsv" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-arrow-down-tray class="h-5 w-5 text-brand-moss" />
                    {{ __('Export audit log') }}
                </button>
                <button type="button" wire:click="downloadUsersCsv" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-user-group class="h-5 w-5 text-brand-moss" />
                    {{ __('Export users') }}
                </button>
            </div>
        </div>
        <div class="{{ $card }}">
            <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Cache & queue maintenance') }}</h2>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="clearApplicationCache" wire:loading.attr="disabled" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-medium text-amber-950 hover:bg-amber-100">{{ __('Clear application cache') }}</button>
                <button type="button" wire:click="clearOptimizedCaches" wire:loading.attr="disabled" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">{{ __('Optimize clear') }}</button>
                <button type="button" wire:click="retryFailedJobs" wire:loading.attr="disabled" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40">
                    {{ __('Retry failed jobs') }}@if ($failedJobsCount > 0) <span class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700">{{ $failedJobsCount }}</span>@endif
                </button>
                <button type="button" wire:click="flushFailedJobs" wire:loading.attr="disabled" wire:confirm="{{ __('Permanently delete all :n failed job(s)?', ['n' => $failedJobsCount]) }}" class="rounded-lg border border-rose-200 bg-white px-4 py-2.5 text-sm font-medium text-rose-700 shadow-sm hover:bg-rose-50">{{ __('Flush failed jobs') }}</button>
            </div>

            {{-- Console — captured output of the maintenance commands run above. --}}
            <div class="mt-4">
                <div class="mb-1.5 flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Console') }}</p>
                    @if ($consoleOutput !== '')
                        <button type="button" wire:click="clearConsole" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Clear') }}</button>
                    @endif
                </div>
                <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $consoleOutput !== '' ? $consoleOutput : __('Run a maintenance action above — its output appears here.') }}</pre>
            </div>
        </div>
    </div>

    @if ($recentFailedJobs->isNotEmpty())
        <section class="mb-8">
            <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Recent failed queue jobs') }}</h2>
            <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                    <thead class="bg-brand-sand/40 text-brand-moss">
                        <tr>
                            <th class="px-3 py-2">{{ __('Failed at') }}</th>
                            <th class="px-3 py-2">{{ __('Connection') }}</th>
                            <th class="px-3 py-2">{{ __('Queue') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                        @foreach ($recentFailedJobs as $fj)
                            <tr>
                                <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse($fj->failed_at)->timezone(config('app.timezone'))->format('M j H:i') }}</td>
                                <td class="px-3 py-2 font-mono">{{ $fj->connection }}</td>
                                <td class="px-3 py-2 font-mono">{{ $fj->queue }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="mb-8">
        <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Application log tail') }}</h2>
        @if ($logTail)
            <pre class="max-h-[18rem] overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-950 p-4 font-mono text-[11px] text-zinc-100">{{ $logTail }}</pre>
        @else
            <p class="rounded-xl border border-dashed border-brand-ink/15 px-4 py-6 text-sm text-brand-moss">{{ __('Log file not readable yet.') }}</p>
        @endif
    </section>
</div>
