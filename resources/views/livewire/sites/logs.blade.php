@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
    ];
    $summary = $report['summary'] ?? [];
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Logs'),
        'currentIcon' => 'clipboard-document-list',
        'contextualDocSlug' => 'vm-site-logs',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Logs')"
                :description="__('Dply activity and system log tailing for this site — live SSH reads with real-time streaming.')"
                :show-documentation="false"
                flush
                compact
            />

            <x-explainer>
                <p>{{ __('This site\'s vhost access &amp; error logs, application log, queue workers, and dply\'s own activity stream — read live from the server over SSH. Use the Viewer tab to tail lines; Overview and Sources summarize what is available for this site.') }}</p>
                <p>{{ __('Time ranges are server-side filters: "Last 5 minutes" reads only the recent slice of each file; broader ranges page through more of the file. Need machine-wide logs (syslog, PHP-FPM, fleet activity)? Open the server logs workspace below.') }}</p>
            </x-explainer>

            <div
                id="dply-server-log-broadcast-context"
                class="hidden"
                aria-hidden="true"
                data-server-id="{{ $server->id }}"
                data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
            ></div>

            {{-- dply Logs app-log stream — only when a logging binding routes to it. --}}
            @if ($hasDplyRealtime)
                <livewire:sites.site-app-logs :site="$site" wire:key="site-app-logs-{{ $site->id }}" />
            @endif

            <x-server-workspace-tablist :aria-label="__('Logs workspace sections')" scroll class="sm:min-w-0 sm:flex-1">
                <x-server-workspace-tab
                    id="logs-tab-viewer"
                    icon="heroicon-o-command-line"
                    :active="$logsTab === 'viewer'"
                    wire:click="setLogsWorkspaceTab('viewer')"
                >
                    {{ __('Viewer') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="logs-tab-overview"
                    icon="heroicon-o-chart-bar-square"
                    :active="$logsTab === 'overview'"
                    wire:click="setLogsWorkspaceTab('overview')"
                >
                    {{ __('Overview') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="logs-tab-sources"
                    icon="heroicon-o-queue-list"
                    :active="$logsTab === 'sources'"
                    wire:click="setLogsWorkspaceTab('sources')"
                >
                    {{ __('Sources') }}
                    @if (($summary['source_count'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-brand-moss">{{ number_format((int) $summary['source_count']) }}</span>
                    @endif
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            @if ($logsTab === 'viewer')
                @include('livewire.servers.partials.log-viewer-panel', ['logSources' => $logSources])
            @endif

            @if ($logsTab === 'overview')
                @include('livewire.servers.partials.logs._tab-overview', [
                    'report' => $report,
                    'tonePalette' => $tonePalette,
                    'server' => $server,
                ])
            @endif

            @if ($logsTab === 'sources')
                @include('livewire.servers.partials.logs._tab-sources', [
                    'report' => $report,
                    'tonePalette' => $tonePalette,
                    'server' => $server,
                ])
            @endif

            {{-- Machine-wide logs live on the server logs workspace. --}}
            <div class="dply-card overflow-hidden">
                <div class="flex flex-col gap-3 bg-brand-sand/20 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div class="flex min-w-0 items-center gap-3">
                        <x-heroicon-o-server-stack class="h-5 w-5 shrink-0 text-brand-moss" aria-hidden="true" />
                        <p class="text-sm text-brand-moss">{{ __('Need syslog, PHP-FPM, or fleet activity for the whole server?') }}</p>
                    </div>
                    <a
                        href="{{ route('servers.logs', $server) }}"
                        wire:navigate
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                        {{ __('Open server logs') }}
                    </a>
                </div>
            </div>

            <x-cli-snippet :commands="[
                ['label' => __('Tail logs'), 'command' => 'dply sites:logs '.$site->slug.' --tail'],
                ['label' => __('Show log files'), 'command' => 'dply sites:logs:list '.$site->slug],
            ]" />
        </main>
    </div>
</div>
