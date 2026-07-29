{{-- Secondary shortcuts, reshaped from a grid of summary cards into two
     compact lists: "Needs attention" (health / patches / hygiene / insights /
     a flagged radar) and "Info" (cost, notifications, a preview/neutral radar).
     Each row reuses ._shortcut-row; all data + feature gates are unchanged. --}}
@php
    $patchFlagged = $patchAdvisorSummary
        && ($patchAdvisorSummary['alert_count'] > 0 || ($patchAdvisorSummary['reboot_required'] ?? false) === true);

    $healthRow = \Laravel\Pennant\Feature::active('workspace.health') && $healthCockpitSummary;
    $patchRow = \Laravel\Pennant\Feature::active('workspace.patch_advisor') && $patchFlagged;
    // Surface the hygiene card once a real SSH scan has run AND it found
    // actionable cleanup. Before a scan, the release/log/job numbers are empty —
    // the "run a scan" nudge lives on the Hygiene tab, not the overview alert
    // list — but disk pressure comes from health metrics (not the scan), so a
    // disk alert still surfaces here pre-scan.
    $hygieneScanned = $releaseHygieneSummary && ! ($releaseHygieneSummary['never_scanned'] ?? false);
    $hygieneDiskAlert = $releaseHygieneSummary && ($releaseHygieneSummary['disk_alert_count'] ?? 0) > 0;
    $hygieneRow = \Laravel\Pennant\Feature::active('workspace.release_hygiene')
        && $releaseHygieneSummary
        && $releaseHygieneSummary['alert_count'] > 0
        && ($hygieneScanned || $hygieneDiskAlert);
    $insightsRow = \Laravel\Pennant\Feature::active('workspace.insights') && $openInsightsCount > 0;

    $radarPresent = (bool) $sharedHostSummary;
    $radarPreview = $radarPresent && (bool) ($sharedHostSummary['preview'] ?? false);
    $radarSeverity = $radarPresent ? ($sharedHostSummary['severity'] ?? '') : '';
    // A radar that's actually flagging something belongs with the alerts; a
    // preview or all-clear radar drops to the Info list.
    $radarAttention = $radarPresent && ! $radarPreview && in_array($radarSeverity, ['critical', 'warning'], true);
    $radarInfo = $radarPresent && ! $radarAttention;

    $costRow = \Laravel\Pennant\Feature::active('workspace.server_cost') && $costCardSummary;
    $notifyRow = (bool) ($notificationSummary['manage_url'] ?? null);

    $hasAttention = $healthRow || $patchRow || $hygieneRow || $insightsRow || $radarAttention;
    $hasInfo = $costRow || $notifyRow || $radarInfo;

    // Severity helper: map a summary's "overall" to the row tint.
    $sev = fn (?string $overall) => in_array($overall, ['critical', 'warning'], true) ? $overall : null;
@endphp

<div class="space-y-4">

