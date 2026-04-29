@php
    $card = 'dply-card-compact';
    $mini = 'text-xs font-medium uppercase tracking-wide text-brand-mist';
    $kv = 'flex flex-wrap items-baseline justify-between gap-2 border-b border-brand-ink/5 py-2 last:border-0';
    $pillOk = 'inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800';
    $pillBad = 'inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-800';
    $pillNeutral = 'inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700';
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <header class="mb-8 border-b border-brand-ink/10 pb-6">
        <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('Platform admin') }}</h1>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Operations control room: twenty built-in tools for health checks, fleet insight, exports, and safe cache maintenance. Horizon, Pulse, and queue workers must be running for background processing.') }}
        </p>
        <details class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
            <summary class="cursor-pointer font-medium text-brand-ink">{{ __('What’s included (20)') }}</summary>
            <ul class="mt-3 list-inside list-disc space-y-1.5 pl-1">
                @foreach ($featuresHighlight as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </details>
    </header>

    @if ($operationMessage)
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
            {{ $operationMessage }}
        </div>
    @endif
    @if ($operationError)
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
            {{ $operationError }}
        </div>
    @endif

    <section aria-labelledby="admin-tools-heading" class="mb-8">
        <h2 id="admin-tools-heading" class="mb-3 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Operations tools') }}</h2>
        <div class="flex flex-wrap gap-2">
            <a
                href="{{ $horizonUrl }}"
                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-queue-list class="h-5 w-5 text-brand-moss" />
                {{ __('Horizon') }}
            </a>
            <a
                href="{{ $pulseUrl }}"
                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-chart-bar class="h-5 w-5 text-brand-moss" />
                {{ __('Laravel Pulse') }}
            </a>
            @if ($reverbHealthUrl)
                <a
                    href="{{ $reverbHealthUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-signal class="h-5 w-5 text-brand-moss" />
                    {{ __('Reverb health') }}
                </a>
            @endif
            <a
                href="{{ route('servers.index') }}"
                wire:navigate
                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-server class="h-5 w-5 text-brand-moss" />
                {{ __('Servers') }}
            </a>
            <a
                href="{{ route('organizations.index') }}"
                wire:navigate
                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-building-office-2 class="h-5 w-5 text-brand-moss" />
                {{ __('Organizations') }}
            </a>
        </div>
        <p class="mt-2 text-xs text-brand-mist">
            {{ __('Reverb health is the HTTP :up endpoint on the Reverb process. Use Horizon for failed jobs and retries when using Redis queues.', ['up' => '/up']) }}
        </p>
    </section>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Users') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['users']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Organizations') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['organizations']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Servers') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['servers']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Sites') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['sites']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Audit (24h)') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['audit_logs_24h']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Task runner pending') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['task_runner_tasks_pending']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('New users (7d)') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['users_7d']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('New orgs (7d)') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['organizations_7d']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Queue jobs pending') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['pending_jobs']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Failed queue jobs') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['failed_jobs']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('API tokens') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['api_tokens']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Open invitations') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['invitations_open']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Status pages') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['status_pages']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Deploy scripts') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['scripts']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Projects') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['projects']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Task runner failed') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['task_runner_failed']) }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $mini }}">{{ __('Task runner running') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($counts['task_runner_running']) }}</p>
        </div>
    </div>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <div class="{{ $card }} space-y-1">
            <h2 class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Runtime & optimization') }}</h2>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('PHP') }}</span>
                <span class="font-mono text-xs text-brand-ink">{{ $system['php'] }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Laravel') }}</span>
                <span class="font-mono text-xs text-brand-ink">{{ $system['laravel'] }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Environment') }}</span>
                <span class="font-mono text-xs text-brand-ink">{{ $system['env'] }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Debug') }}</span>
                @if ($system['debug'])
                    <span class="{{ $pillBad }}">{{ __('On') }}</span>
                @else
                    <span class="{{ $pillOk }}">{{ __('Off') }}</span>
                @endif
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Maintenance') }}</span>
                @if ($system['maintenance'])
                    <span class="{{ $pillBad }}">{{ __('Down') }}</span>
                @else
                    <span class="{{ $pillOk }}">{{ __('Up') }}</span>
                @endif
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('App URL') }}</span>
                <span class="max-w-[14rem] truncate font-mono text-[11px] text-brand-ink" title="{{ $system['url'] }}">{{ $system['url'] }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Timezone') }}</span>
                <span class="font-mono text-xs text-brand-ink">{{ $system['timezone'] }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Config cached') }}</span>
                <span class="{{ $system['config_cached'] ? $pillOk : $pillNeutral }}">{{ $system['config_cached'] ? __('Yes') : __('No') }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Routes cached') }}</span>
                <span class="{{ $system['routes_cached'] ? $pillOk : $pillNeutral }}">{{ $system['routes_cached'] ? __('Yes') : __('No') }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Events cached') }}</span>
                <span class="{{ $system['events_cached'] ? $pillOk : $pillNeutral }}">{{ $system['events_cached'] ? __('Yes') : __('No') }}</span>
            </div>
        </div>

        <div class="{{ $card }} space-y-1">
            <h2 class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Connectivity & drivers') }}</h2>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Database') }}</span>
                @if ($system['db_ok'])
                    <span class="{{ $pillOk }}">{{ __('Reachable') }}</span>
                @else
                    <span class="{{ $pillBad }}">{{ __('Unreachable') }}</span>
                @endif
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Redis ping') }}</span>
                @if ($system['redis_ok'] === true)
                    <span class="{{ $pillOk }}">{{ __('OK') }}</span>
                @elseif ($system['redis_ok'] === false)
                    <span class="{{ $pillBad }}" title="{{ $system['redis_error'] ?? '' }}">{{ __('Failed') }}</span>
                @else
                    <span class="{{ $pillNeutral }}">{{ __('Unknown') }}</span>
                @endif
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Queue') }}</span>
                <span class="font-mono text-xs text-brand-ink">{{ $system['queue_connection'] }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Cache store') }}</span>
                <span class="font-mono text-xs text-brand-ink">{{ $system['cache_store'] }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Broadcast') }}</span>
                <span class="font-mono text-xs text-brand-ink">{{ $system['broadcast'] }}</span>
            </div>
            <div class="{{ $kv }}">
                <span class="text-brand-moss">{{ __('Mail') }}</span>
                <span class="font-mono text-xs text-brand-ink">{{ $system['mail_mailer'] }}</span>
            </div>
            @if ($system['disk'])
                <div class="mt-3 rounded-lg bg-zinc-50 px-3 py-2 text-xs text-brand-ink">
                    <p class="font-medium text-brand-moss">{{ __('Storage volume') }}</p>
                    <p class="mt-1 font-mono text-[11px] text-zinc-600">{{ $system['disk']['path'] }}</p>
                    <p class="mt-1">
                        {{ __(':free free of :total (:pct% used)', ['free' => $system['disk']['free'], 'total' => $system['disk']['total'], 'pct' => $system['disk']['used_percent']]) }}
                    </p>
                </div>
            @endif
        </div>
    </div>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <div class="{{ $card }}">
            <h2 class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Server status mix') }}</h2>
            <ul class="space-y-2 text-sm">
                @forelse ($serverByStatus as $status => $c)
                    <li class="flex justify-between gap-2 border-b border-brand-ink/5 pb-2 last:border-0">
                        <span class="font-mono text-xs text-brand-ink">{{ $status }}</span>
                        <span class="tabular-nums text-brand-moss">{{ number_format($c) }}</span>
                    </li>
                @empty
                    <li class="text-brand-mist">{{ __('No servers yet.') }}</li>
                @endforelse
            </ul>
        </div>
        <div class="{{ $card }}">
            <h2 class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Site status mix') }}</h2>
            <ul class="space-y-2 text-sm">
                @forelse ($siteByStatus as $status => $c)
                    <li class="flex justify-between gap-2 border-b border-brand-ink/5 pb-2 last:border-0">
                        <span class="font-mono text-xs text-brand-ink">{{ $status }}</span>
                        <span class="tabular-nums text-brand-moss">{{ number_format($c) }}</span>
                    </li>
                @empty
                    <li class="text-brand-mist">{{ __('No sites yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <div class="{{ $card }}">
            <h2 class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Application schedule (in-app)') }}</h2>
            <ul class="space-y-3 text-sm text-brand-moss">
                @foreach ($scheduleEntries as $row)
                    <li class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                        <p class="font-medium text-brand-ink">{{ $row['label'] }}</p>
                        <p class="mt-0.5 text-xs text-brand-mist">{{ $row['cadence'] }}</p>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-brand-mist">{{ __('Ensure the platform cron entry runs `php artisan schedule:run` every minute in production.') }}</p>
        </div>
        <div class="{{ $card }}">
            <h2 class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Top organizations by servers') }}</h2>
            <ul class="divide-y divide-brand-ink/10 text-sm">
                @forelse ($topOrganizations as $org)
                    <li class="flex items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                        <span class="truncate font-medium text-brand-ink" title="{{ $org->id }}">{{ $org->name }}</span>
                        <span class="shrink-0 tabular-nums text-brand-moss">{{ number_format($org->servers_count) }}</span>
                    </li>
                @empty
                    <li class="py-4 text-brand-mist">{{ __('No organizations yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <div class="{{ $card }}">
            <h2 class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Data exports') }}</h2>
            <p class="text-sm text-brand-moss">{{ __('Download CSV snapshots for compliance review or offline analysis.') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="downloadAuditCsv"
                    wire:loading.attr="disabled"
                    wire:target="downloadAuditCsv"
                    class="inline-flex min-w-[11rem] items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="downloadAuditCsv" class="inline-flex items-center gap-2">
                        <x-heroicon-o-arrow-down-tray class="h-5 w-5 text-brand-moss" />
                        {{ __('Export audit log') }}
                    </span>
                    <span wire:loading wire:target="downloadAuditCsv" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                        {{ __('Loading…') }}
                    </span>
                </button>
                <button
                    type="button"
                    wire:click="downloadUsersCsv"
                    wire:loading.attr="disabled"
                    wire:target="downloadUsersCsv"
                    class="inline-flex min-w-[11rem] items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="downloadUsersCsv" class="inline-flex items-center gap-2">
                        <x-heroicon-o-user-group class="h-5 w-5 text-brand-moss" />
                        {{ __('Export users') }}
                    </span>
                    <span wire:loading wire:target="downloadUsersCsv" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                        {{ __('Loading…') }}
                    </span>
                </button>
            </div>
        </div>
        <div class="{{ $card }}">
            <h2 class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Cache maintenance') }}</h2>
            <p class="text-sm text-brand-moss">{{ __('Runs on the app host. Use after deployments or when config changes are not visible.') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="clearApplicationCache"
                    wire:loading.attr="disabled"
                    wire:target="clearApplicationCache"
                    class="inline-flex min-w-[12rem] items-center justify-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-medium text-amber-950 hover:bg-amber-100 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="clearApplicationCache" class="inline-flex items-center gap-2">
                        <x-heroicon-o-arrow-path class="h-5 w-5" />
                        {{ __('Clear application cache') }}
                    </span>
                    <span wire:loading wire:target="clearApplicationCache" class="inline-flex items-center gap-2">
                        <x-spinner variant="amber" />
                        {{ __('Loading…') }}
                    </span>
                </button>
                <button
                    type="button"
                    wire:click="clearOptimizedCaches"
                    wire:loading.attr="disabled"
                    wire:target="clearOptimizedCaches"
                    class="inline-flex min-w-[11rem] items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="clearOptimizedCaches" class="inline-flex items-center gap-2">
                        <x-heroicon-o-bolt class="h-5 w-5 text-brand-moss" />
                        {{ __('Optimize clear') }}
                    </span>
                    <span wire:loading wire:target="clearOptimizedCaches" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                        {{ __('Loading…') }}
                    </span>
                </button>
            </div>
        </div>
    </div>

    @if ($recentFailedJobs->isNotEmpty())
        <section class="mb-8" aria-labelledby="failed-jobs-heading">
            <h2 id="failed-jobs-heading" class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Recent failed queue jobs') }}</h2>
            <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                <div class="max-h-[16rem] overflow-y-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                        <thead class="sticky top-0 bg-brand-sand/40 text-brand-moss">
                            <tr>
                                <th class="px-3 py-2 font-medium">{{ __('Failed at') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Connection') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Queue') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('UUID') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white text-brand-ink">
                            @foreach ($recentFailedJobs as $fj)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-brand-mist">{{ \Illuminate\Support\Carbon::parse($fj->failed_at)->timezone(config('app.timezone'))->format('M j H:i') }}</td>
                                    <td class="max-w-[8rem] truncate px-3 py-2 font-mono text-[11px]">{{ $fj->connection }}</td>
                                    <td class="max-w-[8rem] truncate px-3 py-2 font-mono text-[11px]">{{ $fj->queue }}</td>
                                    <td class="max-w-[10rem] truncate px-3 py-2 font-mono text-[10px] text-brand-moss" title="{{ $fj->uuid }}">{{ $fj->uuid }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-brand-mist">{{ __('Retry or flush from Horizon when using Redis queues.') }}</p>
        </section>
    @endif

    <section class="mb-8" aria-labelledby="log-tail-heading">
        <h2 id="log-tail-heading" class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Application log tail') }}</h2>
        @if ($logTail)
            <pre class="max-h-[18rem] overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-950 p-4 font-mono text-[11px] leading-relaxed text-zinc-100">{{ $logTail }}</pre>
            <p class="mt-2 text-xs text-brand-mist">{{ __('Last lines of storage/logs/laravel.log (truncated for safety).') }}</p>
        @else
            <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-4 py-6 text-sm text-brand-moss">{{ __('Log file not readable yet, or logging elsewhere.') }}</p>
        @endif
    </section>

    <div class="grid gap-8 lg:grid-cols-2">
        <section aria-labelledby="recent-audit-heading">
            <h2 id="recent-audit-heading" class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Recent audit log') }}</h2>
            <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                <div class="max-h-[28rem] overflow-y-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                        <thead class="sticky top-0 bg-brand-sand/40 text-brand-moss">
                            <tr>
                                <th class="px-3 py-2 font-medium">{{ __('When') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('User') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Action') }}</th>
                                <th class="px-3 py-2 font-medium">{{ __('Subject') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white text-brand-ink">
                            @forelse ($recentAuditLogs as $log)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-brand-mist">{{ $log->created_at?->timezone(config('app.timezone'))->format('M j H:i') }}</td>
                                    <td class="max-w-[8rem] truncate px-3 py-2" title="{{ $log->user?->email }}">{{ $log->user?->name ?? '—' }}</td>
                                    <td class="max-w-[10rem] truncate px-3 py-2 font-mono text-[11px]">{{ $log->action }}</td>
                                    <td class="max-w-[14rem] truncate px-3 py-2 text-brand-moss">{{ $log->subject_summary ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-6 text-center text-brand-mist">{{ __('No audit entries yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section aria-labelledby="recent-users-heading">
            <h2 id="recent-users-heading" class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Newest users') }}</h2>
            <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                <ul class="divide-y divide-brand-ink/10 bg-white text-sm">
                    @forelse ($recentUsers as $u)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3">
                            <span class="font-medium text-brand-ink">{{ $u->name }}</span>
                            <span class="text-xs text-brand-moss">{{ $u->email }}</span>
                            <span class="w-full text-xs text-brand-mist sm:w-auto sm:text-end">{{ $u->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-brand-mist">{{ __('No users.') }}</li>
                    @endforelse
                </ul>
            </div>
        </section>
    </div>
</div>
