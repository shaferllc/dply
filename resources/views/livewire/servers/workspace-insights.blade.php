@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="insights"
    :title="__('Insights')"
    :description="__('Monitoring, recommendations, and optional fixes for this server.')"
    :pageHeaderToolbar="true"
>
    <x-slot name="headerActions">
        <button type="button" wire:click="runChecksNow" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="runChecksNow" aria-hidden="true" />
            <span wire:loading.remove wire:target="runChecksNow">{{ __('Refresh') }}</span>
            <span wire:loading wire:target="runChecksNow">{{ __('Queueing…') }}</span>
        </button>
    </x-slot>

    @include('livewire.servers.partials.workspace-flashes')

    <x-explainer class="mb-4">
        <p>{{ __('Insights runs a battery of read-only health checks against the server (config sanity, package versions, log signals, resource pressure) and groups the findings into a prioritized list. Each finding may have an associated "Apply fix" action that dply can run for you over SSH.') }}</p>
        <p>{{ __('"Run checks now" re-runs the full battery on demand. Otherwise checks run on a slow background cadence so the page stays responsive — opening this tab uses the most recent cached results.') }}</p>
    </x-explainer>

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project insight context') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('These findings are scoped to this server. For shared incident context, runbooks, and grouped notifications, use the linked project pages for the broader project view.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
                <a href="{{ route('projects.access', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project access') }}</a>
            </div>
        </div>
    @endif

    <x-server-workspace-tablist ariaLabel="{{ __('Insights sections') }}">
        <x-server-workspace-tab wire:click="setTab('overview')" :active="$tab === 'overview'">
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-list-bullet class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Overview') }}
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
                    <li class="flex flex-wrap items-start justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="font-medium text-red-950 break-words [overflow-wrap:anywhere]">{{ $b->title }}</p>
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
            <div class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Open findings') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Server-scoped open findings appear here. Site-specific items are on each site’s Insights page.') }}</p>
            </div>
            @if ($findings->isEmpty())
                <div class="px-5 py-10 text-center">
                    <p class="text-sm font-medium text-brand-ink">{{ __('No open findings right now.') }}</p>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Run a refresh, wait for the scheduled job, or review settings if you expected a signal here.') }}</p>
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
                            $checkedAtRaw = is_array($f->meta ?? null) ? ($f->meta['checked_at'] ?? null) : null;
                            $checkedAt = is_string($checkedAtRaw) && $checkedAtRaw !== ''
                                ? \Carbon\Carbon::parse($checkedAtRaw)
                                : null;
                            $appTimezone = config('app.timezone') ?: 'UTC';
                            $checkedAtLocal = $checkedAt?->copy()->timezone($appTimezone);
                            $checkedAtUtc = $checkedAt?->copy()->timezone('UTC');

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
                                'queued' => ['bg-amber-100 text-amber-900 ring-amber-300', __('Fix running…'), 'heroicon-o-arrow-path'],
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
                        <li class="relative overflow-hidden">
                            <span class="absolute inset-y-3 left-0 w-1 rounded-full {{ $accent }}" aria-hidden="true"></span>
                            <div class="pl-5 pr-5 py-4 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                <div class="min-w-0 flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $iconBg }}">
                                        <x-dynamic-component :component="$sevIconComponent" class="h-4 w-4 {{ $iconText }}" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide {{ $iconText }}">{{ $sevLabel }}</p>
                                        <button
                                            type="button"
                                            wire:click="openFindingDetail({{ $f->id }})"
                                            class="group flex w-full items-start justify-between gap-2 text-left"
                                            aria-label="{{ __('Open details for :title', ['title' => $f->title]) }}"
                                        >
                                            <h4 class="text-base font-semibold leading-snug text-brand-ink break-words [overflow-wrap:anywhere] group-hover:text-brand-forest">{{ $f->title }}</h4>
                                            <x-heroicon-o-chevron-right class="mt-1 h-4 w-4 shrink-0 text-brand-mist group-hover:text-brand-forest" aria-hidden="true" />
                                        </button>
                                        @if ($f->body)
                                            <p class="mt-1.5 text-sm leading-6 text-brand-moss whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $f->body }}</p>
                                        @endif
                                        @include('livewire.partials.insight-correlation', ['finding' => $f])
                                        @if ($f->insight_key === 'insights_pipeline_heartbeat' && $checkedAtLocal && $checkedAtUtc)
                                            <div class="mt-2 grid grid-cols-1 gap-x-4 gap-y-0.5 text-[11px] text-brand-mist sm:grid-cols-3">
                                                <p><span class="font-medium text-brand-moss">{{ __('App time') }}:</span> {{ $checkedAtLocal->format('Y-m-d H:i:s T') }}</p>
                                                <p><span class="font-medium text-brand-moss">{{ __('UTC') }}:</span> {{ $checkedAtUtc->format('Y-m-d H:i:s T') }}</p>
                                                <p><span class="font-medium text-brand-moss">{{ __('Recorded') }}:</span> {{ $f->detected_at?->timezone($appTimezone)->format('Y-m-d H:i:s T') }}</p>
                                            </div>
                                        @endif
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if ($canFix && $fixInFlight)
                                                <span class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-amber-900">
                                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                                                    {{ __('Fix running…') }}
                                                </span>
                                            @elseif ($canFix)
                                                <button type="button" wire:click="openApplyFixModal({{ $f->id }})" class="{{ $btnSecondary }}">
                                                    <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('Apply fix') }}
                                                </button>
                                            @endif
                                            <button type="button" wire:click="rerunSingleCheck('{{ $f->insight_key }}')" wire:loading.attr="disabled" wire:target="rerunSingleCheck('{{ $f->insight_key }}')" class="{{ $btnSecondary }}" title="{{ __('Re-run this single check now (does not recompute health score)') }}">
                                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0" wire:loading.class="animate-spin" wire:target="rerunSingleCheck('{{ $f->insight_key }}')" aria-hidden="true" />
                                                {{ __('Re-run') }}
                                            </button>
                                            @if ($f->severity === 'critical' && $f->acknowledged_at !== null)
                                                <button type="button" wire:click="unacknowledgeFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="unacknowledgeFinding({{ $f->id }})" class="{{ $btnSecondary }}">
                                                    <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('Restore to banner') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1 text-right">
                                    <span class="text-xs text-brand-mist whitespace-nowrap" title="{{ $f->detected_at?->timezone($appTimezone)->format('Y-m-d H:i:s T') }}">
                                        {{ $f->detected_at?->diffForHumans() ?? '—' }}
                                    </span>
                                    @if ($f->acknowledged_at !== null)
                                        <span class="text-[10px] uppercase tracking-wide text-brand-mist whitespace-nowrap" title="{{ $f->acknowledged_at?->timezone($appTimezone)->format('Y-m-d H:i:s T') }}">
                                            {{ __('Dismissed') }}
                                        </span>
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
                <div class="border-b border-brand-ink/10 px-5 py-4">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Recommendations') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Tuning suggestions based on observed signals. Nothing is broken — these are opportunities to improve.') }}</p>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($suggestionFindings as $f)
                        @php
                            $appTimezone = config('app.timezone') ?: 'UTC';
                            $sFix = config('insights.insights.'.$f->insight_key.'.fix');
                            $sCanFix = is_array($sFix) && ($sFix['handler'] ?? null);
                        @endphp
                        <li class="relative overflow-hidden">
                            <span class="absolute inset-y-3 left-0 w-1 rounded-full bg-brand-sage" aria-hidden="true"></span>
                            <div class="pl-5 pr-5 py-4 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                <div class="min-w-0 flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-sand/60">
                                        <x-heroicon-s-light-bulb class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Suggestion') }}</p>
                                        <button
                                            type="button"
                                            wire:click="openFindingDetail({{ $f->id }})"
                                            class="group flex w-full items-start justify-between gap-2 text-left"
                                            aria-label="{{ __('Open details for :title', ['title' => $f->title]) }}"
                                        >
                                            <h4 class="text-base font-semibold leading-snug text-brand-ink break-words [overflow-wrap:anywhere] group-hover:text-brand-forest">{{ $f->title }}</h4>
                                            <x-heroicon-o-chevron-right class="mt-1 h-4 w-4 shrink-0 text-brand-mist group-hover:text-brand-forest" aria-hidden="true" />
                                        </button>
                                        @if ($f->body)
                                            <p class="mt-1.5 text-sm leading-6 text-brand-moss whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $f->body }}</p>
                                        @endif
                                        @include('livewire.partials.insight-correlation', ['finding' => $f])
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if ($sCanFix)
                                                <button type="button" wire:click="openApplyFixModal({{ $f->id }})" class="{{ $btnSecondary }}">
                                                    <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('Apply fix') }}
                                                </button>
                                            @endif
                                            <button type="button" wire:click="ignoreFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="ignoreFinding({{ $f->id }})" class="{{ $btnSecondary }}">
                                                <x-heroicon-o-eye-slash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Ignore') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1 text-right">
                                    <span class="text-xs text-brand-mist whitespace-nowrap" title="{{ $f->detected_at?->timezone($appTimezone)->format('Y-m-d H:i:s T') }}">
                                        {{ $f->detected_at?->diffForHumans() ?? '—' }}
                                    </span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($ignoredSuggestions->isNotEmpty())
            <div class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 px-5 py-4">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Ignored recommendations') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Suggestions you dismissed. Restore one to bring it back into Recommendations on the next scheduled run.') }}</p>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($ignoredSuggestions as $f)
                        @php
                            $appTimezone = config('app.timezone') ?: 'UTC';
                        @endphp
                        <li class="px-5 py-3 flex flex-wrap items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-brand-ink/80">{{ $f->title }}</p>
                                <p class="mt-0.5 text-xs text-brand-mist">
                                    {{ __('Ignored') }}:
                                    {{ $f->ignored_at?->timezone($appTimezone)->format('Y-m-d H:i:s T') ?? '—' }}
                                </p>
                            </div>
                            <button type="button" wire:click="unignoreFinding({{ $f->id }})" wire:loading.attr="disabled" wire:target="unignoreFinding({{ $f->id }})" class="{{ $btnSecondary }} shrink-0">
                                {{ __('Restore') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($recentlyAppliedFindings->isNotEmpty())
            <div class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 px-5 py-4">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Recently applied fixes') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Fixes Dply applied where an on-disk backup is still recorded. Revert reads the backup, validates the prior config, and reloads the affected service.') }}</p>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($recentlyAppliedFindings as $f)
                        @php
                            $appTimezone = config('app.timezone') ?: 'UTC';
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
                                    {{ $f->resolved_at?->timezone($appTimezone)->format('Y-m-d H:i:s T') ?? '—' }}
                                </p>
                                <p class="text-xs text-brand-mist">
                                    {{ __('Backup') }}: <span class="font-mono">{{ $f->meta['backup_path'] }}</span>
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="revertFix({{ $f->id }})"
                                wire:loading.attr="disabled"
                                wire:target="revertFix({{ $f->id }})"
                                wire:confirm="{{ __('Restore the previous configuration from backup and reload the affected service?') }}"
                                class="{{ $btnSecondary }} shrink-0"
                            >
                                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Revert') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    @if ($tab === 'notifications')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm p-6 space-y-3 text-sm text-brand-moss">
            <p>{{ __('Subscribe to “Insights alerts” on this server from your notification channels. When new findings open (or a resolved issue recurs), subscribed channels receive a short message with a link back here.') }}</p>
            <p>
                <a href="{{ route('profile.notification-channels') }}" wire:navigate class="font-medium text-brand-forest underline">{{ __('Manage notification channels') }}</a>
                ·
                <a href="{{ route('profile.notification-channels.bulk-assign') }}" wire:navigate class="font-medium text-brand-forest underline">{{ __('Bulk-assign event types') }}</a>
            </p>
            <p class="text-xs text-brand-mist">{{ __('Event key: server.insights_alerts') }}</p>
        </div>
    @endif

    @if ($tab === 'settings')
        @include('livewire.partials.insights-settings-form', ['catalog' => $insightsCatalog, 'orgHasPro' => $orgHasPro])
        <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-brand-ink/10">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="enableAll" class="{{ $btnSecondary }}">{{ __('Enable all') }}</button>
                <button type="button" wire:click="disableAll" class="{{ $btnSecondary }}">{{ __('Disable all') }}</button>
            </div>
            <button type="button" wire:click="saveSettings" class="{{ $btnPrimary }}">{{ __('Save settings') }}</button>
        </div>
    @endif

    <x-slot name="modals">
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
                            @include('livewire.servers.partials.insight-finding-detail', ['detail' => $detail])
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
