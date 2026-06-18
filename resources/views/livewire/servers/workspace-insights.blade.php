<x-server-workspace-layout
    :server="$server"
    active="insights"
    :title="__('Insights')"
    :description="__('Monitoring, recommendations, and optional fixes for this server.')"
    :pageHeaderToolbar="true"
>
    <x-slot name="headerActions">
        <x-primary-button size="sm" type="button" wire:click="runChecksNow" wire:loading.attr="disabled">
            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="runChecksNow" aria-hidden="true" />
            <span wire:loading.remove wire:target="runChecksNow">{{ __('Refresh') }}</span>
            <span wire:loading wire:target="runChecksNow">{{ __('Queueing…') }}</span>
        </x-primary-button>
    </x-slot>

    @include('livewire.servers.partials.workspace-flashes')

    @if ($server->workspace)
        @feature('surface.projects')
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Project') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Project insight context') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('These findings are scoped to this server. For shared incident context, runbooks, and grouped notifications, use the linked project pages for the broader project view.') }}</p>
                    </div>
                </div>
                <div class="px-6 py-6 sm:px-7">
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-bolt class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Open project operations') }}
                        </a>
                        <a href="{{ route('projects.access', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-shield-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Open project access') }}
                        </a>
                    </div>
                </div>
            </section>
        @endfeature
    @endif

    {{-- Workspace console banner. Three banner sources share one slot — `run` (full
         sweep / single recheck), `fix` (apply-fix per finding), `revert` (revert-fix
         per finding). Precedence: in-flight beats settled; among ties, the
         most-recently-started run wins. Each kind has its own dismiss action so
         clearing one banner doesn't lose state for another that's still queued. --}}
    @php
        $insightsBanner = null;
        $now = '';
        foreach (['run', 'fix', 'revert'] as $kind) {
            $status = (string) data_get($server->meta ?? [], config("insights_workspace.meta_{$kind}_status_key"));
            $runId = (string) data_get($server->meta ?? [], config("insights_workspace.meta_{$kind}_run_id_key"));
            if ($runId === '' || ! in_array($status, ['queued', 'running', 'completed', 'failed', 'refused'], true)) {
                continue;
            }
            $busy = in_array($status, ['queued', 'running'], true);
            $startedAt = (string) data_get($server->meta ?? [], config("insights_workspace.meta_{$kind}_started_at_key"));
            $rank = $busy ? '9999-12-31T23:59:59Z' : $startedAt;
            if ($insightsBanner === null
                || ($busy && ! $insightsBanner['busy'])
                || ($busy === $insightsBanner['busy'] && $rank > $insightsBanner['rank'])
            ) {
                $insightsBanner = [
                    'kind' => $kind,
                    'status' => $status,
                    'busy' => $busy,
                    'rank' => $rank,
                    'started_at' => $startedAt,
                    'finished_at' => (string) data_get($server->meta ?? [], config("insights_workspace.meta_{$kind}_finished_at_key")),
                    'error' => (string) data_get($server->meta ?? [], config("insights_workspace.meta_{$kind}_error_key")),
                    'finding_id' => $kind === 'run'
                        ? null
                        : data_get($server->meta ?? [], config("insights_workspace.meta_{$kind}_finding_id_key")),
                ];
            }
        }

        if ($insightsBanner !== null) {
            $bk = $insightsBanner['kind'];
            $bs = $insightsBanner['status'];
            $bbusy = $insightsBanner['busy'];

            $insightsBanner['output'] = match ($bk) {
                'run' => $this->runOutputLines,
                'fix' => $this->fixOutputLines,
                'revert' => $this->revertOutputLines,
            };

            $insightsBanner['message'] = match ([$bk, $bs]) {
                ['run', 'queued'] => __('Insights run queued — waiting for a worker to pick it up…'),
                ['run', 'running'] => __('Running insight checks on :host …', ['host' => $server->getSshConnectionString()]),
                ['run', 'completed'] => __('Insight checks complete.'),
                ['run', 'failed'] => __('Insight checks failed.'),
                ['fix', 'queued'] => __('Apply fix queued — waiting for a worker to pick it up…'),
                ['fix', 'running'] => __('Applying fix on :host …', ['host' => $server->getSshConnectionString()]),
                ['fix', 'completed'] => __('Fix applied.'),
                ['fix', 'failed'] => __('Fix failed.'),
                ['fix', 'refused'] => __('Fix refused.'),
                ['revert', 'queued'] => __('Revert queued — waiting for a worker to pick it up…'),
                ['revert', 'running'] => __('Reverting fix on :host …', ['host' => $server->getSshConnectionString()]),
                ['revert', 'completed'] => __('Revert complete.'),
                ['revert', 'failed'] => __('Revert failed.'),
                default => '',
            };

            $insightsBanner['subtitle'] = $bbusy
                ? __('Refreshing every 4s · safe to leave this page — the job runs on the queue.')
                : match (true) {
                    in_array($bs, ['failed', 'refused'], true) && $insightsBanner['error'] !== ''
                        => $insightsBanner['error'],
                    $bs === 'completed' && $insightsBanner['finished_at'] !== ''
                        => __('Finished :time', ['time' => \Illuminate\Support\Carbon::parse($insightsBanner['finished_at'])->diffForHumans()]),
                    default => null,
                };

            // Banner status drives color; `refused` reuses the `failed` palette.
            $insightsBanner['banner_status'] = $bs === 'refused' ? 'failed' : $bs;
        }
    @endphp

    @if ($insightsBanner !== null)
        <x-workspace-console-banner
            :status="$insightsBanner['banner_status']"
            :message="$insightsBanner['message']"
            :subtitle="$insightsBanner['subtitle']"
            :output="$insightsBanner['output']"
            :busy="$insightsBanner['busy']"
            :dismiss-action="$insightsBanner['busy'] ? null : 'dismissInsightsBanner(\'' . $insightsBanner['kind'] . '\')'"
            :poll-action="$insightsBanner['busy'] ? 'pollInsightsStatus' : null"
            poll-interval="4s"
            :default-expanded="true"
        />
    @endif

    @php
        $dismissedCount = $dismissedFindings->count() + $ignoredSuggestions->count();
    @endphp
    <x-server-workspace-tablist ariaLabel="{{ __('Insights sections') }}">
        <x-server-workspace-tab wire:click="setTab('overview')" :active="$tab === 'overview'">
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-list-bullet class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Overview') }}
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab wire:click="setTab('dismissed')" :active="$tab === 'dismissed'">
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-eye-slash class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Dismissed') }}
                @if ($dismissedCount > 0)
                    <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $dismissedCount }}</span>
                @endif
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab wire:click="setTab('notifications')" :active="$tab === 'notifications'">
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-bell-alert class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Notifications') }}
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab wire:click="setTab('settings')" :active="$tab === 'settings'">
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-cog-6-tooth class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Settings') }}
            </span>
        </x-server-workspace-tab>
    </x-server-workspace-tablist>

    @if ($tab === 'overview' && $bannerFindings->isNotEmpty())
        <div role="alert" aria-live="polite" class="rounded-2xl border border-red-200 bg-red-50/70 shadow-sm">
            <div class="flex items-start gap-3 px-5 py-4 border-b border-red-200/80">
                <x-heroicon-s-exclamation-triangle class="h-5 w-5 shrink-0 text-red-700 mt-0.5" aria-hidden="true" />
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-red-900">{{ __('Critical attention required') }}</h2>
                    <p class="mt-0.5 text-xs text-red-900/80">{{ __('Acknowledge to clear from this banner. Recurring issues will resurface automatically.') }}</p>
                </div>
            </div>
            <ul class="divide-y divide-red-200/70">
                @foreach ($bannerFindings as $b)
                    @php
                        // Mirror the list-row fix-state derivation so banner items
                        // show the queued / terminal pill from the same source of
                        // truth (the finding's meta).
                        $bMeta = is_array($b->meta) ? $b->meta : [];
                        $bFixStatus = match (true) {
                            isset($bMeta['fix_applied_at']) && is_string($bMeta['fix_applied_at']) && $bMeta['fix_applied_at'] !== '' => 'succeeded',
                            isset($bMeta['fix_failed_at']) && is_string($bMeta['fix_failed_at']) && $bMeta['fix_failed_at'] !== '' => 'failed',
                            isset($bMeta['fix_refused_at']) && is_string($bMeta['fix_refused_at']) && $bMeta['fix_refused_at'] !== '' => 'refused',
                            isset($bMeta['fix_run_started_at']) && is_string($bMeta['fix_run_started_at']) && $bMeta['fix_run_started_at'] !== '' => 'queued',
                            default => 'idle',
                        };
                    @endphp
                    <li class="flex flex-wrap items-start justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-red-950 break-words [overflow-wrap:anywhere]">{{ $b->title }}</p>
                                @if ($bFixStatus === 'queued')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900 ring-1 ring-amber-300">
                                        <x-heroicon-o-arrow-path class="h-3 w-3 shrink-0 animate-spin" aria-hidden="true" />
                                        {{ __('Fix queued…') }}
                                    </span>
                                @elseif ($bFixStatus === 'failed')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-red-900 ring-1 ring-red-300">
                                        <x-heroicon-o-x-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ __('Fix failed') }}
                                    </span>
                                @elseif ($bFixStatus === 'refused')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900 ring-1 ring-amber-300">
                                        <x-heroicon-o-no-symbol class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ __('Fix refused') }}
                                    </span>
                                @endif
                            </div>
                            @if ($b->body)
                                <p class="mt-1 text-sm leading-snug text-red-900/85 whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $b->body }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="acknowledgeFinding({{ $b->id }})" wire:loading.attr="disabled" wire:target="acknowledgeFinding({{ $b->id }})" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-red-900 shadow-sm hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50">
                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Dismiss') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($tab === 'overview')
        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-list-bullet class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Findings') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Open findings') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Server-scoped open findings appear here. Site-specific items are on each site’s Insights page.') }}</p>
                </div>
            </div>
            @if ($findings->isEmpty())
                <div class="px-5 py-10 text-center">
                    <p class="text-sm font-medium text-brand-ink">{{ __('No open findings right now.') }}</p>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Run a refresh, wait for the scheduled job, or review settings if you expected a signal here.') }}</p>
                    <div class="mt-4">
                        <x-primary-button size="sm" type="button" wire:click="runChecksNow" wire:loading.attr="disabled" wire:target="runChecksNow">
                            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="runChecksNow" aria-hidden="true" />
                            <span wire:loading.remove wire:target="runChecksNow">{{ __('Refresh') }}</span>
                            <span wire:loading wire:target="runChecksNow">{{ __('Queueing…') }}</span>
                        </x-primary-button>
                    </div>
                    <div class="mt-4 inline-flex flex-wrap items-center justify-center gap-2 text-xs text-brand-mist">
                        <span class="rounded-full border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5">
                            {{ trans_choice('{1} :count enabled check|[2,*] :count enabled checks', $enabledChecks, ['count' => $enabledChecks]) }}
                        </span>
                        <span class="rounded-full border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5">
                            {{ trans_choice('{1} :count implemented runner enabled|[2,*] :count implemented runners enabled', $implementedChecks, ['count' => $implementedChecks]) }}
                        </span>
                    </div>
                    <p class="mt-4 max-w-2xl mx-auto text-xs leading-6 text-brand-mist">
                        {{ __('Common reasons for an empty list: the server has not finished a queued run yet, matching checks are disabled, some checks only emit findings when thresholds are crossed, or the server does not have recent metrics / matching conditions for those checks.') }}
                    </p>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($findings as $f)
                        @php
                            $fix = config('insights.insights.'.$f->insight_key.'.fix');
                            $canFix = is_array($fix) && ($fix['handler'] ?? null);
                            // All datetime renders below funnel through ServerDateFormatter so the
                            // operator's server-level format + timezone preference (Settings →
                            // Reference) wins over raw UTC.
                            $fmt = fn ($v) => \App\Support\Servers\ServerDateFormatter::format($v, $server);

                            // Derive fix-run state from meta so the row can show whether the
                            // background job is queued/running, succeeded, or failed. Mirrors
                            // the logic in WorkspaceInsights::selectedFindingDetail so the
                            // list and modal stay consistent.
                            $fMeta = is_array($f->meta) ? $f->meta : [];
                            $fixRunStatus = match (true) {
                                isset($fMeta['fix_applied_at']) && is_string($fMeta['fix_applied_at']) && $fMeta['fix_applied_at'] !== '' => 'succeeded',
                                isset($fMeta['fix_failed_at']) && is_string($fMeta['fix_failed_at']) && $fMeta['fix_failed_at'] !== '' => 'failed',
                                isset($fMeta['fix_refused_at']) && is_string($fMeta['fix_refused_at']) && $fMeta['fix_refused_at'] !== '' => 'refused',
                                isset($fMeta['fix_run_started_at']) && is_string($fMeta['fix_run_started_at']) && $fMeta['fix_run_started_at'] !== '' => 'queued',
                                default => 'idle',
                            };
                            $fixInFlight = $fixRunStatus === 'queued';
                            [$fixRunChip, $fixRunLabel, $fixRunIcon] = match ($fixRunStatus) {
                                'queued' => ['bg-amber-100 text-amber-900 ring-amber-300', __('Fix queued…'), 'heroicon-o-arrow-path'],
                                'succeeded' => ['bg-emerald-100 text-emerald-900 ring-emerald-300', __('Fix succeeded'), 'heroicon-o-check-circle'],
                                'failed' => ['bg-red-100 text-red-900 ring-red-300', __('Fix failed'), 'heroicon-o-x-circle'],
                                'refused' => ['bg-amber-100 text-amber-900 ring-amber-300', __('Fix refused'), 'heroicon-o-no-symbol'],
                                default => [null, null, null],
                            };
                        @endphp
                        @php
                            [$accent, $iconBg, $iconText, $sevIconComponent, $sevLabel] = match ($f->severity) {
                                'critical' => ['bg-red-500', 'bg-red-50', 'text-red-700', 'heroicon-s-exclamation-triangle', __('Critical')],
                                'warning' => ['bg-amber-500', 'bg-amber-50', 'text-amber-700', 'heroicon-s-exclamation-circle', __('Warning')],
                                'info' => ['bg-sky-400', 'bg-sky-50', 'text-sky-700', 'heroicon-s-information-circle', __('Info')],
                                default => ['bg-brand-mist', 'bg-brand-sand/60', 'text-brand-moss', 'heroicon-s-bell', __('Notice')],
                            };
                        @endphp
                        {{-- Stretched-link card pattern: a transparent absolute-positioned button
                             covers the entire row and triggers the detail modal. Action buttons
                             inside the card get `relative z-10` so they remain interactive on top
                             of the overlay. Hover state on the `group` (the <li>) drives bg tint,
                             accent-bar widening, and the View details pill so the whole row reads
                             as clickable. --}}
                        <li class="group relative cursor-pointer transition-colors hover:bg-brand-sand/25">
                            <span class="absolute inset-y-3 left-0 w-1 rounded-full {{ $accent }} transition-all group-hover:w-1.5" aria-hidden="true"></span>
                            <button
                                type="button"
                                wire:click="openFindingDetail({{ $f->id }})"
                                aria-label="{{ __('Open details for :title', ['title' => $f->title]) }}"
                                class="absolute inset-0 z-0 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-forest focus-visible:outline-offset-[-2px]"
                            ></button>

                            <div class="relative pointer-events-none flex items-start gap-4 px-5 py-4 pl-6">
                                <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $iconBg }}">
                                    <x-dynamic-component :component="$sevIconComponent" class="h-4 w-4 {{ $iconText }}" aria-hidden="true" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    {{-- Top meta line: severity · time · acknowledged?  with the
                                         View details affordance pinned to the right so it's
                                         always visible regardless of body length. --}}
                                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] font-semibold uppercase tracking-wide leading-none">
                                            <span class="{{ $iconText }}">{{ $sevLabel }}</span>
                                            <span class="text-brand-mist">·</span>
                                            <span class="text-brand-mist whitespace-nowrap normal-case font-normal" title="{{ $fmt($f->detected_at) }}">
                                                {{ $f->detected_at?->diffForHumans() ?? '—' }}
                                            </span>
                                            @if ($f->acknowledged_at !== null)
                                                <span class="text-brand-mist">·</span>
                                                <span class="text-brand-mist whitespace-nowrap" title="{{ $fmt($f->acknowledged_at) }}">{{ __('Dismissed') }}</span>
                                            @endif
                                        </p>
                                        <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-forest/25 transition-all group-hover:bg-brand-forest group-hover:text-brand-cream group-hover:ring-brand-forest">
                                            {{ __('View details') }}
                                            <x-heroicon-o-arrow-right class="h-3 w-3 shrink-0 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                                        </span>
                                    </div>

                                    <h4 class="mt-1 text-base font-semibold leading-snug text-brand-ink break-words [overflow-wrap:anywhere] transition-colors group-hover:text-brand-forest">{{ $f->title }}</h4>

                                    @if ($f->body)
                                        <p class="mt-1.5 max-w-3xl text-sm leading-snug text-brand-moss whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $f->body }}</p>
                                    @endif

                                    @include('livewire.partials.insight-correlation', ['finding' => $f])

                                    @if ($canFix)
                                        <div class="mt-3">
                                            @if ($fixInFlight)
                                                <span class="pointer-events-auto relative z-10 inline-flex items-center justify-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-amber-900">
                                                    <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 animate-spin" aria-hidden="true" />
                                                    {{ __('Fix queued…') }}
                                                </span>
                                            @else
                                                <x-secondary-button size="sm" type="button" wire:click="openApplyFixModal({{ $f->id }})" class="pointer-events-auto relative z-10">
                                                    <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {{ __('Apply fix') }}
                                                </x-secondary-button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($suggestionFindings->isNotEmpty())
            <div class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-light-bulb class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Suggestions') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recommendations') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Tuning suggestions based on observed signals. Nothing is broken — these are opportunities to improve.') }}</p>
                    </div>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($suggestionFindings as $f)
                        @php
                            $fmt = fn ($v) => \App\Support\Servers\ServerDateFormatter::format($v, $server);
                            $sFix = config('insights.insights.'.$f->insight_key.'.fix');
                            $sCanFix = is_array($sFix) && ($sFix['handler'] ?? null);
                        @endphp
                        {{-- Stretched-link card (see notes on the open-findings list above). --}}
                        <li class="group relative overflow-hidden cursor-pointer transition-colors hover:bg-brand-sand/20">
                            <span class="absolute inset-y-3 left-0 w-1 rounded-full bg-brand-sage transition-all group-hover:w-1.5" aria-hidden="true"></span>
                            <button
                                type="button"
                                wire:click="openFindingDetail({{ $f->id }})"
                                aria-label="{{ __('Open details for :title', ['title' => $f->title]) }}"
                                class="absolute inset-0 z-0 rounded-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-forest focus-visible:outline-offset-[-2px]"
                            ></button>
                            <div class="relative pl-5 pr-5 py-4 flex flex-wrap items-start justify-between gap-x-4 gap-y-2 pointer-events-none">
                                <div class="min-w-0 flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-sand/60">
                                        <x-heroicon-s-light-bulb class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Suggestion') }}</p>
                                        <h4 class="text-base font-semibold leading-snug text-brand-ink break-words [overflow-wrap:anywhere] underline decoration-brand-ink/15 decoration-2 underline-offset-4 transition-colors group-hover:text-brand-forest group-hover:decoration-brand-forest/60">{{ $f->title }}</h4>
                                        @if ($f->body)
                                            <p class="mt-1.5 text-sm leading-6 text-brand-moss whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $f->body }}</p>
                                        @endif
                                        @include('livewire.partials.insight-correlation', ['finding' => $f])
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if ($sCanFix)
                                                <x-secondary-button size="sm" type="button" wire:click="openApplyFixModal({{ $f->id }})" class="pointer-events-auto relative z-10">
                                                    <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {{ __('Apply fix') }}
                                                </x-secondary-button>
                                            @endif
                                            <x-secondary-button size="sm" type="button" wire:click="ignoreFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="ignoreFinding({{ $f->id }})" class="pointer-events-auto relative z-10">
                                                <x-heroicon-o-eye-slash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Ignore') }}
                                            </x-secondary-button>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2 text-right">
                                    <span class="text-xs text-brand-mist whitespace-nowrap" title="{{ $fmt($f->detected_at) }}">
                                        {{ $f->detected_at?->diffForHumans() ?? '—' }}
                                    </span>
                                    <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-forest/25 transition-all group-hover:bg-brand-forest group-hover:text-brand-cream group-hover:ring-brand-forest">
                                        {{ __('View details') }}
                                        <x-heroicon-o-arrow-right class="h-3 w-3 shrink-0 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                                    </span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($recentlyAppliedFindings->isNotEmpty())
            <div class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-arrow-uturn-left class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recently applied fixes') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Fixes Dply applied where an on-disk backup is still recorded. Revert reads the backup, validates the prior config, and reloads the affected service.') }}</p>
                    </div>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($recentlyAppliedFindings as $f)
                        @php
                            $fmt = fn ($v) => \App\Support\Servers\ServerDateFormatter::format($v, $server);
                            $change = is_array($f->meta['fix_change'] ?? null) ? $f->meta['fix_change'] : null;
                        @endphp
                        <li class="px-5 py-4 flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="font-medium text-brand-ink">{{ $f->title }}</p>
                                @if ($change)
                                    <ul class="mt-1 text-xs text-brand-moss space-y-0.5">
                                        @foreach ($change as $k => $v)
                                            <li><span class="font-mono">{{ $k }}</span>: {{ is_scalar($v) ? $v : json_encode($v) }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="mt-1 text-xs text-brand-mist">
                                    {{ __('Applied') }}:
                                    {{ $fmt($f->resolved_at) ?? '—' }}
                                </p>
                                <p class="text-xs text-brand-mist">
                                    {{ __('Backup') }}: <span class="font-mono">{{ $f->meta['backup_path'] }}</span>
                                </p>
                            </div>
                            <x-secondary-button
                                size="sm"
                                type="button"
                                wire:click="revertFix({{ $f->id }})"
                                wire:loading.attr="disabled"
                                wire:target="revertFix({{ $f->id }})"
                                wire:confirm="{{ __('Restore the previous configuration from backup and reload the affected service?') }}"
                                class="shrink-0"
                            >
                                <x-heroicon-o-arrow-uturn-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Revert') }}
                            </x-secondary-button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    @if ($tab === 'dismissed')
        @if ($dismissedFindings->isNotEmpty())
            <div class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-eye-slash class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Dismissed') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Dismissed findings') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Findings you acknowledged. Silenced from the banner and Overview. Restore one to bring it back to the open list.') }}</p>
                    </div>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($dismissedFindings as $f)
                        @php
                            $fmt = fn ($v) => \App\Support\Servers\ServerDateFormatter::format($v, $server);
                            [$accent, $iconBg, $iconText, $sevIconComponent, $sevLabel] = match ($f->severity) {
                                'critical' => ['bg-red-500', 'bg-red-50', 'text-red-700', 'heroicon-s-exclamation-triangle', __('Critical')],
                                'warning' => ['bg-amber-500', 'bg-amber-50', 'text-amber-700', 'heroicon-s-exclamation-circle', __('Warning')],
                                'info' => ['bg-sky-400', 'bg-sky-50', 'text-sky-700', 'heroicon-s-information-circle', __('Info')],
                                default => ['bg-brand-mist', 'bg-brand-sand/60', 'text-brand-moss', 'heroicon-s-bell', __('Notice')],
                            };
                        @endphp
                        <li class="group relative cursor-pointer transition-colors hover:bg-brand-sand/25">
                            <span class="absolute inset-y-3 left-0 w-1 rounded-full {{ $accent }} opacity-40" aria-hidden="true"></span>
                            <button
                                type="button"
                                wire:click="openFindingDetail({{ $f->id }})"
                                aria-label="{{ __('Open details for :title', ['title' => $f->title]) }}"
                                class="absolute inset-0 z-0 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-forest focus-visible:outline-offset-[-2px]"
                            ></button>
                            <div class="relative pointer-events-none flex items-start gap-4 px-5 py-4 pl-6">
                                <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $iconBg }} opacity-70">
                                    <x-dynamic-component :component="$sevIconComponent" class="h-4 w-4 {{ $iconText }}" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] font-semibold uppercase tracking-wide leading-none">
                                            <span class="{{ $iconText }} opacity-80">{{ $sevLabel }}</span>
                                            <span class="text-brand-mist">·</span>
                                            <span class="text-brand-mist normal-case font-normal whitespace-nowrap" title="{{ $fmt($f->acknowledged_at) }}">
                                                {{ __('Dismissed :time', ['time' => $f->acknowledged_at?->diffForHumans() ?? '—']) }}
                                            </span>
                                        </p>
                                        <button type="button" wire:click="unacknowledgeFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="unacknowledgeFinding({{ $f->id }})" class="pointer-events-auto relative z-10 inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-forest/25 transition hover:bg-brand-forest hover:text-brand-cream hover:ring-brand-forest disabled:cursor-not-allowed disabled:opacity-50">
                                            <x-heroicon-o-arrow-uturn-left class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ __('Restore') }}
                                        </button>
                                    </div>
                                    <h4 class="mt-1 text-base font-semibold leading-snug text-brand-ink/80 break-words [overflow-wrap:anywhere]">{{ $f->title }}</h4>
                                    @if ($f->body)
                                        <p class="mt-1 max-w-3xl text-sm leading-snug text-brand-moss whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $f->body }}</p>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($ignoredSuggestions->isNotEmpty())
            <div class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-light-bulb class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Ignored') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Ignored recommendations') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Suggestions you dismissed. Restore one to bring it back into Recommendations on the next scheduled run.') }}</p>
                    </div>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($ignoredSuggestions as $f)
                        @php
                            $fmt = fn ($v) => \App\Support\Servers\ServerDateFormatter::format($v, $server);
                        @endphp
                        <li class="px-5 py-3 flex flex-wrap items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-brand-ink/80">{{ $f->title }}</p>
                                <p class="mt-0.5 text-xs text-brand-mist">
                                    {{ __('Ignored') }}:
                                    {{ $fmt($f->ignored_at) ?? '—' }}
                                </p>
                            </div>
                            <x-secondary-button size="sm" type="button" wire:click="unignoreFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="unignoreFinding({{ $f->id }})" class="shrink-0">
                                {{ __('Restore') }}
                            </x-secondary-button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($dismissedFindings->isEmpty() && $ignoredSuggestions->isEmpty())
            <div class="dply-card overflow-hidden">
                <div class="px-5 py-12 text-center">
                    <x-heroicon-o-eye-slash class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                    <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('Nothing dismissed.') }}</p>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Anything you dismiss from Overview or ignore from Recommendations will show up here.') }}</p>
                </div>
            </div>
        @endif
    @endif

    @if ($tab === 'notifications')
        @include('livewire.servers.partials.insights._tab-notifications')
    @endif

    @if ($tab === 'settings')
        @include('livewire.partials.insights-settings-form', ['catalog' => $insightsCatalog, 'orgHasPro' => $orgHasPro])
        <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-brand-ink/10">
            <div class="flex flex-wrap gap-2">
                <x-secondary-button size="sm" type="button" wire:click="enableAll">{{ __('Enable all') }}</x-secondary-button>
                <x-secondary-button size="sm" type="button" wire:click="disableAll">{{ __('Disable all') }}</x-secondary-button>
            </div>
            <x-primary-button size="sm" type="button" wire:click="saveSettings">{{ __('Save settings') }}</x-primary-button>
        </div>
    @endif

    <x-slot name="modals">
        {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
             shared with the Notifications tab so an operator can add a channel without
             leaving the page; the new channel is auto-selected on success. --}}
        @include('livewire.partials.create-notification-channel-modal')
        @php($detail = $this->selectedFindingDetail)
        @if ($detailFindingId !== null && $detail)
            @php($a = $detail['actions'])
            @php($f = $detail['finding'])
            <div
                class="fixed inset-0 z-40 overflow-y-auto"
                role="dialog"
                aria-modal="true"
                aria-labelledby="insight-detail-title"
                x-data
                x-on:keydown.escape.window="$wire.closeFindingDetail()"
                @if ($a['fixInFlight'])
                    {{-- Auto-refresh while ApplyInsightFixJob is in
                         flight so the operator sees the run status flip
                         from "Queued · running" to "Succeeded" /
                         "Failed" / "Refused" without a manual reload. --}}
                    wire:poll.4s="$refresh"
                @endif
            >
                <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeFindingDetail"></div>
                <div class="relative flex min-h-full items-start justify-center px-4 py-10 sm:px-6">
                    <div class="relative w-full max-w-2xl dply-modal-panel" wire:click.stop>
                        <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                            <h2 id="insight-detail-title" class="text-base font-semibold text-brand-ink">{{ __('Finding details') }}</h2>
                            <button type="button" wire:click="closeFindingDetail" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                                <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                            </button>
                        </div>
                        <div class="px-6 py-5 sm:px-7">
                            @include('livewire.servers.partials.insight-finding-detail', ['detail' => $detail, 'server' => $server])
                        </div>
                        <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-3 sm:px-7">
                            <button type="button" wire:click="closeFindingDetail" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50">
                                {{ __('Close') }}
                            </button>
                            @if ($a['canUnignore'])
                                <button type="button" wire:click="unignoreFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="unignoreFinding({{ $f->id }})" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50">
                                    <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Restore') }}
                                </button>
                            @endif
                            @if ($a['canIgnore'])
                                <button type="button" wire:click="ignoreFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="ignoreFinding({{ $f->id }})" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50">
                                    <x-heroicon-o-eye-slash class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Dismiss') }}
                                </button>
                            @endif
                            @if ($a['canUnacknowledge'])
                                <button type="button" wire:click="unacknowledgeFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="unacknowledgeFinding({{ $f->id }})" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50">
                                    <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Restore to banner') }}
                                </button>
                            @endif
                            @if ($a['canAcknowledge'])
                                <button type="button" wire:click="acknowledgeFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="acknowledgeFinding({{ $f->id }})" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50">
                                    <x-heroicon-o-check class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Acknowledge') }}
                                </button>
                            @endif
                            @if ($a['canRevertFix'])
                                <button type="button" wire:click="revertFix({{ $f->id }})" wire:loading.attr="disabled" wire:target="revertFix({{ $f->id }})" wire:confirm="{{ __('Restore the previous configuration from backup and reload the affected service?') }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3.5 py-2 text-sm font-semibold text-amber-900 shadow-sm hover:bg-amber-100">
                                    <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Revert fix') }}
                                </button>
                            @endif
                            @if ($a['canRerun'])
                                <button type="button" wire:click="rerunSingleCheck('{{ $f->insight_key }}')" wire:loading.attr="disabled" wire:target="rerunSingleCheck('{{ $f->insight_key }}')" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50">
                                    <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="rerunSingleCheck('{{ $f->insight_key }}')" aria-hidden="true" />
                                    {{ __('Run check now') }}
                                </button>
                            @endif
                            @if ($a['fixInFlight'])
                                <span class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3.5 py-2 text-sm font-semibold text-amber-900">
                                    <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                                    {{ __('Fix running…') }}
                                </span>
                            @elseif ($a['canRunFix'])
                                <button type="button" wire:click="runFix({{ $f->id }})" wire:loading.attr="disabled" wire:target="runFix({{ $f->id }})" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-60">
                                    <x-heroicon-o-play class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Run fix now') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($showApplyFixModal && $selectedFixFinding)
            <div
                class="fixed inset-0 z-50 overflow-y-auto"
                role="dialog"
                aria-modal="true"
                aria-labelledby="apply-insight-fix-title"
                x-data
                x-on:keydown.escape.window="$wire.closeApplyFixModal()"
            >
                <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeApplyFixModal"></div>
                <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                    <div class="relative w-full max-w-md dply-modal-panel" wire:click.stop>
                        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                            <h2 id="apply-insight-fix-title" class="text-lg font-semibold text-brand-ink">{{ __('Apply suggested fix') }}</h2>
                        </div>
                        <div class="space-y-3 px-6 py-5 sm:px-7">
                            <p class="text-sm leading-relaxed text-brand-moss">
                                {{ __('This will queue the suggested server-side fix for the selected insight.') }}
                            </p>
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4">
                                <p class="text-sm font-semibold text-brand-ink">{{ $selectedFixFinding->title }}</p>
                                @if ($selectedFixFinding->body)
                                    <p class="mt-2 text-sm leading-6 text-brand-moss">{{ $selectedFixFinding->body }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
                            <button
                                type="button"
                                wire:click="closeApplyFixModal"
                                class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50"
                            >
                                {{ __('Cancel') }}
                            </button>
                            <button
                                type="button"
                                wire:click="confirmApplyFix"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="confirmApplyFix">{{ __('Queue fix') }}</span>
                                <span wire:loading wire:target="confirmApplyFix">{{ __('Queueing…') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-slot>
</x-server-workspace-layout>
