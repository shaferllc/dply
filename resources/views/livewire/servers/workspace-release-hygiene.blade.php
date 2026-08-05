@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    ];

    $overallTone = match ($report['overall']) {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };

    $overallLabel = match ($report['overall']) {
        'critical' => __('Needs attention'),
        'warning' => __('Review cleanup'),
        default => __('Healthy headroom'),
    };

    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="hygiene"
    :title="__('Release hygiene')"
    :description="__('Atomic release pressure, Laravel log sizes, failed queue jobs, and disk headroom — the silent causes of deploy failures.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Hygiene') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Atomic release pressure, Laravel log sizes, failed queue jobs, and disk headroom — the silent causes of deploy failures.') }}
                        </p>
                    </div>
                </div>
                <div @class(['inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1', $overallTone])>
                    @switch($report['overall'])
                        @case('critical')
                            <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @case('warning')
                            <x-heroicon-o-exclamation-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @default
                            <x-heroicon-o-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    @endswitch
                    {{ $overallLabel }}
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Release hygiene sections')" scroll class="!mb-0 border-0 bg-transparent p-0 shadow-none">
                <x-server-workspace-tab
                    id="hygiene-tab-overview"
                    icon="heroicon-o-archive-box"
                    :active="$hygiene_tab === 'overview'"
                    wire:click="setHygieneTab('overview')"
                >
                    {{ __('Overview') }}
                    @if ($report['alert_count'] > 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-amber-900">{{ number_format($report['alert_count']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="hygiene-tab-releases"
                    icon="heroicon-o-square-3-stack-3d"
                    :active="$hygiene_tab === 'releases'"
                    wire:click="setHygieneTab('releases')"
                >
                    {{ __('Releases') }}
                    @if (($report['releases']['sites_over_keep'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-amber-900">{{ number_format((int) $report['releases']['sites_over_keep']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="hygiene-tab-logs"
                    icon="heroicon-o-document-text"
                    :active="$hygiene_tab === 'logs'"
                    wire:click="setHygieneTab('logs')"
                >
                    {{ __('Logs & jobs') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="hygiene-tab-notifications"
                    icon="heroicon-o-bell"
                    :active="$hygiene_tab === 'notifications'"
                    wire:click="setHygieneTab('notifications')"
                >
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        <div wire:loading.block wire:target="setHygieneTab" class="px-5 py-6 sm:px-6" aria-busy="true">
            <span class="sr-only">{{ __('Loading…') }}</span>
            <div class="space-y-3" aria-hidden="true">
                <div class="flex items-start gap-3">
                    <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                @foreach (range(1, 4) as $row)
                    <div class="flex items-start gap-3 border-t border-brand-ink/10 pt-3">
                        <span class="mt-1 h-5 w-14 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-3/4 max-w-md animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:loading.remove wire:target="setHygieneTab">
            @if ($hygiene_tab === 'overview')
                {{-- Overview --}}
                <div>
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}">
                                <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Overall') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                    @switch($report['overall'])
                                        @case('critical') {{ __('Disk or release pressure') }} @break
                                        @case('warning') {{ __('Review cleanup') }} @break
                                        @default {{ __('Healthy headroom') }}
                                    @endswitch
                                </h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    @if ($report['scan']['checked_at'])
                                        {{ __('Last scan :time', ['time' => $report['scan']['checked_at']->diffForHumans()]) }}
                                        @if ($report['scan']['stale'])
                                            · <span class="font-medium text-amber-800">{{ __('stale') }}</span>
                                        @endif
                                    @else
                                        {{ __('No SSH scan on record yet.') }}
                                    @endif
                                    @if ($report['disk']['pct'] !== null)
                                        · {{ __('Disk :pct%', ['pct' => number_format($report['disk']['pct'], 0)]) }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        @if ($opsReady && ! $isDeployer)
                            {{-- Busy is driven by $hygieneScanning (a queued-scan flag the
                                 poll clears), not wire:loading — so it can't get stuck. --}}
                            <x-spinner-button
                                wire:click="refreshReleaseHygieneScan"
                                :busy="$hygieneScanning"
                                target="refreshReleaseHygieneScan"
                                icon="heroicon-o-arrow-path"
                                :label="__('Scan disk')"
                                :busy-label="__('Scanning…')"
                            />
                        @endif
                    </div>

                    @if ($hygieneScanTimedOut)
                        {{-- Poll budget exhausted with no result — usually a stopped scan
                             worker. Stop spinning and offer an explicit retry. --}}
                        <div class="px-5 py-8 text-center text-sm text-brand-moss sm:px-6">
                            <x-heroicon-o-clock class="mx-auto h-6 w-6 text-brand-mist" aria-hidden="true" />
                            <p class="mt-2 font-medium text-brand-ink">{{ __('Scan didn\'t return in time') }}</p>
                            <p class="mt-1">{{ __('The disk scan was queued but no result came back. The scan worker may be busy or offline.') }}</p>
                            @if (! empty($hygieneScanProgress))
                                <div class="mx-auto mt-4 max-h-40 max-w-xl overflow-y-auto rounded-md border border-brand-ink/10 bg-brand-ink/[0.03] px-3 py-2 text-left font-mono text-[11px] leading-relaxed text-brand-ink/70">
                                    @foreach ($hygieneScanProgress as $entry)
                                        <div class="break-all">{{ $entry['line'] ?? '' }}</div>
                                    @endforeach
                                </div>
                            @endif
                            <div class="mt-4 flex justify-center">
                                <x-spinner-button
                                    wire:click="refreshReleaseHygieneScan"
                                    target="refreshReleaseHygieneScan"
                                    icon="heroicon-o-arrow-path"
                                    :label="__('Retry scan')"
                                    :busy-label="__('Scanning…')"
                                />
                            </div>
                        </div>
                    @elseif ($hygieneScanning)
                        {{-- Scanning: poll until the job writes a result (or the budget runs
                             out above). The captured frames replay once the result lands. --}}
                        <div wire:poll.{{ $this->hygieneScanPollInterval() }}s="pollReleaseHygieneScan" class="px-5 py-8 sm:px-6">
                            <span class="inline-flex items-center gap-2 text-sm text-brand-moss">
                                <x-spinner class="h-4 w-4" aria-hidden="true" /> {{ __('Scanning disk over SSH…') }}
                            </span>
                            @if (! empty($hygieneScanProgress))
                                <div class="mt-4 max-h-40 overflow-y-auto rounded-md border border-brand-ink/10 bg-brand-ink/[0.03] px-3 py-2 font-mono text-[11px] leading-relaxed text-brand-ink/70">
                                    @foreach ($hygieneScanProgress as $entry)
                                        <div class="break-all">{{ $entry['line'] ?? '' }}</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <x-replay-log :frames="$hygieneScanProgress">
                            @if ($hygieneScanError)
                                <div class="border-b border-rose-200 bg-rose-50/70 px-5 py-3 text-sm text-rose-900 sm:px-6">{{ $hygieneScanError }}</div>
                            @endif
                            @if ($report['scan']['never_scanned'] && $report['alert_count'] === 0)
                                <x-empty-state
                                    borderless
                                    icon="heroicon-o-archive-box"
                                    :title="__('No hygiene scan yet')"
                                    :description="__('Run a scan over SSH to measure release folders, Laravel logs, and failed jobs on disk.')"
                                >
                                    @if ($opsReady && ! $isDeployer)
                                        <x-slot:actions>
                                            <x-spinner-button
                                                wire:click="refreshReleaseHygieneScan"
                                                :busy="$hygieneScanning"
                                                target="refreshReleaseHygieneScan"
                                                icon="heroicon-o-arrow-path"
                                                :label="__('Scan disk')"
                                                :busy-label="__('Scanning…')"
                                            />
                                        </x-slot:actions>
                                    @endif
                                </x-empty-state>
                            @elseif (! empty($report['alerts']))
                                <ul class="divide-y divide-brand-ink/10">
                                    @foreach ($report['alerts'] as $alert)
                                        @php
                                            $alertTone = match ($alert['severity']) {
                                                'critical' => $tonePalette['rose'],
                                                'warning' => $tonePalette['amber'],
                                                default => $tonePalette['sage'],
                                            };
                                        @endphp
                                        <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-4 sm:px-6">
                                            <div class="flex min-w-0 items-start gap-3">
                                                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $alertTone }}">
                                                    <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                                                    <p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p>
                                                </div>
                                            </div>
                                            @if ($alert['href'] && $alert['link_label'])
                                                <a href="{{ $alert['href'] }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                                    {{ $alert['link_label'] }}
                                                    <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                                                </a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <x-empty-state
                                    borderless
                                    tone="sage"
                                    icon="heroicon-o-check-circle"
                                    :title="__('All clear')"
                                    :description="__('No release, log, or failed-job alerts from the latest data.')"
                                />
                            @endif
                        </x-replay-log>
                    @endif
                </div>
            @endif

            @if ($hygiene_tab === 'releases')
                {{-- Releases --}}
                <div>
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-square-3-stack-3d class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Releases') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Atomic releases') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Stored in Dply vs on-disk release folders from the last scan.') }}</p>
                        </div>
                    </div>
                    <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                        @if ($report['releases']['atomic_site_count'] === 0)
                            <x-empty-state
                                borderless
                                icon="heroicon-o-rectangle-stack"
                                :title="__('No atomic deploy sites')"
                                :description="__('Sites using atomic releases will show their stored-vs-kept release folders here after the next scan.')"
                            />
                        @else
                            <dl class="mb-4 grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Atomic sites') }}</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['releases']['atomic_site_count'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Stored records') }}</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['releases']['total_stored'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Over keep') }}</dt>
                                    <dd class="mt-1 text-2xl font-semibold {{ $report['releases']['sites_over_keep'] > 0 ? 'text-amber-800' : 'text-brand-ink' }}">
                                        {{ $report['releases']['sites_over_keep'] }}
                                    </dd>
                                </div>
                            </dl>

                            <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                                <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                                    <thead class="bg-brand-sand/30 text-brand-moss">
                                        <tr>
                                            <th class="px-3 py-2 font-semibold">{{ __('Site') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ __('Stored') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ __('On disk') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ __('Keep') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ __('Size') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                                        @foreach ($report['releases']['rows'] as $row)
                                            <tr @class(['bg-amber-50/40' => ($row['extra'] ?? 0) > 0])>
                                                <td class="px-3 py-2">
                                                    <a href="{{ $row['href'] }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $row['site_name'] }}</a>
                                                </td>
                                                <td class="px-3 py-2 text-brand-moss">{{ $row['stored'] }}</td>
                                                <td class="px-3 py-2 text-brand-moss">{{ $row['on_disk'] ?: '—' }}</td>
                                                <td class="px-3 py-2 text-brand-moss">{{ $row['keep'] }}</td>
                                                <td class="px-3 py-2 text-brand-moss">{{ $row['release_bytes'] > 0 ? $formatBytes($row['release_bytes']) : '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    @feature('workspace.run')
                    <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Cleanup') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Prune saved command') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $report['prune_command']['description'] }}</p>
                            </div>
                        </div>
                        {{-- Only show the button when there's something to do. When it's
                             already on Run, the body shows an "On your Run tab" status +
                             Open Run link, so a disabled dead button would just be noise. --}}
                        @if (! $isDeployer && ! $report['prune_command']['installed'])
                            <x-spinner-button
                                wire:click="installPruneSavedCommand"
                                target="installPruneSavedCommand"
                                icon="heroicon-o-plus"
                                :label="__('Add to Run')"
                            />
                        @endif
                    </div>
                    <div class="space-y-4 px-5 py-5 sm:px-6">
                        {{-- Status + impact at a glance --}}
                        <div class="flex flex-wrap items-center gap-2 text-[11px] font-semibold">
                            @if ($report['prune_command']['installed'])
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sage/15 px-2.5 py-1 text-brand-forest ring-1 ring-brand-sage/25">
                                    <x-heroicon-o-check-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('On your Run tab') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/60 px-2.5 py-1 text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ __('Not added yet') }}
                                </span>
                            @endif
                            @if (($report['releases']['sites_over_keep'] ?? 0) > 0)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-amber-900">
                                    <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ trans_choice(':count site over keep|:count sites over keep', (int) $report['releases']['sites_over_keep'], ['count' => (int) $report['releases']['sites_over_keep']]) }}
                                </span>
                            @endif
                            @if (($report['releases']['total_stored'] ?? 0) > 0)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/60 px-2.5 py-1 text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ trans_choice(':count release folder stored|:count release folders stored', (int) $report['releases']['total_stored'], ['count' => (int) $report['releases']['total_stored']]) }}
                                </span>
                            @endif
                        </div>

                        {{-- What it does --}}
                        <dl class="grid gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Saved as') }}</dt>
                                <dd class="mt-0.5 font-medium text-brand-ink">{{ $report['prune_command']['name'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Default keep') }}</dt>
                                <dd class="mt-0.5 font-medium text-brand-ink">{{ __(':n newest per site', ['n' => 5]) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Keep a different count') }}</dt>
                                <dd class="mt-0.5"><code class="rounded bg-brand-ink/5 px-1.5 py-0.5 font-mono text-xs text-brand-ink">{{ __('first argument, e.g. 8') }}</code></dd>
                            </div>
                        </dl>

                        <p class="text-xs leading-relaxed text-brand-moss">
                            {{ __('Deletes the oldest release folders under each site\'s releases/ directory (by modified time), keeping the newest few. It runs over SSH from the Run tab so you can review the output and re-run it on demand — the live release stays in place.') }}
                        </p>

                        <a href="{{ route('servers.run', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                            {{ __('Open Run') }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                        </a>
                    </div>
                    @endfeature
                </div>
            @endif

            @if ($hygiene_tab === 'logs')
                {{-- Logs & jobs --}}
                <div>
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Logs') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Logs & failed jobs') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Laravel logs and queue:failed counts from the last SSH scan. View tails the newest lines over SSH.') }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($opsReady && ! $isDeployer)
                                <x-spinner-button
                                    wire:click="refreshReleaseHygieneScan"
                                    :busy="$hygieneScanning"
                                    target="refreshReleaseHygieneScan"
                                    size="xs"
                                    icon="heroicon-o-arrow-path"
                                    :label="__('Scan disk')"
                                    :busy-label="__('Scanning…')"
                                />
                            @endif
                            <a href="{{ route('servers.logs', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-forest hover:bg-brand-sand/40">
                                {{ __('Full log viewer') }}
                                <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                            </a>
                        </div>
                    </div>
                    <div class="space-y-4 px-5 py-5 sm:px-6">
                        <dl class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Laravel logs') }}</dt>
                                <dd class="mt-1 text-lg font-semibold text-brand-ink">{{ $formatBytes($report['logs']['laravel_total_bytes']) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Failed jobs') }}</dt>
                                <dd class="mt-1 text-lg font-semibold {{ $report['failed_jobs']['total'] > 0 ? 'text-rose-700' : 'text-brand-ink' }}">
                                    {{ $report['failed_jobs']['total'] }}
                                </dd>
                            </div>
                        </dl>

                        @if (count($report['logs']['site_rows']) > 0)
                            <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                                <table class="min-w-full text-left text-xs">
                                    <thead class="bg-brand-sand/30 text-brand-moss">
                                        <tr>
                                            <th class="px-3 py-2 font-semibold">{{ __('Site') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ __('Laravel log') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ __('Size') }}</th>
                                            @if ($opsReady && ! $isDeployer)
                                                <th class="px-3 py-2 font-semibold text-right">{{ __('Read') }}</th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                                        @foreach ($report['logs']['site_rows'] as $row)
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <a href="{{ $row['href'] }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $row['site_name'] }}</a>
                                                </td>
                                                <td class="max-w-[12rem] truncate px-3 py-2 font-mono text-brand-moss" title="{{ $row['path'] }}">{{ $row['path'] ?: '—' }}</td>
                                                <td class="px-3 py-2 text-brand-ink">{{ $row['bytes'] > 0 ? $formatBytes($row['bytes']) : '—' }}</td>
                                                @if ($opsReady && ! $isDeployer)
                                                    <td class="px-3 py-2 text-right">
                                                        @if ($row['path'] !== '')
                                                            <button
                                                                type="button"
                                                                wire:click="viewHygieneLog(@js($row['path']), @js($row['site_name'].' — laravel.log'))"
                                                                wire:loading.attr="disabled"
                                                                wire:target="viewHygieneLog"
                                                                class="font-semibold text-brand-forest hover:underline disabled:opacity-50"
                                                            >
                                                                {{ __('View') }}
                                                            </button>
                                                        @else
                                                            <span class="text-brand-mist">—</span>
                                                        @endif
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if (count($report['failed_jobs']['rows']) > 0)
                            <ul class="space-y-2 text-sm">
                                @foreach ($report['failed_jobs']['rows'] as $row)
                                    <li class="flex items-center justify-between gap-3 rounded-lg border border-brand-ink/10 px-3 py-2">
                                        <a href="{{ $row['href'] }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $row['site_name'] }}</a>
                                        <span class="text-xs font-semibold text-rose-700">{{ trans_choice(':count failed|:count failed', $row['count'], ['count' => $row['count']]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($report['logs']['journal_usage'])
                            <p class="text-xs text-brand-moss">{{ __('Journal') }}: {{ $report['logs']['journal_usage'] }}</p>
                        @endif

                        @if (count($report['logs']['system_logfiles']) > 0)
                            <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                                <table class="min-w-full text-left text-xs">
                                    <thead class="bg-brand-sand/30 text-brand-moss">
                                        <tr>
                                            <th class="px-3 py-2 font-semibold">{{ __('System log') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ __('Size') }}</th>
                                            @if ($opsReady && ! $isDeployer)
                                                <th class="px-3 py-2 font-semibold text-right">{{ __('Read') }}</th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                                        @foreach ($report['logs']['system_logfiles'] as $file)
                                            <tr>
                                                <td class="px-3 py-2 font-mono text-brand-moss">{{ $file['path'] }}</td>
                                                <td class="px-3 py-2 text-brand-ink">{{ $formatBytes($file['bytes']) }}</td>
                                                @if ($opsReady && ! $isDeployer)
                                                    <td class="px-3 py-2 text-right">
                                                        <button
                                                            type="button"
                                                            wire:click="viewHygieneLog(@js($file['path']), @js($file['path']))"
                                                            wire:loading.attr="disabled"
                                                            wire:target="viewHygieneLog"
                                                            class="font-semibold text-brand-forest hover:underline disabled:opacity-50"
                                                        >
                                                            {{ __('View') }}
                                                        </button>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($hygiene_tab === 'notifications')
                @include('livewire.servers.partials.release-hygiene.notifications-tab')
            @endif
        </div>
    </section>

    @if ($showHygieneLogModal)
        <x-modal name="hygiene-log-view" :show="true" wire:model="showHygieneLogModal" max-width="4xl">
            <div class="space-y-4 p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-brand-moss">{{ __('Log tail') }}</p>
                        <p class="break-all font-mono text-sm font-semibold text-brand-ink">{{ $hygieneLogLabel }}</p>
                        @if ($hygieneLogPath)
                            <p class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $hygieneLogPath }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-spinner-button
                            wire:click="refreshHygieneLog"
                            target="refreshHygieneLog,viewHygieneLog"
                            size="xs"
                            icon="heroicon-o-arrow-path"
                            :label="__('Refresh')"
                        />
                        <button type="button" wire:click="closeHygieneLogModal" class="text-sm text-brand-moss hover:underline">{{ __('Close') }}</button>
                    </div>
                </div>

                <div class="flex flex-wrap items-end gap-3 text-sm">
                    <div>
                        <label for="hygiene-log-tail-lines" class="text-xs font-medium text-brand-moss">{{ __('Lines to tail') }}</label>
                        <input
                            id="hygiene-log-tail-lines"
                            type="number"
                            wire:model="hygieneLogTailLines"
                            min="50"
                            max="5000"
                            class="mt-1 block w-24 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs text-brand-ink"
                        />
                    </div>
                    <x-spinner-button
                        wire:click="refreshHygieneLog"
                        target="refreshHygieneLog"
                        :label="__('Apply')"
                    />
                </div>

                @if ($hygieneLogError)
                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $hygieneLogError }}</div>
                @elseif ($hygieneLogOutput !== null && $hygieneLogOutput !== '')
                    <pre class="max-h-[60vh] overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/5 p-3 text-xs leading-relaxed text-brand-ink"><code>{{ $hygieneLogOutput }}</code></pre>
                @else
                    <p class="text-sm text-brand-moss">{{ __('No output yet.') }}</p>
                @endif
            </div>
        </x-modal>
    @endif

    {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
         shared with the Notifications tab so an operator can add a channel without
         leaving the page; the new channel is auto-selected on success. --}}
    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
