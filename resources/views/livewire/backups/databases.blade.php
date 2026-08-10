<div class="contents">
    @if ($qdId)
        <div wire:poll.1500ms="pollQuickDownload" class="hidden"></div>
    @endif

    <x-workspace-nav surface="local" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Backups'), 'icon' => 'archive-box'],
        ]" />

        @if (! $featureActive)
            <x-profile-shell
                dense
                :title="__('Backups')"
                :description="__('Schedules, recent runs, and storage destinations.')"
                icon="heroicon-o-archive-box"
            >
                <x-slot:tabs>
                    <x-backups-subnav active="databases" />
                </x-slot:tabs>
                <div class="px-3 py-3 sm:px-4">
                    <x-backups-preview-panel compact />
                </div>
            </x-profile-shell>
        @else
            @php
                $coverage = $metrics['coverage'];
                $lastSuccessAt = $metrics['lastSuccessAt'];
                $unprotectedCount = $metrics['unprotectedServers'];

                // The hero reads as a verdict, not a number: full coverage is calm,
                // partial coverage is a warning, nothing covered is a problem.
                $coverageTone = match (true) {
                    $metrics['servers'] === 0 => 'bg-brand-mist',
                    $unprotectedCount === 0 => 'bg-brand-sage',
                    $metrics['protectedServers'] === 0 => 'bg-brand-rust',
                    default => 'bg-brand-gold',
                };

                // Nothing scheduled and nothing ever run means this org has not
                // started — show the onboarding splash instead of empty tables.
                $neverUsed = request()->boolean('splash') || ($schedules->isEmpty() && $recentRuns->isEmpty());
            @endphp

            <x-profile-shell
                dense
                :title="__('Backups')"
                :description="__('Scheduled dumps, on-demand downloads, and off-box storage for every database in :org.', ['org' => $organization->name])"
                icon="heroicon-o-archive-box"
            >
                <x-slot:actions>
                    <a
                        href="{{ route('profile.backup-configurations') }}"
                        wire:navigate
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-archive-box-arrow-down class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Destinations') }}
                    </a>
                </x-slot:actions>

                <x-slot:stats>
                    {{-- Posture band: coverage verdict on the left, two weeks of run
                         history on the right, supporting numbers underneath. --}}
                    <div class="grid gap-px bg-brand-ink/5 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
                        <div class="bg-white px-4 py-4 sm:px-5">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Protection coverage') }}</p>
                            <div class="mt-1.5 flex items-baseline gap-2">
                                <span class="font-mono text-3xl font-semibold tabular-nums text-brand-ink">{{ $coverage }}%</span>
                                <span class="text-xs text-brand-moss">
                                    {{ __(':protected of :total servers on an active schedule', [
                                        'protected' => $metrics['protectedServers'],
                                        'total' => $metrics['servers'],
                                    ]) }}
                                </span>
                            </div>
                            <div
                                class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-brand-sand/70"
                                role="img"
                                aria-label="{{ __(':percent% of servers protected', ['percent' => $coverage]) }}"
                            >
                                <div class="h-full rounded-full {{ $coverageTone }}" style="width: {{ max($coverage, 2) }}%"></div>
                            </div>
                            <p class="mt-2 text-xs text-brand-moss">
                                @if ($metrics['servers'] === 0)
                                    {{ __('No servers in this workspace yet.') }}
                                @elseif ($unprotectedCount === 0)
                                    {{ __('Every server is covered by a backup schedule.') }}
                                @else
                                    {{ trans_choice(':count server has no schedule|:count servers have no schedule', $unprotectedCount, ['count' => $unprotectedCount]) }}
                                @endif
                            </p>
                        </div>

                        <div class="bg-white px-4 py-4 sm:px-5">
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Last 14 days') }}</p>
                                <p class="flex items-center gap-2 text-2xs text-brand-mist">
                                    <span class="inline-flex items-center gap-1">
                                        <span class="h-2 w-2 rounded-[2px] bg-brand-sage" aria-hidden="true"></span>{{ __('ok') }}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <span class="h-2 w-2 rounded-[2px] bg-brand-rust/70" aria-hidden="true"></span>{{ __('failed') }}
                                    </span>
                                    <span class="inline-flex items-center gap-1">
                                        <span class="h-2 w-2 rounded-[2px] bg-brand-sand" aria-hidden="true"></span>{{ __('none') }}
                                    </span>
                                </p>
                            </div>
                            @php
                                $activityTotal = collect($activity)->sum(fn ($day) => $day['completed'] + $day['failed']);
                            @endphp
                            <div class="mt-3 flex items-stretch gap-1">
                                @foreach ($activity as $day)
                                    @php
                                        $cellTone = match (true) {
                                            $day['failed'] > 0 => 'bg-brand-rust/70',
                                            $day['completed'] > 0 => 'bg-brand-sage',
                                            default => 'bg-brand-sand/60',
                                        };
                                        $cellLabel = $day['date']->format('M j').' — '.
                                            trans_choice(':count run|:count runs', $day['completed'] + $day['failed'], ['count' => $day['completed'] + $day['failed']]).
                                            ($day['failed'] > 0 ? ', '.trans_choice(':count failed|:count failed', $day['failed'], ['count' => $day['failed']]) : '');
                                    @endphp
                                    <span
                                        class="h-7 flex-1 rounded-[3px] {{ $cellTone }}"
                                        title="{{ $cellLabel }}"
                                        aria-label="{{ $cellLabel }}"
                                    ></span>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-brand-moss">
                                @if ($activityTotal === 0)
                                    {{ __('No database backup runs in the last 14 days.') }}
                                @else
                                    {{ trans_choice(':count run|:count runs', $activityTotal, ['count' => number_format($activityTotal)]) }}
                                    {{ __('across every server in this workspace.') }}
                                @endif
                            </p>
                        </div>
                    </div>

                    @php
                        $metricCards = [
                            [
                                'label' => __('Last successful'),
                                'value' => $lastSuccessAt ? $lastSuccessAt->diffForHumans(short: true) : __('Never'),
                                'icon' => 'heroicon-o-check-circle',
                                'tone' => $lastSuccessAt ? 'text-brand-sage' : 'text-brand-mist',
                                'hint' => $lastSuccessAt?->format('Y-m-d H:i'),
                            ],
                            [
                                'label' => __('Completed (7d)'),
                                'value' => number_format($metrics['completed7d']),
                                'icon' => 'heroicon-o-arrow-path',
                                'tone' => 'text-brand-forest',
                                'hint' => null,
                            ],
                            [
                                'label' => __('Failed (7d)'),
                                'value' => number_format($metrics['failed7d']),
                                'icon' => 'heroicon-o-exclamation-circle',
                                'tone' => $metrics['failed7d'] > 0 ? 'text-brand-rust' : 'text-brand-mist',
                                'hint' => null,
                            ],
                            [
                                'label' => __('Storage used'),
                                'value' => $metrics['storage'],
                                'icon' => 'heroicon-o-cloud-arrow-up',
                                'tone' => 'text-brand-moss',
                                'hint' => null,
                            ],
                        ];
                    @endphp
                    <dl class="grid grid-cols-2 gap-px border-t border-brand-ink/5 bg-brand-ink/5 sm:grid-cols-4">
                        @foreach ($metricCards as $card)
                            <div class="bg-white px-4 py-2.5">
                                <dt class="flex items-center gap-1 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                    <x-dynamic-component :component="$card['icon']" class="h-3.5 w-3.5 shrink-0 {{ $card['tone'] }}" aria-hidden="true" />
                                    <span class="truncate">{{ $card['label'] }}</span>
                                </dt>
                                <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink" @if ($card['hint']) title="{{ $card['hint'] }}" @endif>
                                    {{ $card['value'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-slot:stats>

                <x-slot:tabs>
                    <x-backups-subnav active="databases" />
                </x-slot:tabs>

                {{-- Unprotected servers — the one thing worth acting on from here. --}}
                @if ($unprotectedServers->isNotEmpty())
                    <section class="border-b border-brand-ink/10">
                        <x-workspace-panel-head
                            dense
                            tone="amber"
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-shield-exclamation"
                            :title="__('Unprotected servers')"
                            :count="$unprotectedServers->count()"
                            :note="__('No active schedule — open a server to add one.')"
                        />
                        <div class="flex flex-wrap gap-1.5 px-3 py-3 sm:px-4">
                            @foreach ($unprotectedServers->take(12) as $server)
                                <a
                                    href="{{ route('servers.backups', $server) }}"
                                    wire:navigate
                                    wire:key="unprotected-{{ $server->id }}"
                                    class="group inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50/70 px-2.5 py-1 text-xs font-semibold text-amber-900 transition-colors hover:border-amber-300 hover:bg-amber-100"
                                >
                                    <x-heroicon-o-server class="h-3.5 w-3.5 shrink-0 text-amber-700" aria-hidden="true" />
                                    <span class="max-w-[12rem] truncate">{{ $server->name }}</span>
                                    <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0 text-amber-600 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                                </a>
                            @endforeach
                            @if ($unprotectedServers->count() > 12)
                                <span class="inline-flex items-center px-1 text-xs text-brand-mist">
                                    {{ __('+:count more', ['count' => $unprotectedServers->count() - 12]) }}
                                </span>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($neverUsed)
                    {{-- Onboarding splash: nothing scheduled, nothing ever run. --}}
                    <section class="border-b border-brand-ink/10 px-4 py-8 sm:px-6 sm:py-10">
                        <div class="mx-auto max-w-2xl text-center">
                            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-archive-box class="h-6 w-6" aria-hidden="true" />
                            </span>
                            <h3 class="mt-4 text-base font-semibold tracking-tight text-brand-ink">{{ __('Nothing is being backed up yet') }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                {{ __('dply dumps your databases on a schedule you choose, ships them to storage you own, and keeps a downloadable copy one click away. Set the first one up from any server.') }}
                            </p>
                            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                                <a
                                    href="{{ route('servers.index') }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                                >
                                    <x-heroicon-o-shield-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Protect a server') }}
                                </a>
                                <a
                                    href="{{ route('profile.backup-configurations') }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-cloud-arrow-up class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Add a destination') }}
                                </a>
                            </div>
                        </div>

                        @php
                            $capabilities = [
                                [
                                    'icon' => 'heroicon-o-clock',
                                    'title' => __('On a schedule'),
                                    'body' => __('Pick a cadence per database or site. Runs land in the history below with size and status.'),
                                ],
                                [
                                    'icon' => 'heroicon-o-cloud-arrow-up',
                                    'title' => __('Into your own storage'),
                                    'body' => __('Point schedules at any S3-compatible bucket. Your keys, your bucket, your retention.'),
                                ],
                                [
                                    'icon' => 'heroicon-o-arrow-down-tray',
                                    'title' => __('Or right now'),
                                    'body' => __('Stream a fresh dump straight to your browser — no schedule and no bucket required.'),
                                ],
                            ];
                        @endphp
                        <ul class="mx-auto mt-8 grid max-w-4xl gap-3 sm:grid-cols-3">
                            @foreach ($capabilities as $capability)
                                <li class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-white text-brand-forest ring-1 ring-brand-ink/10">
                                        <x-dynamic-component :component="$capability['icon']" class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ $capability['title'] }}</p>
                                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $capability['body'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @else
                    {{-- Schedules --}}
                    <section class="border-b border-brand-ink/10">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-clock"
                            :title="__('Schedules')"
                            :count="$schedules->isNotEmpty() ? $schedules->count() : null"
                            :note="__('Recurring backups across servers — manage targets from each server workspace.')"
                        />

                        @if ($schedules->isEmpty())
                            <div class="px-3 py-6 text-center sm:px-4">
                                <x-heroicon-o-clock class="mx-auto h-7 w-7 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-sm text-brand-moss">{{ __('No backup schedules yet.') }}</p>
                                <p class="mt-0.5 text-xs text-brand-mist">{{ __('Add schedules from a server Backups workspace.') }}</p>
                                <a href="{{ route('servers.index') }}" wire:navigate class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                    {{ __('Go to servers') }}
                                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                                </a>
                            </div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-brand-ink/10 bg-brand-sand/15 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                            <th class="px-3 py-2 sm:px-4">{{ __('Server') }}</th>
                                            <th class="px-3 py-2">{{ __('Target') }}</th>
                                            <th class="px-3 py-2">{{ __('Cadence') }}</th>
                                            <th class="px-3 py-2">{{ __('Status') }}</th>
                                            <th class="px-3 py-2">{{ __('Last run') }}</th>
                                            <th class="px-3 py-2">{{ __('Destination') }}</th>
                                            <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/8">
                                        @foreach ($schedules as $schedule)
                                            <tr wire:key="schedule-{{ $schedule->id }}" @class([
                                                'hover:bg-brand-sand/10',
                                                'opacity-60' => ! $schedule->is_active,
                                            ])>
                                                <td class="px-3 py-2 sm:px-4 font-medium text-brand-ink">
                                                    <a href="{{ route('servers.backups', $schedule->server) }}" wire:navigate class="hover:text-brand-sage">
                                                        {{ $schedule->server?->name ?? '—' }}
                                                    </a>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div class="flex items-center gap-1.5">
                                                        <span @class([
                                                            'inline-flex shrink-0 items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                                            'bg-brand-forest/10 text-brand-forest' => $schedule->target_type === 'database',
                                                            'bg-brand-gold/20 text-amber-800' => $schedule->target_type !== 'database',
                                                        ])>
                                                            {{ $schedule->target_type === 'database' ? __('DB') : __('Files') }}
                                                        </span>
                                                        <span class="min-w-0 truncate font-medium text-brand-ink">{{ $schedule->targetLabel() }}</span>
                                                    </div>
                                                </td>
                                                <td class="px-3 py-2 text-xs">
                                                    @if ($cronDesc = $schedule->cronDescription())
                                                        <span class="block text-brand-moss">{{ $cronDesc }}</span>
                                                        <span class="mt-0.5 block font-mono text-2xs text-brand-mist">{{ $schedule->cron_expression }}</span>
                                                    @else
                                                        <span class="font-mono text-brand-moss">{{ $schedule->cron_expression }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2">
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
                                                <td class="px-3 py-2 text-xs text-brand-moss">
                                                    {{ $schedule->last_run_at ? $schedule->last_run_at->diffForHumans() : __('Never') }}
                                                </td>
                                                <td class="px-3 py-2 text-xs text-brand-moss">
                                                    {{ $schedule->backupConfiguration?->name ?? __('Server default') }}
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    <div class="flex items-center justify-end gap-1.5">
                                                        <button
                                                            type="button"
                                                            wire:click="runScheduleNow('{{ $schedule->id }}')"
                                                            wire:loading.attr="disabled"
                                                            class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                                            title="{{ __('Run now') }}"
                                                        >
                                                            <x-heroicon-o-play class="h-3.5 w-3.5" aria-hidden="true" />
                                                            {{ __('Run') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            wire:click="toggleSchedule('{{ $schedule->id }}')"
                                                            wire:loading.attr="disabled"
                                                            class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-moss shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
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
                                                            class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-brand-ink/10 bg-white text-brand-mist shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
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
                        @endif
                    </section>

                    {{-- Recent runs --}}
                    <section class="border-b border-brand-ink/10">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-circle-stack"
                            :title="__('Recent runs')"
                            :note="__('Last 25 database backup runs across all servers.')"
                        />

                        @if ($recentRuns->isEmpty())
                            <div class="px-3 py-6 text-center sm:px-4">
                                <x-heroicon-o-circle-stack class="mx-auto h-7 w-7 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-sm text-brand-moss">{{ __('No backup runs yet.') }}</p>
                            </div>
                        @else
                            <ul class="divide-y divide-brand-ink/8">
                                @foreach ($recentRuns as $run)
                                    @php
                                        $runTone = match ($run->status) {
                                            'completed' => ['bg-brand-sage/15 text-brand-forest', 'heroicon-m-check', __('Done')],
                                            'failed' => ['bg-brand-rust/10 text-brand-rust', 'heroicon-m-x-mark', __('Failed')],
                                            default => ['bg-brand-gold/20 text-amber-800', 'heroicon-m-ellipsis-horizontal', __('Pending')],
                                        };
                                    @endphp
                                    <li wire:key="run-{{ $run->id }}" class="flex items-center gap-3 px-3 py-2.5 hover:bg-brand-sand/10 sm:px-4">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg {{ $runTone[0] }}">
                                            <x-dynamic-component :component="$runTone[1]" class="h-4 w-4" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-brand-ink">
                                                {{ $run->serverDatabase?->name ?? '—' }}
                                                <span class="text-brand-mist">{{ __('on') }}</span>
                                                {{ $run->serverDatabase?->server?->name ?? '—' }}
                                            </p>
                                            <p class="mt-0.5 truncate text-xs text-brand-mist">
                                                {{ $runTone[2] }}
                                                · {{ $run->bytes ? \Illuminate\Support\Number::fileSize((int) $run->bytes) : __('no artifact') }}
                                                · {{ $run->backupConfiguration?->name ?? __('Server default') }}
                                            </p>
                                        </div>
                                        <time
                                            class="shrink-0 text-xs text-brand-moss"
                                            datetime="{{ $run->created_at->toIso8601String() }}"
                                            title="{{ $run->created_at->format('Y-m-d H:i:s') }}"
                                        >
                                            {{ $run->created_at->diffForHumans(short: true) }}
                                        </time>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                {{-- Utilities: grab a dump now, or manage where scheduled dumps land. --}}
                <div class="grid gap-px bg-brand-ink/10 lg:grid-cols-2">
                    <section class="bg-white">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-arrow-down-tray"
                            :title="__('Quick download')"
                            :note="__('A fresh dump straight to your browser. Capped at :cap.', ['cap' => \Illuminate\Support\Number::fileSize((int) config('quick_download.max_bytes', 262_144_000))])"
                        />

                        @if ($databases->isEmpty())
                            <div class="px-3 py-6 text-center sm:px-4">
                                <x-heroicon-o-circle-stack class="mx-auto h-7 w-7 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-sm text-brand-moss">{{ __('No databases found on your servers.') }}</p>
                            </div>
                        @else
                            <ul class="grid gap-2 px-3 py-3 sm:grid-cols-2 sm:px-4">
                                @foreach ($databases as $database)
                                    <li wire:key="qd-db-{{ $database->id }}" class="flex flex-col gap-2 rounded-xl border border-brand-ink/10 bg-white p-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $database->name }}</p>
                                            <p class="mt-0.5 truncate text-xs text-brand-mist">
                                                {{ $database->server?->name ?? '—' }} · {{ \Illuminate\Support\Str::title($database->engine) }}
                                            </p>
                                        </div>
                                        <div class="flex justify-start">
                                            <x-quick-download.database-link :server="$database->server" :database="$database" :active-key="$qdTargetKey" />
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    <section class="bg-white">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-cloud-arrow-up"
                            :title="__('Storage destinations')"
                            :count="$destinations->isNotEmpty() ? $destinations->count() : null"
                            :note="__('Where scheduled dumps land.')"
                        >
                            <x-slot:actions>
                                <a
                                    href="{{ route('profile.backup-configurations') }}"
                                    wire:navigate
                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Add') }}
                                </a>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        @if ($destinations->isEmpty())
                            <div class="px-3 py-6 text-center sm:px-4">
                                <x-heroicon-o-cloud-arrow-up class="mx-auto h-7 w-7 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-sm text-brand-moss">{{ __('No storage destinations configured yet.') }}</p>
                                <p class="mt-0.5 text-xs text-brand-mist">{{ __('Without one, scheduled dumps stay on the server.') }}</p>
                                <a
                                    href="{{ route('profile.backup-configurations') }}"
                                    wire:navigate
                                    class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink"
                                >
                                    {{ __('Add your first destination') }}
                                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                                </a>
                            </div>
                        @else
                            <ul class="divide-y divide-brand-ink/8">
                                @foreach ($destinations as $destination)
                                    @php
                                        $usedBy = $schedules->where('backup_configuration_id', $destination->id)->count();
                                    @endphp
                                    <li wire:key="dest-{{ $destination->id }}" class="flex items-center gap-3 px-3 py-2.5 sm:px-4">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                                            <x-heroicon-o-archive-box class="h-3.5 w-3.5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-brand-ink">{{ $destination->name }}</p>
                                            <p class="text-xs text-brand-mist">
                                                {{ \App\Models\BackupConfiguration::labelForProvider($destination->provider) }}
                                                @if ($usedBy > 0)
                                                    · {{ trans_choice(':count schedule|:count schedules', $usedBy, ['count' => $usedBy]) }}
                                                @endif
                                            </p>
                                        </div>
                                        <a
                                            href="{{ route('profile.backup-configurations') }}"
                                            wire:navigate
                                            class="shrink-0 text-brand-mist transition-colors hover:text-brand-ink"
                                            title="{{ __('Edit') }}"
                                        >
                                            <x-heroicon-o-pencil-square class="h-4 w-4" aria-hidden="true" />
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </x-profile-shell>
        @endif
    </div>
</div>
