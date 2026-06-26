{{-- Host state strip: at-a-glance disk / memory / services / package counts from
     the inventory probe. Grafted onto Overview when the Manage workspace was
     dissolved. Read-only here — the probe refresh lives on the Tools page and on
     the scheduled inventory sweep. --}}
@php
    $meta = $server->meta ?? [];
    $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];
    $upgrades = $meta['inventory_upgradable_packages'] ?? null;
    $reboot = $meta['inventory_reboot_required'] ?? null;
    $checkedAt = $meta['inventory_checked_at'] ?? null;

    $securityCount = 0;
    if (! empty($meta['inventory_upgradable_preview']) && is_string($meta['inventory_upgradable_preview'])) {
        foreach (explode("\n", $meta['inventory_upgradable_preview']) as $line) {
            if (preg_match('#/(\S+)\s+\S+\s+\S+#', $line, $m)) {
                if (preg_match('/-security|esm-/i', $m[1])) {
                    $securityCount++;
                }
            }
        }
    }

    $servicesTotal = count($units);
    $servicesRunning = 0;
    $servicesFailed = 0;
    foreach ($units as $u) {
        if (($u['active_state'] ?? null) === 'active') {
            $servicesRunning++;
        }
        if (($u['active_state'] ?? null) === 'failed') {
            $servicesFailed++;
        }
    }

    $extSnap = (string) ($meta['inventory_extended_snapshot'] ?? '');
    $extParts = $extSnap === '' ? [] : preg_split('/\R---\R/', $extSnap);
    $rootDiskLine = null;
    if (! empty($extParts[0])) {
        foreach (explode("\n", $extParts[0]) as $line) {
            if (preg_match('#\s/$#', $line)) {
                $rootDiskLine = preg_split('/\s+/', trim($line));
                break;
            }
        }
    }
    $memLine = null;
    if (! empty($extParts[2])) {
        foreach (explode("\n", $extParts[2]) as $line) {
            if (str_starts_with(trim($line), 'Mem:')) {
                $memLine = preg_split('/\s+/', trim($line));
                break;
            }
        }
    }

    $hasAnyData = $checkedAt !== null;

    $rootDiskPct = ($rootDiskLine && count($rootDiskLine) >= 5) ? $rootDiskLine[4] : '—';
    $rootDiskUse = ($rootDiskLine && count($rootDiskLine) >= 3) ? $rootDiskLine[2].' / '.$rootDiskLine[1] : null;
    $memUsed = ($memLine && count($memLine) >= 4) ? $memLine[2].' / '.$memLine[1] : '—';

    $rootPctNum = is_numeric(rtrim((string) $rootDiskPct, '%')) ? (int) rtrim((string) $rootDiskPct, '%') : null;

    $statCardTones = [
        'emerald' => 'border-emerald-200 bg-emerald-50/60',
        'amber' => 'border-amber-200 bg-amber-50/60',
        'rose' => 'border-rose-200 bg-rose-50/60',
        'neutral' => 'border-brand-ink/10 bg-white',
    ];

    $hostStateStats = [
        [
            'label' => __('Services running'),
            'value' => $hasAnyData ? $servicesRunning.' / '.$servicesTotal : '—',
            'sub' => $servicesFailed > 0 ? trans_choice(':n failed|:n failed', $servicesFailed, ['n' => $servicesFailed]) : ($hasAnyData ? __('All active') : __('No data')),
            'tone' => $servicesFailed > 0 ? 'rose' : ($hasAnyData && $servicesTotal > 0 ? 'emerald' : 'neutral'),
            'subTone' => $servicesFailed > 0 ? 'text-red-700 font-medium' : 'text-brand-mist',
        ],
        [
            'label' => __('Root disk'),
            'value' => $rootDiskPct,
            'sub' => $rootDiskUse ?: __('No data'),
            'tone' => $rootPctNum === null ? 'neutral' : ($rootPctNum >= 90 ? 'rose' : ($rootPctNum >= 80 ? 'amber' : 'emerald')),
            'subTone' => 'text-brand-mist',
        ],
        [
            'label' => __('Memory'),
            'value' => $memUsed,
            'sub' => $hasAnyData ? __('used / total') : __('No data'),
            'tone' => 'neutral',
            'subTone' => 'text-brand-mist',
        ],
        [
            'label' => __('Upgradable'),
            'value' => $hasAnyData ? (string) ($upgrades ?? '0') : '—',
            'sub' => __('packages'),
            'tone' => ! $hasAnyData ? 'neutral' : (((int) ($upgrades ?? 0)) > 0 ? 'amber' : 'emerald'),
            'subTone' => 'text-brand-mist',
        ],
        [
            'label' => __('Security updates'),
            'value' => $hasAnyData ? (string) $securityCount : '—',
            'sub' => $securityCount > 0 ? __('need attention') : ($hasAnyData ? __('up to date') : __('No data')),
            'tone' => ! $hasAnyData ? 'neutral' : ($securityCount > 0 ? 'rose' : 'emerald'),
            'subTone' => $securityCount > 0 ? 'text-red-700 font-medium' : 'text-brand-mist',
        ],
        [
            'label' => __('Reboot'),
            'value' => $reboot === true ? __('Pending') : ($reboot === false ? __('No') : '—'),
            'sub' => $reboot === true ? __('restart required') : ($reboot === false ? __('not required') : __('No data')),
            'tone' => $reboot === true ? 'amber' : ($reboot === false ? 'emerald' : 'neutral'),
            'subTone' => $reboot === true ? 'text-amber-800 font-medium' : 'text-brand-mist',
        ],
    ];
@endphp

@if ($hasAnyData)
    <section class="dply-card overflow-hidden" aria-labelledby="host-state-title">
        <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
            <div class="flex items-center gap-2">
                <x-heroicon-o-squares-2x2 class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                <h3 id="host-state-title" class="text-sm font-semibold text-brand-ink">{{ __('Host state') }}</h3>
            </div>
            <p class="text-xs text-brand-mist">{{ __('Updated :t', ['t' => \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans()]) }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3 p-6 sm:grid-cols-3 sm:p-7">
            @foreach ($hostStateStats as $stat)
                <div @class(['flex items-baseline justify-between gap-3 rounded-2xl border px-4 py-3 shadow-sm', $statCardTones[$stat['tone']]])>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                        <p class="mt-1 truncate text-[11px] {{ $stat['subTone'] }}" title="{{ $stat['sub'] }}">{{ $stat['sub'] }}</p>
                    </div>
                    <p class="shrink-0 whitespace-nowrap font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>
    </section>
@endif
