<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Backups') }}</li>
            </ol>
        </nav>

        @if (! $featureActive)
            <x-backups-preview-panel />
        @else

        {{-- Page header --}}
        <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('Backups') }}</h1>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Schedules, recent runs, and storage destinations across :org.', ['org' => $organization->name]) }}</p>
            </div>
            <a
                href="{{ route('profile.backup-configurations') }}"
                wire:navigate
                class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                <x-heroicon-o-archive-box-arrow-down class="h-4 w-4" aria-hidden="true" />
                {{ __('Manage destinations') }}
            </a>
        </div>

        {{-- Health strip --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 mb-8">
            @php
                $metricCards = [
                    [
                        'label' => __('Completed (7d)'),
                        'value' => number_format($metrics['completed7d']),
                        'icon'  => 'check-circle',
                        'color' => 'text-brand-sage',
                        'bg'    => 'bg-brand-sage/10',
                    ],
                    [
                        'label' => __('Failed (7d)'),
                        'value' => number_format($metrics['failed7d']),
                        'icon'  => 'exclamation-circle',
                        'color' => $metrics['failed7d'] > 0 ? 'text-brand-rust' : 'text-brand-mist',
                        'bg'    => $metrics['failed7d'] > 0 ? 'bg-brand-rust/10' : 'bg-brand-sand/40',
                    ],
                    [
                        'label' => __('Storage used'),
                        'value' => $metrics['storage'],
                        'icon'  => 'cloud-arrow-up',
                        'color' => 'text-brand-moss',
                        'bg'    => 'bg-brand-sand/40',
                    ],
                    [
                        'label' => __('Active schedules'),
                        'value' => number_format($metrics['activeSchedules']),
                        'icon'  => 'clock',
                        'color' => 'text-brand-forest',
                        'bg'    => 'bg-brand-forest/10',
                    ],
                    [
                        'label' => __('Unprotected servers'),
                        'value' => number_format($metrics['unprotectedServers']),
                        'icon'  => 'shield-exclamation',
                        'color' => $metrics['unprotectedServers'] > 0 ? 'text-amber-600' : 'text-brand-mist',
                        'bg'    => $metrics['unprotectedServers'] > 0 ? 'bg-amber-50' : 'bg-brand-sand/40',
                    ],
                ];
            @endphp
            @foreach ($metricCards as $card)
                <div class="rounded-2xl border border-brand-ink/10 bg-white/85 p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <p class="text-xs font-medium text-brand-mist">{{ $card['label'] }}</p>
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg {{ $card['bg'] }} {{ $card['color'] }}">
                            <x-dynamic-component :component="'heroicon-o-' . $card['icon']" class="h-4 w-4" aria-hidden="true" />
                        </span>
                    </div>
                    <p class="text-2xl font-bold tracking-tight text-brand-ink">{{ $card['value'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Schedules --}}
        <section class="mb-8">
            <div class="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Schedules') }}</h2>
                    <p class="text-xs text-brand-moss mt-0.5">{{ __('All recurring backup schedules across your servers. Manage targets and cadence from each server workspace.') }}</p>
                </div>
            </div>

            @if ($schedules->isEmpty())
                <div class="rounded-2xl border border-brand-ink/10 bg-white/85 px-6 py-12 text-center shadow-sm">
                    <x-heroicon-o-clock class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                    <p class="mt-3 text-sm text-brand-moss">{{ __('No backup schedules yet.') }}</p>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Add schedules from a server's Backups workspace.') }}</p>
                    <a href="{{ route('servers.index') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-sage hover:text-brand-ink">
                        {{ __('Go to servers') }}
                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            @else
                <div class="dply-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-brand-ink/10 bg-brand-sand/20 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <th class="px-4 py-3 sm:px-6">{{ __('Server') }}</th>
                                    <th class="px-4 py-3">{{ __('Target') }}</th>
                                    <th class="px-4 py-3">{{ __('Cadence') }}</th>
                                    <th class="px-4 py-3">{{ __('Status') }}</th>
                                    <th class="px-4 py-3">{{ __('Last run') }}</th>
                                    <th class="px-4 py-3">{{ __('Destination') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ($schedules as $schedule)
                                    <tr wire:key="schedule-{{ $schedule->id }}" class="hover:bg-brand-sand/10">
                                        <td class="px-4 py-3 sm:px-6 font-medium text-brand-ink">
                                            <a
                                                href="{{ route('servers.backups', $schedule->server) }}"
                                                wire:navigate
                                                class="hover:text-brand-sage"
                                            >{{ $schedule->server?->name ?? '—' }}</a>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-col gap-0.5">
                                                <span class="font-medium text-brand-ink">{{ $schedule->targetLabel() }}</span>
                                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide w-fit
                                                    {{ $schedule->target_type === 'database' ? 'bg-brand-forest/10 text-brand-forest' : 'bg-brand-gold/20 text-amber-800' }}
                                                ">
                                                    {{ $schedule->target_type === 'database' ? __('DB') : __('Files') }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs text-brand-moss">
                                            {{ $schedule->cron_expression }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($schedule->is_active)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-xs font-semibold text-brand-forest">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                                    {{ __('Active') }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-semibold text-brand-mist">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-brand-mist" aria-hidden="true"></span>
                                                    {{ __('Paused') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-brand-moss">
                                            {{ $schedule->last_run_at ? $schedule->last_run_at->diffForHumans() : __('Never') }}
                                        </td>
                                        <td class="px-4 py-3 text-brand-moss">
                                            {{ $schedule->backupConfiguration?->name ?? __('Server default') }}
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <button
                                                    type="button"
                                                    wire:click="runScheduleNow('{{ $schedule->id }}')"
                                                    wire:loading.attr="disabled"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                                                    title="{{ __('Run now') }}"
                                                >
                                                    <x-heroicon-o-play class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Run') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="toggleSchedule('{{ $schedule->id }}')"
                                                    wire:loading.attr="disabled"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-moss shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                                                    title="{{ $schedule->is_active ? __('Pause') : __('Resume') }}"
                                                >
                                                    @if ($schedule->is_active)
                                                        <x-heroicon-o-pause class="h-3.5 w-3.5" aria-hidden="true" />
                                                    @else
                                                        <x-heroicon-o-play-pause class="h-3.5 w-3.5" aria-hidden="true" />
                                                    @endif
                                                </button>
                                                <a
                                                    href="{{ route('servers.backups', $schedule->server) }}"
                                                    wire:navigate
                                                    class="inline-flex items-center justify-center h-7 w-7 rounded-lg border border-brand-ink/10 bg-white text-brand-mist shadow-sm transition hover:text-brand-ink hover:bg-brand-sand/40"
                                                    title="{{ __('Open on server') }}"
                                                >
                                                    <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>

        {{-- Recent runs --}}
        <section class="mb-8">
            <div class="mb-4">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Recent runs') }}</h2>
                <p class="text-xs text-brand-moss mt-0.5">{{ __('Last 25 database backup runs across all servers.') }}</p>
            </div>

            @if ($recentRuns->isEmpty())
                <div class="rounded-2xl border border-brand-ink/10 bg-white/85 px-6 py-12 text-center shadow-sm">
                    <x-heroicon-o-circle-stack class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                    <p class="mt-3 text-sm text-brand-moss">{{ __('No backup runs yet.') }}</p>
                </div>
            @else
                <div class="dply-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-brand-ink/10 bg-brand-sand/20 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <th class="px-4 py-3 sm:px-6">{{ __('Status') }}</th>
                                    <th class="px-4 py-3">{{ __('Server / Database') }}</th>
                                    <th class="px-4 py-3">{{ __('Size') }}</th>
                                    <th class="px-4 py-3">{{ __('Destination') }}</th>
                                    <th class="px-4 py-3">{{ __('When') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ($recentRuns as $run)
                                    <tr wire:key="run-{{ $run->id }}" class="hover:bg-brand-sand/10">
                                        <td class="px-4 py-3 sm:px-6">
                                            @if ($run->status === 'completed')
                                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-xs font-semibold text-brand-forest">
                                                    <x-heroicon-m-check class="h-3 w-3" aria-hidden="true" />
                                                    {{ __('Done') }}
                                                </span>
                                            @elseif ($run->status === 'failed')
                                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-rust/10 px-2 py-0.5 text-xs font-semibold text-brand-rust">
                                                    <x-heroicon-m-x-mark class="h-3 w-3" aria-hidden="true" />
                                                    {{ __('Failed') }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-gold/20 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse" aria-hidden="true"></span>
                                                    {{ __('Pending') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-col gap-0.5">
                                                <span class="font-medium text-brand-ink">{{ $run->serverDatabase?->server?->name ?? '—' }}</span>
                                                <span class="text-xs text-brand-moss">{{ $run->serverDatabase?->name ?? '—' }}</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-brand-moss">
                                            {{ $run->bytes ? \Illuminate\Support\Number::fileSize((int) $run->bytes) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-brand-moss">
                                            {{ $run->backupConfiguration?->name ?? __('Server default') }}
                                        </td>
                                        <td class="px-4 py-3 text-brand-moss">
                                            <time datetime="{{ $run->created_at->toIso8601String() }}" title="{{ $run->created_at->format('Y-m-d H:i:s') }}">
                                                {{ $run->created_at->diffForHumans() }}
                                            </time>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>

        {{-- Storage destinations --}}
        <section>
            <div class="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Storage destinations') }}</h2>
                    <p class="text-xs text-brand-moss mt-0.5">{{ __('S3-compatible and remote destinations available to backup schedules in this org.') }}</p>
                </div>
                <a
                    href="{{ route('profile.backup-configurations') }}"
                    wire:navigate
                    class="shrink-0 inline-flex items-center gap-1.5 text-sm font-medium text-brand-sage hover:text-brand-ink"
                >
                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                    {{ __('Add destination') }}
                </a>
            </div>

            @if ($destinations->isEmpty())
                <div class="rounded-2xl border border-brand-ink/10 bg-white/85 px-6 py-12 text-center shadow-sm">
                    <x-heroicon-o-cloud-arrow-up class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                    <p class="mt-3 text-sm text-brand-moss">{{ __('No storage destinations configured yet.') }}</p>
                    <a
                        href="{{ route('profile.backup-configurations') }}"
                        wire:navigate
                        class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-sage hover:text-brand-ink"
                    >
                        {{ __('Add your first destination') }}
                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            @else
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($destinations as $destination)
                        @php
                            $usedBy = $schedules->where('backup_configuration_id', $destination->id)->count();
                        @endphp
                        <div wire:key="dest-{{ $destination->id }}" class="flex items-center gap-4 rounded-2xl border border-brand-ink/10 bg-white/85 p-4 shadow-sm">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/8">
                                <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-brand-ink text-sm">{{ $destination->name }}</p>
                                <p class="text-xs text-brand-mist mt-0.5">
                                    {{ \App\Models\BackupConfiguration::labelForProvider($destination->provider) }}
                                    @if ($usedBy > 0)
                                        · {{ trans_choice(':count schedule|:count schedules', $usedBy, ['count' => $usedBy]) }}
                                    @endif
                                </p>
                            </div>
                            <a
                                href="{{ route('profile.backup-configurations') }}"
                                wire:navigate
                                class="shrink-0 text-brand-mist hover:text-brand-ink transition-colors"
                                title="{{ __('Edit') }}"
                            >
                                <x-heroicon-o-pencil-square class="h-4 w-4" aria-hidden="true" />
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        @endif
    </div>
</div>