{{-- ── Needs attention ───────────────────────────────────────────── --}}
@if ($hasAttention)
    <section class="dply-card overflow-hidden">
        <header class="border-b border-brand-ink/10 px-6 pt-4 pb-3 sm:px-7">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Needs attention') }}</p>
        </header>
        <div class="divide-y divide-brand-ink/8">
            @if ($insightsRow)
                @include('livewire.servers.partials.overview._shortcut-row', [
                    'icon' => 'heroicon-o-light-bulb',
                    'label' => __('Insights'),
                    'severity' => $criticalInsightsCount > 0 ? 'critical' : 'warning',
                    'headline' => trans_choice('{1} :count open finding|[2,*] :count open findings', $openInsightsCount, ['count' => $openInsightsCount])
                        . ($criticalInsightsCount > 0
                            ? ' · ' . trans_choice('{1} :count critical|[2,*] :count critical', $criticalInsightsCount, ['count' => $criticalInsightsCount])
                            : ''),
                    'href' => route('servers.insights', $server),
                    'cta' => __('Open'),
                ])
            @endif

            @if ($patchRow)
                @include('livewire.servers.partials.overview._shortcut-row', [
                    'icon' => 'heroicon-o-shield-check',
                    'label' => __('Patches'),
                    'severity' => $sev($patchAdvisorSummary['overall'] ?? null),
                    'headline' => ($patchAdvisorSummary['reboot_required'] ?? false) === true
                        ? __('Reboot required')
                        : (($patchAdvisorSummary['security'] ?? 0) > 0
                            ? trans_choice(':count security update|:count security updates', $patchAdvisorSummary['security'], ['count' => $patchAdvisorSummary['security']])
                            : trans_choice(':count patch alert|:count patch alerts', $patchAdvisorSummary['alert_count'], ['count' => $patchAdvisorSummary['alert_count']])),
                    'href' => route('servers.patches', $server),
                    'cta' => __('Open'),
                ])
            @endif

            @if ($healthRow)
                @include('livewire.servers.partials.overview._shortcut-row', [
                    'icon' => 'heroicon-o-heart',
                    'label' => __('Health'),
                    'severity' => $sev($healthCockpitSummary['overall'] ?? null),
                    'headline' => $healthCockpitSummary['alert_count'] > 0
                        ? trans_choice(':count open alert|:count open alerts', $healthCockpitSummary['alert_count'], ['count' => $healthCockpitSummary['alert_count']])
                        : __('No open alerts'),
                    'href' => route('servers.health', $server),
                    'cta' => __('Open'),
                ])
            @endif

            @if ($hygieneRow)
                @include('livewire.servers.partials.overview._shortcut-row', [
                    'icon' => 'heroicon-o-archive-box',
                    'label' => __('Hygiene'),
                    'severity' => $sev($releaseHygieneSummary['overall'] ?? null),
                    'headline' => trans_choice(':count cleanup alert|:count cleanup alerts', $releaseHygieneSummary['alert_count'], ['count' => $releaseHygieneSummary['alert_count']]),
                    'href' => route('servers.hygiene', $server),
                    'cta' => __('Open'),
                ])
            @endif

            @if ($radarAttention)
                @include('livewire.servers.partials.overview._shortcut-row', [
                    'icon' => 'heroicon-o-signal',
                    'label' => __('Shared host radar'),
                    'severity' => $sev($radarSeverity),
                    'headline' => $sharedHostSummary['title'],
                    'href' => route('servers.shared-host', $server),
                    'cta' => __('Open'),
                ])
            @endif
        </div>
    </section>
@endif

{{-- ── Info ──────────────────────────────────────────────────────── --}}
@if ($hasInfo)
    <section class="dply-card overflow-hidden">
        <header class="border-b border-brand-ink/10 px-6 pt-4 pb-3 sm:px-7">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Info') }}</p>
        </header>
        <div class="divide-y divide-brand-ink/8">
            @if ($costRow)
                @include('livewire.servers.partials.overview._shortcut-row', [
                    'icon' => 'heroicon-o-currency-dollar',
                    'label' => __('Cost'),
                    'severity' => ($costCardSummary['nudge_severity'] ?? null) === 'warning' ? 'warning' : null,
                    'headline' => $costCardSummary['nudge_title']
                        ? $costCardSummary['formatted_total'] . ' · ' . $costCardSummary['nudge_title']
                        : $costCardSummary['formatted_total'],
                    'href' => route('servers.settings', ['server' => $server, 'section' => 'governance']) . '#settings-cost-estimate',
                    'cta' => __('Open'),
                ])
            @endif

            @if ($radarInfo)
                @include('livewire.servers.partials.overview._shortcut-row', [
                    'icon' => 'heroicon-o-signal',
                    'label' => __('Shared host radar') . ($radarPreview ? ' · ' . __('Soon') : ''),
                    'severity' => null,
                    'headline' => $sharedHostSummary['title'],
                    'href' => route('servers.shared-host', $server),
                    'cta' => $radarPreview ? __('Preview') : __('Open'),
                ])
            @endif

            @if ($notifyRow)
                @include('livewire.servers.partials.overview._shortcut-row', [
                    'icon' => 'heroicon-o-bell-alert',
                    'label' => __('Channels'),
                    'severity' => null,
                    'headline' => $notificationSummary['channel_count'] > 0
                        ? trans_choice('{1} :count channel routing this server|[2,*] :count channels routing this server', $notificationSummary['channel_count'], ['count' => $notificationSummary['channel_count']])
                        : __('No channels routing yet'),
                    'href' => $notificationSummary['manage_url'],
                    'cta' => __('Manage'),
                    'ctaIcon' => 'heroicon-m-cog-6-tooth',
                ])
            @endif
        </div>
    </section>
@endif

</div>
