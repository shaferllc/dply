@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="cron"
    :title="__('Cron jobs')"
    :description="__('Schedule commands in the Dply-managed crontab block for this server.')"
    :context-site="$contextSiteModel ?? null"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Cron jobs scheduled here are written into a dply-managed block in the server\'s crontab. The block is rewritten in full on every change — nothing else in the crontab is touched. Use the existing crontab outside the block for things you don\'t want dply to manage.') }}</p>
        <p>{{ __('"Run now" queues an immediate execution of a job, streams output back over SSH, and records the result. The job\'s schedule keeps firing on its normal cadence in parallel; "Run now" is independent.') }}</p>
    </x-explainer>

    @if ($opsReady && $server->organization?->cron_maintenance_until && now()->lt($server->organization->cron_maintenance_until))
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                        <x-heroicon-o-wrench class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Maintenance') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cron maintenance window active') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Managed cron lines are not installed on servers until :time.', ['time' => $server->organization->cron_maintenance_until->timezone(config('app.timezone'))->format('Y-m-d H:i T')]) }}
                            @if (filled($server->organization->cron_maintenance_note))
                                {{ $server->organization->cron_maintenance_note }}
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($siteContextUnavailable)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                        <x-heroicon-o-no-symbol class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Unavailable') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cron jobs are not available for this site’s runtime') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Managed SSH crontab applies to VM-hosted sites. For container or serverless runtimes, use that platform’s scheduler or workers instead.') }}
                        </p>
                        @if ($contextSiteModel)
                            <a href="{{ route('sites.show', [$server, $contextSiteModel]) }}" wire:navigate class="mt-3 inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                <x-heroicon-m-arrow-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Back to site') }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @elseif ($opsReady)
        <div
            id="dply-server-cron-run-context"
            class="hidden"
            aria-hidden="true"
            data-server-id="{{ $server->id }}"
            data-subscribe="{{ $cronRunEchoSubscribable ? '1' : '0' }}"
        ></div>

        <div class="space-y-6">
            @include('livewire.servers.partials.cron._banner')

            {{-- Crontab at a glance: total / enabled / disabled / unsynced. --}}
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['violet'] }}">
                            <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Schedule') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Crontab at a glance') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Counts across the dply-managed block on this server.') }}</p>
                        </div>
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-2 p-6 sm:grid-cols-4 sm:p-7">
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-brand-sage/30 bg-brand-sage/8' => $cronJobCount > 0,
                        'border-brand-ink/10 bg-white' => $cronJobCount === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cron jobs') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $cronJobCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $cronJobCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Managed by dply') }}</p>
                    </div>
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-emerald-200 bg-emerald-50/60' => $enabledCronJobCount > 0,
                        'border-brand-ink/10 bg-white' => $enabledCronJobCount === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Enabled') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $enabledCronJobCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('active|active', $enabledCronJobCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Will fire on schedule') }}</p>
                    </div>
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-amber-200 bg-amber-50/60' => $disabledCronJobCount > 0,
                        'border-brand-ink/10 bg-white' => $disabledCronJobCount === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Disabled') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $disabledCronJobCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('paused|paused', $disabledCronJobCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Held — won’t fire') }}</p>
                    </div>
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-rose-200 bg-rose-50/60' => $unsyncedCronCount > 0,
                        'border-brand-ink/10 bg-white' => $unsyncedCronCount === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Unsynced') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $unsyncedCronCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('drifted|drifted', $unsyncedCronCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Dply ↔ server mismatch') }}</p>
                    </div>
                </dl>
            </section>

            <x-server-workspace-tablist :aria-label="__('Cron workspace sections')">
                <x-server-workspace-tab id="cron-tab-jobs" :active="$cron_workspace_tab === 'jobs'" wire:click="setCronWorkspaceTab('jobs')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-list-bullet class="h-4 w-4" aria-hidden="true" />
                        {{ __('Jobs') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-history" :active="$cron_workspace_tab === 'history'" wire:click="setCronWorkspaceTab('history')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                        {{ __('History') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-inspect" :active="$cron_workspace_tab === 'inspect'" wire:click="setCronWorkspaceTab('inspect')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-command-line class="h-4 w-4" aria-hidden="true" />
                        {{ __('Inspect') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-templates" :active="$cron_workspace_tab === 'templates'" wire:click="setCronWorkspaceTab('templates')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-document-duplicate class="h-4 w-4" aria-hidden="true" />
                        {{ __('Templates') }}
                    </span>
                </x-server-workspace-tab>
                @if ($canUpdateOrg)
                    <x-server-workspace-tab id="cron-tab-maintenance" :active="$cron_workspace_tab === 'maintenance'" wire:click="setCronWorkspaceTab('maintenance')">
                        <span class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-wrench class="h-4 w-4" aria-hidden="true" />
                            {{ __('Maintenance') }}
                        </span>
                    </x-server-workspace-tab>
                @endif
            </x-server-workspace-tablist>

            <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setCronWorkspaceTab">

            @if ($cron_workspace_tab === 'jobs')
                <x-server-workspace-tab-panel
                    id="cron-panel-jobs"
                    labelled-by="cron-tab-jobs"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.jobs-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'history')
                <x-server-workspace-tab-panel
                    id="cron-panel-history"
                    labelled-by="cron-tab-history"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.history-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'inspect')
                <x-server-workspace-tab-panel
                    id="cron-panel-inspect"
                    labelled-by="cron-tab-inspect"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.inspect-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'templates')
                <x-server-workspace-tab-panel
                    id="cron-panel-templates"
                    labelled-by="cron-tab-templates"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.templates-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($canUpdateOrg && $cron_workspace_tab === 'maintenance')
                <x-server-workspace-tab-panel
                    id="cron-panel-maintenance"
                    labelled-by="cron-tab-maintenance"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.maintenance-tab')
                </x-server-workspace-tab-panel>
            @endif

            </div>
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    @if ($contextSiteModel)
        <x-cli-snippet tone="stub" />
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.cron._modals')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
