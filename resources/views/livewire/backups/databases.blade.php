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
            <x-profile-shell
                dense
                :title="__('Backups')"
                :description="__('Schedules, recent runs, and storage destinations across :org.', ['org' => $organization->name])"
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
                    @php
                        $metricCards = [
                            [
                                'label' => __('Completed (7d)'),
                                'value' => number_format($metrics['completed7d']),
                                'icon' => 'heroicon-o-check-circle',
                                'tone' => 'text-brand-sage',
                            ],
                            [
                                'label' => __('Failed (7d)'),
                                'value' => number_format($metrics['failed7d']),
                                'icon' => 'heroicon-o-exclamation-circle',
                                'tone' => $metrics['failed7d'] > 0 ? 'text-brand-rust' : 'text-brand-mist',
                            ],
                            [
                                'label' => __('Storage used'),
                                'value' => $metrics['storage'],
                                'icon' => 'heroicon-o-cloud-arrow-up',
                                'tone' => 'text-brand-moss',
                            ],
                            [
                                'label' => __('Active schedules'),
                                'value' => number_format($metrics['activeSchedules']),
                                'icon' => 'heroicon-o-clock',
                                'tone' => 'text-brand-forest',
                            ],
                            [
                                'label' => __('Unprotected'),
                                'value' => number_format($metrics['unprotectedServers']),
                                'icon' => 'heroicon-o-shield-exclamation',
                                'tone' => $metrics['unprotectedServers'] > 0 ? 'text-amber-600' : 'text-brand-mist',
                            ],
                        ];
                    @endphp
                    <dl class="grid grid-cols-2 gap-px bg-brand-ink/5 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($metricCards as $card)
                            <div class="bg-white px-3 py-2">
                                <dt class="flex items-center gap-1 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                    <x-dynamic-component :component="$card['icon']" class="h-3.5 w-3.5 shrink-0 {{ $card['tone'] }}" aria-hidden="true" />
                                    <span class="truncate">{{ $card['label'] }}</span>
                                </dt>
                                <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $card['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-slot:stats>

                <x-slot:tabs>
                    <x-backups-subnav active="databases" />
                </x-slot:tabs>

                {{-- Schedules --}}
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-clock"
                        :title="__('Schedules')"
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
                                        <tr wire:key="schedule-{{ $schedule->id }}" class="hover:bg-brand-sand/10">
                                            <td class="px-3 py-2 sm:px-4 font-medium text-brand-ink">
                                                <a href="{{ route('servers.backups', $schedule->server) }}" wire:navigate class="hover:text-brand-sage">
                                                    {{ $schedule->server?->name ?? '—' }}
                                                </a>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="flex flex-col gap-0.5">
                                                    <span class="font-medium text-brand-ink">{{ $schedule->targetLabel() }}</span>
                                                    <span @class([
                                                        'inline-flex w-fit items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                                        'bg-brand-forest/10 text-brand-forest' => $schedule->target_type === 'database',
                                                        'bg-brand-gold/20 text-amber-800' => $schedule->target_type !== 'database',
                                                    ])>
                                                        {{ $schedule->target_type === 'database' ? __('DB') : __('Files') }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-xs text-brand-moss">
                                                <span class="font-mono">{{ $schedule->cron_expression }}</span>
                                                @if ($cronDesc = $schedule->cronDescription())
                                                    <span class="mt-0.5 block text-xs text-brand-mist">{{ $cronDesc }}</span>
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

                {{-- Quick download --}}
                @if (! $databases->isEmpty())
                    <section class="border-b border-brand-ink/10">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-arrow-down-tray"
                            :title="__('Quick download')"
                            :note="__('Stream a fresh SQL dump to your browser — no schedule, no S3. Capped at :cap.', ['cap' => \Illuminate\Support\Number::fileSize((int) config('quick_download.max_bytes', 262_144_000))])"
                        />
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-brand-ink/10 bg-brand-sand/15 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                        <th class="px-3 py-2 sm:px-4">{{ __('Database') }}</th>
                                        <th class="px-3 py-2">{{ __('Server') }}</th>
                                        <th class="px-3 py-2">{{ __('Engine') }}</th>
                                        <th class="px-3 py-2 text-right">{{ __('Download') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/8">
                                    @foreach ($databases as $database)
                                        <tr wire:key="qd-db-{{ $database->id }}" class="hover:bg-brand-sand/10">
                                            <td class="px-3 py-2 sm:px-4 font-medium text-brand-ink">{{ $database->name }}</td>
                                            <td class="px-3 py-2 text-xs text-brand-moss">{{ $database->server?->name ?? '—' }}</td>
                                            <td class="px-3 py-2 text-xs text-brand-moss">{{ \Illuminate\Support\Str::title($database->engine) }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <x-quick-download.database-link :server="$database->server" :database="$database" :active-key="$qdTargetKey" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

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
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-brand-ink/10 bg-brand-sand/15 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                        <th class="px-3 py-2 sm:px-4">{{ __('Status') }}</th>
                                        <th class="px-3 py-2">{{ __('Server / Database') }}</th>
                                        <th class="px-3 py-2">{{ __('Size') }}</th>
                                        <th class="px-3 py-2">{{ __('Destination') }}</th>
                                        <th class="px-3 py-2">{{ __('When') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/8">
                                    @foreach ($recentRuns as $run)
                                        <tr wire:key="run-{{ $run->id }}" class="hover:bg-brand-sand/10">
                                            <td class="px-3 py-2 sm:px-4">
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
                                                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500" aria-hidden="true"></span>
                                                        {{ __('Pending') }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="flex flex-col gap-0.5">
                                                    <span class="font-medium text-brand-ink">{{ $run->serverDatabase?->server?->name ?? '—' }}</span>
                                                    <span class="text-xs text-brand-moss">{{ $run->serverDatabase?->name ?? '—' }}</span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-xs text-brand-moss">
                                                {{ $run->bytes ? \Illuminate\Support\Number::fileSize((int) $run->bytes) : '—' }}
                                            </td>
                                            <td class="px-3 py-2 text-xs text-brand-moss">
                                                {{ $run->backupConfiguration?->name ?? __('Server default') }}
                                            </td>
                                            <td class="px-3 py-2 text-xs text-brand-moss">
                                                <time datetime="{{ $run->created_at->toIso8601String() }}" title="{{ $run->created_at->format('Y-m-d H:i:s') }}">
                                                    {{ $run->created_at->diffForHumans() }}
                                                </time>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                {{-- Storage destinations --}}
                <section>
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-cloud-arrow-up"
                        :title="__('Storage destinations')"
                        :note="__('S3-compatible and remote destinations for schedules in this org.')"
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
                                <li wire:key="dest-{{ $destination->id }}" class="flex items-center gap-3 px-3 py-2 sm:px-4">
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
            </x-profile-shell>
        @endif
    </div>
</div>
