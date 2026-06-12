{{-- Secondary shortcuts (capacity, cost, patches, hygiene, radar,
     insights, alerts). A two-column grid keeps these compact
     "summary + open" cards as one tidy block instead of a tall
     stack of identical full-width stripes. --}}
<div class="grid gap-4 lg:grid-cols-2 lg:items-start">
{{-- Health cockpit shortcut (VM + flag). --}}
@feature('workspace.health')
@if ($healthCockpitSummary)
    @php
        $healthCritical = $healthCockpitSummary['overall'] === 'critical';
        $healthWarning = $healthCockpitSummary['overall'] === 'warning';
    @endphp
    <section @class([
        'dply-card overflow-hidden',
        'border-rose-200' => $healthCritical,
        'border-amber-200' => $healthWarning && ! $healthCritical,
    ])>
        <div @class([
            'px-6 pt-5 pb-4 sm:px-7',
            'bg-rose-50/60' => $healthCritical,
            'bg-amber-50/60' => $healthWarning && ! $healthCritical,
        ])>
            <div class="flex items-start gap-3">
                <span @class([
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                    'bg-rose-50 text-rose-700 ring-rose-200' => $healthCritical,
                    'bg-amber-100 text-amber-700 ring-amber-200' => $healthWarning && ! $healthCritical,
                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $healthCritical && ! $healthWarning,
                ])>
                    <x-heroicon-o-heart class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p @class([
                        'text-[11px] font-semibold uppercase tracking-[0.16em]',
                        'text-rose-700' => $healthCritical,
                        'text-amber-800' => $healthWarning && ! $healthCritical,
                        'text-brand-sage' => ! $healthCritical && ! $healthWarning,
                    ])>{{ __('Health cockpit') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        @if ($healthCockpitSummary['alert_count'] > 0)
                            {{ trans_choice(':count open alert|:count open alerts', $healthCockpitSummary['alert_count'], ['count' => $healthCockpitSummary['alert_count']]) }}
                        @else
                            {{ __('No open alerts') }}
                        @endif
                    </h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Capacity, releases, deploys, certificates, and daemons in one view.') }}</p>
                </div>
                <a href="{{ route('servers.health', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    {{ __('Open Health') }}
                    <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>
@endif
@endfeature

{{-- Cost card shortcut (VM + flag). --}}
@feature('workspace.server_cost')
@if ($costCardSummary)
    @php
        $costNudgeWarning = ($costCardSummary['nudge_severity'] ?? null) === 'warning';
    @endphp
    <section @class([
        'dply-card overflow-hidden',
        'border-amber-200' => $costNudgeWarning,
    ])>
        <div @class([
            'px-6 pt-5 pb-4 sm:px-7',
            'bg-amber-50/60' => $costNudgeWarning,
        ])>
            <div class="flex items-start gap-3">
                <span @class([
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                    'bg-amber-100 text-amber-700 ring-amber-200' => $costNudgeWarning,
                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $costNudgeWarning,
                ])>
                    <x-heroicon-o-currency-dollar class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p @class([
                        'text-[11px] font-semibold uppercase tracking-[0.16em]',
                        'text-amber-800' => $costNudgeWarning,
                        'text-brand-sage' => ! $costNudgeWarning,
                    ])>{{ __('Cost card') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $costCardSummary['formatted_total'] }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">
                        @if ($costCardSummary['nudge_title'])
                            {{ $costCardSummary['nudge_title'] }}
                        @else
                            {{ __('Provider estimate + dply tier fee for this server.') }}
                        @endif
                    </p>
                </div>
                <a href="{{ route('servers.settings', ['server' => $server, 'section' => 'governance']) }}#settings-cost-estimate" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    {{ __('Open Cost') }}
                    <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>
@endif
@endfeature

{{-- Patch advisor shortcut (VM + flag). --}}
@feature('workspace.patch_advisor')
@if ($patchAdvisorSummary && ($patchAdvisorSummary['alert_count'] > 0 || $patchAdvisorSummary['reboot_required'] === true))
    @php
        $patchCritical = $patchAdvisorSummary['overall'] === 'critical';
        $patchWarning = $patchAdvisorSummary['overall'] === 'warning';
    @endphp
    <section @class([
        'dply-card overflow-hidden',
        'border-rose-200' => $patchCritical,
        'border-amber-200' => $patchWarning && ! $patchCritical,
    ])>
        <div @class([
            'px-6 pt-5 pb-4 sm:px-7',
            'bg-rose-50/60' => $patchCritical,
            'bg-amber-50/60' => $patchWarning && ! $patchCritical,
        ])>
            <div class="flex items-start gap-3">
                <span @class([
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                    'bg-rose-50 text-rose-700 ring-rose-200' => $patchCritical,
                    'bg-amber-100 text-amber-700 ring-amber-200' => $patchWarning && ! $patchCritical,
                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $patchCritical && ! $patchWarning,
                ])>
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p @class([
                        'text-[11px] font-semibold uppercase tracking-[0.16em]',
                        'text-rose-700' => $patchCritical,
                        'text-amber-800' => $patchWarning && ! $patchCritical,
                        'text-brand-sage' => ! $patchCritical && ! $patchWarning,
                    ])>{{ __('Patch advisor') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        @if ($patchAdvisorSummary['reboot_required'] === true)
                            {{ __('Reboot required') }}
                        @elseif ($patchAdvisorSummary['security'] > 0)
                            {{ trans_choice(':count security update|:count security updates', $patchAdvisorSummary['security'], ['count' => $patchAdvisorSummary['security']]) }}
                        @elseif ($patchAdvisorSummary['alert_count'] > 0)
                            {{ trans_choice(':count patch alert|:count patch alerts', $patchAdvisorSummary['alert_count'], ['count' => $patchAdvisorSummary['alert_count']]) }}
                        @else
                            {{ __('Review pending updates') }}
                        @endif
                    </h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('apt updates, reboot flags, and uptime from the inventory probe.') }}</p>
                </div>
                <a href="{{ route('servers.patches', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    {{ __('Open Patches') }}
                    <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>
@endif
@endfeature

{{-- Release hygiene shortcut (VM + flag). --}}
@feature('workspace.release_hygiene')
@if ($releaseHygieneSummary && $releaseHygieneSummary['alert_count'] > 0)
    @php
        $hygieneCritical = $releaseHygieneSummary['overall'] === 'critical';
        $hygieneWarning = $releaseHygieneSummary['overall'] === 'warning';
    @endphp
    <section @class([
        'dply-card overflow-hidden',
        'border-rose-200' => $hygieneCritical,
        'border-amber-200' => $hygieneWarning && ! $hygieneCritical,
    ])>
        <div @class([
            'px-6 pt-5 pb-4 sm:px-7',
            'bg-rose-50/60' => $hygieneCritical,
            'bg-amber-50/60' => $hygieneWarning && ! $hygieneCritical,
        ])>
            <div class="flex items-start gap-3">
                <span @class([
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                    'bg-rose-50 text-rose-700 ring-rose-200' => $hygieneCritical,
                    'bg-amber-100 text-amber-700 ring-amber-200' => $hygieneWarning && ! $hygieneCritical,
                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $hygieneCritical && ! $hygieneWarning,
                ])>
                    <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p @class([
                        'text-[11px] font-semibold uppercase tracking-[0.16em]',
                        'text-rose-700' => $hygieneCritical,
                        'text-amber-800' => $hygieneWarning && ! $hygieneCritical,
                        'text-brand-sage' => ! $hygieneCritical && ! $hygieneWarning,
                    ])>{{ __('Release hygiene') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        {{ trans_choice(':count cleanup alert|:count cleanup alerts', $releaseHygieneSummary['alert_count'], ['count' => $releaseHygieneSummary['alert_count']]) }}
                    </h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Release folders, Laravel logs, and failed queue jobs on this server.') }}</p>
                </div>
                <a href="{{ route('servers.hygiene', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    {{ __('Open Hygiene') }}
                    <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>
@endif
@endfeature

@if ($sharedHostSummary)
    @php
        $sharedHostCritical = ($sharedHostSummary['severity'] ?? '') === 'critical';
        $sharedHostWarning = ($sharedHostSummary['severity'] ?? '') === 'warning';
        $sharedHostPreview = (bool) ($sharedHostSummary['preview'] ?? false);
    @endphp
    <section @class([
        'dply-card overflow-hidden',
        'border-rose-200' => $sharedHostCritical,
        'border-amber-200' => $sharedHostWarning && ! $sharedHostCritical,
        'border-sky-200' => $sharedHostPreview,
    ])>
        <div @class([
            'px-6 pt-5 pb-4 sm:px-7',
            'bg-rose-50/60' => $sharedHostCritical,
            'bg-amber-50/60' => $sharedHostWarning && ! $sharedHostCritical,
            'bg-sky-50/60' => $sharedHostPreview,
        ])>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span @class([
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1',
                        'bg-rose-50 text-rose-700 ring-rose-200' => $sharedHostCritical,
                        'bg-amber-100 text-amber-700 ring-amber-200' => $sharedHostWarning && ! $sharedHostCritical,
                        'bg-sky-100 text-sky-800 ring-sky-200' => $sharedHostPreview,
                        'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $sharedHostCritical && ! $sharedHostWarning && ! $sharedHostPreview,
                    ])>
                        <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p @class([
                            'text-[11px] font-semibold uppercase tracking-[0.16em]',
                            'text-rose-700' => $sharedHostCritical,
                            'text-amber-800' => $sharedHostWarning && ! $sharedHostCritical,
                            'text-sky-800' => $sharedHostPreview,
                            'text-brand-sage' => ! $sharedHostCritical && ! $sharedHostWarning && ! $sharedHostPreview,
                        ])>{{ __('Shared Host Radar') }}@if ($sharedHostPreview) · {{ __('Soon') }}@endif</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $sharedHostSummary['title'] }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ $sharedHostSummary['message'] }}</p>
                    </div>
                </div>
                <a href="{{ route('servers.shared-host', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    {{ $sharedHostPreview ? __('Preview radar') : __('Open radar') }}
                    <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>
@endif

{{-- Insights (conditional + flag-gated). --}}
@feature('workspace.insights')
@if ($openInsightsCount > 0)
    @php $insightsCritical = $criticalInsightsCount > 0; @endphp
    <section class="dply-card overflow-hidden {{ $insightsCritical ? 'border-red-200' : 'border-amber-200' }}">
        <div class="border-b border-brand-ink/10 {{ $insightsCritical ? 'bg-red-50/60' : 'bg-amber-50/60' }} px-6 py-5 sm:px-7">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $insightsCritical ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-amber-100 text-amber-700 ring-amber-200' }}">
                    <x-heroicon-o-light-bulb class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] {{ $insightsCritical ? 'text-red-700' : 'text-amber-800' }}">{{ __('Insights') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        {{ trans_choice('{1} :count open finding|[2,*] :count open findings', $openInsightsCount, ['count' => $openInsightsCount]) }}
                    </h3>
                    @if ($insightsCritical)
                        <p class="mt-1">
                            <span class="inline-flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700">
                                <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                {{ trans_choice('{1} :count critical|[2,*] :count critical', $criticalInsightsCount, ['count' => $criticalInsightsCount]) }}
                            </span>
                        </p>
                    @endif
                </div>
                <a href="{{ route('servers.insights', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold {{ $insightsCritical ? 'text-red-700 hover:text-red-900' : 'text-amber-800 hover:text-amber-900' }} shadow-sm transition hover:bg-brand-sand/40">
                    {{ __('Open Insights') }}
                    <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>
@endif
@endfeature

{{-- Notifications --}}
@if ($notificationSummary['manage_url'])
    <section class="dply-card overflow-hidden">
        <div class="px-6 pt-5 pb-4 sm:px-7">
            <div class="flex items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        @if ($notificationSummary['channel_count'] > 0)
                            {{ trans_choice('{1} :count channel routing this server|[2,*] :count channels routing this server', $notificationSummary['channel_count'], ['count' => $notificationSummary['channel_count']]) }}
                        @else
                            {{ __('No channels routing yet') }}
                        @endif
                    </h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        @if ($notificationSummary['channel_count'] === 0)
                            {{ __('Add a channel to get pinged when something matters on this box.') }}
                        @else
                            {{ __('Channels deliver alerts when health checks fail, deploys break, or schedules trip.') }}
                        @endif
                    </p>
                </div>
                <a href="{{ $notificationSummary['manage_url'] }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    <x-heroicon-m-cog-6-tooth class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Manage') }}
                </a>
            </div>
        </div>
    </section>
@endif
</div>
