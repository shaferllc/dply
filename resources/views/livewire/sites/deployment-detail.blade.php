@php
    $durationMs = $deployment->phaseTotalDurationMs();
    if ($durationMs <= 0 && $deployment->started_at && $deployment->finished_at) {
        $durationMs = $deployment->started_at->diffInMilliseconds($deployment->finished_at);
    }
    $stepCount = collect($phases)->sum(fn ($p) => count($deployment->phaseSteps($p)));
    $statusLabel = $deployment->isBillingBlocked() ? __('blocked — billing') : $deployment->status;
    $statusBadgeClass = match (true) {
        $deployment->status === 'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        $deployment->status === 'failed' => 'bg-rose-50 text-rose-800 ring-rose-200',
        $deployment->status === 'running' => 'bg-amber-50 text-amber-900 ring-amber-200',
        $deployment->isBillingBlocked() => 'bg-amber-100 text-amber-950 ring-amber-300',
        default => 'bg-brand-sand/60 text-brand-ink ring-brand-ink/10',
    };
    $historyUrl = route('sites.deployments.index', ['server' => $server, 'site' => $site, 'tab' => 'history']);
    $timelinePhases = \App\Support\Sites\SiteDeployTimeline::forDeployment($site, $deployment);
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail
        :items="$settingsBreadcrumbs"
        :site="$site"
        doc-contextual
        :contextual-doc-slug="$contextualDocSlug ?? null"
        class="mb-6"
    />

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-rocket-launch"
                    :title="__('Deployment')"
                    :note="$deployment->id"
                >
                    <x-slot:actions>
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                            $statusBadgeClass,
                        ])>{{ $statusLabel }}</span>
                        <a href="{{ $historyUrl }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white/80 px-2.5 py-1 text-[11px] font-semibold text-brand-ink hover:bg-white dark:border-brand-mist/25 dark:bg-zinc-800">
                            <x-heroicon-o-arrow-left class="h-3.5 w-3.5 opacity-70" aria-hidden="true" />
                            {{ __('History') }}
                        </a>
                        @if ($this->showWindowLogCorrelation)
                            <button type="button" wire:click="openLogsForDeploy" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white/80 px-2.5 py-1 text-[11px] font-semibold text-brand-ink hover:bg-white dark:border-brand-mist/25 dark:bg-zinc-800">
                                <x-heroicon-m-bars-3-bottom-left class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                {{ __('Logs') }}
                            </button>
                        @endif
                        <button type="button" wire:click="toggleOutput" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white/80 px-2.5 py-1 text-[11px] font-semibold text-brand-ink hover:bg-white dark:border-brand-mist/25 dark:bg-zinc-800">
                            @if ($showOutput)
                                <x-heroicon-m-eye-slash class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                {{ __('Hide output') }}
                            @else
                                <x-heroicon-m-eye class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                                {{ __('Step output') }}
                            @endif
                        </button>
                    </x-slot:actions>
                </x-workspace-panel-head>

                <dl class="grid grid-cols-2 gap-px border-b border-brand-ink/10 bg-brand-ink/10 sm:grid-cols-4">
                    <div class="bg-white px-3 py-2 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Trigger') }}</dt>
                        <dd class="mt-0.5 truncate text-xs text-brand-ink">{{ $deployment->trigger ?: '—' }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Commit') }}</dt>
                        <dd class="mt-0.5 truncate font-mono text-[11px] text-brand-ink" title="{{ $deployment->git_sha }}">
                            @if ($deployment->git_sha)
                                {{ \Illuminate\Support\Str::limit($deployment->git_sha, 12, '') }}
                            @else
                                <span class="font-sans text-brand-mist">—</span>
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Exit') }}</dt>
                        <dd @class([
                            'mt-0.5 font-mono text-[11px]',
                            'text-rose-700' => $deployment->exit_code !== null && $deployment->exit_code !== 0,
                            'text-brand-ink' => $deployment->exit_code === null || $deployment->exit_code === 0,
                        ])>{{ $deployment->exit_code === null ? '—' : $deployment->exit_code }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Duration') }}</dt>
                        <dd class="mt-0.5 font-mono text-[11px] tabular-nums text-brand-ink">{{ $durationMs > 0 ? number_format($durationMs / 1000, 1).'s' : '—' }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Started') }}</dt>
                        <dd class="mt-0.5 truncate text-xs text-brand-moss" @if ($deployment->started_at) title="{{ $deployment->started_at->toIso8601String() }}" @endif>
                            {{ $deployment->started_at?->diffForHumans() ?? '—' }}
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Finished') }}</dt>
                        <dd class="mt-0.5 truncate text-xs text-brand-moss" @if ($deployment->finished_at) title="{{ $deployment->finished_at->toIso8601String() }}" @endif>
                            {{ $deployment->finished_at?->diffForHumans() ?? '—' }}
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Steps') }}</dt>
                        <dd class="mt-0.5 text-xs tabular-nums text-brand-ink">{{ $stepCount }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Idempotency') }}</dt>
                        <dd class="mt-0.5 truncate font-mono text-[11px] text-brand-moss" title="{{ $deployment->idempotency_key }}">{{ $deployment->idempotency_key ?: '—' }}</dd>
                    </div>
                </dl>

                @if ($deployment->status === 'failed')
                    @include('livewire.sites.partials.deployments._remediation-panel', ['deployment' => $deployment])
                    <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
                        <x-ops-copilot-callout :site="$site" :show="true" />
                    </div>
                @endif

                @if ($timelinePhases === [])
                    <div class="px-3 py-8 text-center text-sm text-brand-moss sm:px-4">
                        {{ __('No phase results recorded for this deployment.') }}
                    </div>
                @else
                    <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4">
                        @include('livewire.sites.partials.deployments._phase-timeline', ['timelinePhases' => $timelinePhases, 'deployment' => $deployment, 'dbFix' => $dbFix ?? null])
                    </div>
                @endif

                @if (trim((string) $deployment->log_output) !== '')
                    <div class="border-b border-brand-ink/10">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-document-text"
                            :title="__('Deploy log')"
                            :note="__('Combined stdout/stderr from the runner.')"
                        />
                        <pre class="max-h-80 overflow-auto bg-brand-ink px-3 py-3 font-mono text-[11px] leading-relaxed text-brand-cream/95 sm:px-4">{{ trim((string) $deployment->log_output) }}</pre>
                    </div>
                @endif

                <div class="bg-brand-sand/25 px-3 py-2 sm:px-4">
                    <x-cli-snippet :command="'dply sites:deployment '.$deployment->id.' --output'" />
                </div>
            </section>
        </div>
    </div>

    @include('livewire.partials.window-logs-drawer')
</div>
