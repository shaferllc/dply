@php
    $card = 'dply-card-compact';
    $mini = 'text-xs font-medium uppercase tracking-wide text-brand-mist';
    $pillOk = 'inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800';
    $pillBad = 'inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-800';
@endphp

<div>
    <x-page-header
        :title="__('Overview')"
        :description="__('Platform KPIs, health signals, and quick links into operations, audit, flags, and organizations.')"
        flush
        compact
    />

    @if ($healthIssues !== [])
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
            <p class="font-semibold">{{ __('Attention needed') }}</p>
            <ul class="mt-2 list-inside list-disc space-y-1">
                @foreach ($healthIssues as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
            <p class="mt-2">
                <a href="{{ route('admin.operations') }}" wire:navigate class="font-medium underline">{{ __('Open operations') }}</a>
            </p>
        </div>
    @else
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
            {{ __('Core connectivity checks look healthy. Review operations for queue depth and logs.') }}
        </div>
    @endif

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @foreach ([
            ['label' => __('Users'), 'value' => $counts['users']],
            ['label' => __('Organizations'), 'value' => $counts['organizations']],
            ['label' => __('Servers'), 'value' => $counts['servers']],
            ['label' => __('Sites'), 'value' => $counts['sites']],
            ['label' => __('Audit (24h)'), 'value' => $counts['audit_logs_24h']],
            ['label' => __('Failed jobs'), 'value' => $counts['failed_jobs']],
            ['label' => __('New users (7d)'), 'value' => $counts['users_7d']],
            ['label' => __('New orgs (7d)'), 'value' => $counts['organizations_7d']],
        ] as $stat)
            <div class="{{ $card }}">
                <p class="{{ $mini }}">{{ $stat['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($stat['value']) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('admin.operations') }}" wire:navigate class="{{ $card }} block transition hover:border-brand-sage/40">
            <p class="font-semibold text-brand-ink">{{ __('Operations') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Runtime, queues, logs, exports, cache') }}</p>
        </a>
        <a href="{{ route('admin.audit') }}" wire:navigate class="{{ $card }} block transition hover:border-brand-sage/40">
            <p class="font-semibold text-brand-ink">{{ __('Audit log') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Filter and export platform activity') }}</p>
        </a>
        <a href="{{ route('admin.flags.global') }}" wire:navigate class="{{ $card }} block transition hover:border-brand-sage/40">
            <p class="font-semibold text-brand-ink">{{ __('Global flags') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('App-wide kill switches') }}</p>
        </a>
        <a href="{{ route('admin.organizations.index') }}" wire:navigate class="{{ $card }} block transition hover:border-brand-sage/40">
            <p class="font-semibold text-brand-ink">{{ __('Organizations') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Search orgs and manage overrides') }}</p>
        </a>
    </div>

    <div class="mb-8 grid gap-6 lg:grid-cols-2">
        <div class="{{ $card }} space-y-1">
            <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Runtime snapshot') }}</h2>
            <div class="flex justify-between gap-2 border-b border-brand-ink/5 py-2 text-sm">
                <span class="text-brand-moss">{{ __('Environment') }}</span>
                <span class="font-mono text-xs">{{ $system['env'] }}</span>
            </div>
            <div class="flex justify-between gap-2 border-b border-brand-ink/5 py-2 text-sm">
                <span class="text-brand-moss">{{ __('Database') }}</span>
                @if ($system['db_ok'])
                    <span class="{{ $pillOk }}">{{ __('OK') }}</span>
                @else
                    <span class="{{ $pillBad }}">{{ __('Failed') }}</span>
                @endif
            </div>
            <div class="flex justify-between gap-2 py-2 text-sm">
                <span class="text-brand-moss">{{ __('Redis') }}</span>
                @if ($system['redis_ok'] === true)
                    <span class="{{ $pillOk }}">{{ __('OK') }}</span>
                @elseif ($system['redis_ok'] === false)
                    <span class="{{ $pillBad }}">{{ __('Failed') }}</span>
                @else
                    <span class="text-brand-mist">{{ __('Unknown') }}</span>
                @endif
            </div>
        </div>

        <div class="{{ $card }}">
            <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Top organizations by servers') }}</h2>
            <ul class="divide-y divide-brand-ink/10 text-sm">
                @forelse ($topOrganizations as $org)
                    <li class="flex items-center justify-between gap-2 py-2">
                        <a href="{{ route('admin.organizations.show', $org) }}" wire:navigate class="truncate font-medium text-brand-ink hover:underline">{{ $org->name }}</a>
                        <span class="tabular-nums text-brand-moss">{{ number_format($org->servers_count) }}</span>
                    </li>
                @empty
                    <li class="py-4 text-brand-mist">{{ __('No organizations yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <section>
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Recent audit log') }}</h2>
                <a href="{{ route('admin.audit') }}" wire:navigate class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('View all') }}</a>
            </div>
            <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                    <thead class="bg-brand-sand/40 text-brand-moss">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ __('When') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Action') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('User') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                        @forelse ($recentAuditLogs as $log)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2 text-brand-mist">{{ $log->created_at?->timezone(config('app.timezone'))->format('M j H:i') }}</td>
                                <td class="max-w-[10rem] truncate px-3 py-2 font-mono">{{ $log->action }}</td>
                                <td class="max-w-[8rem] truncate px-3 py-2">{{ $log->user?->email ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-6 text-center text-brand-mist">{{ __('No audit entries yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2 class="mb-3 text-base font-semibold text-brand-ink">{{ __('Newest users') }}</h2>
            <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                <ul class="divide-y divide-brand-ink/10 text-sm">
                    @forelse ($recentUsers as $u)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3">
                            <span class="font-medium text-brand-ink">{{ $u->name }}</span>
                            <span class="text-xs text-brand-moss">{{ $u->email }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-brand-mist">{{ __('No users.') }}</li>
                    @endforelse
                </ul>
            </div>
        </section>
    </div>
</div>
