@php
    $meta = $server->meta ?? [];
    $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];
    $upgrades = $meta['inventory_upgradable_packages'] ?? null;
    $reboot = $meta['inventory_reboot_required'] ?? null;
    $checkedAt = $meta['inventory_checked_at'] ?? null;

    // Security count from upgradable preview (same parsing as the inventory tab table).
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

    // Services running/total from the units snapshot.
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

    // Disk root + memory from extended snapshot (best-effort parse — strip-and-find).
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
    $uptimeLine = null;
    if (! empty($extParts[1])) {
        $uptimeLine = trim($extParts[1]);
    }

    $hasAnyData = $checkedAt !== null;
@endphp

<section class="space-y-6" aria-labelledby="manage-overview-title">
    {{-- Status strip --}}
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl border border-brand-ink/10 bg-white px-5 py-3 text-sm">
        <h2 id="manage-overview-title" class="sr-only">{{ __('Overview') }}</h2>

        @if ($server->health_status === \App\Models\Server::HEALTH_REACHABLE)
            <span class="inline-flex items-center gap-1.5 font-medium text-brand-forest">
                <span aria-hidden="true" class="inline-block h-2 w-2 rounded-full bg-brand-forest"></span>
                {{ __('Reachable') }}
            </span>
        @elseif ($server->health_status === \App\Models\Server::HEALTH_UNREACHABLE)
            <span class="inline-flex items-center gap-1.5 font-medium text-red-700">
                <span aria-hidden="true" class="inline-block h-2 w-2 rounded-full bg-red-600"></span>
                {{ __('Unreachable') }}
            </span>
        @else
            <span class="inline-flex items-center gap-1.5 text-brand-moss">
                <span aria-hidden="true" class="inline-block h-2 w-2 rounded-full bg-brand-mist"></span>
                {{ __('Health unknown') }}
            </span>
        @endif

        @if ($uptimeLine)
            <span class="text-xs text-brand-moss truncate" title="{{ $uptimeLine }}">{{ $uptimeLine }}</span>
        @endif

        <span class="ml-auto inline-flex items-center gap-3 text-xs text-brand-moss">
            @if ($checkedAt)
                {{ __('Refreshed :t', ['t' => \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans()]) }}
            @else
                {{ __('Never refreshed') }}
            @endif
            @if ($opsReady && ! $isDeployer)
                <button
                    type="button"
                    wire:click="refreshServerInventoryDetails"
                    wire:loading.attr="disabled"
                    wire:target="refreshServerInventoryDetails"
                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Refresh state') }}
                    </span>
                    <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Refreshing…') }}
                    </span>
                </button>
            @endif
        </span>
    </div>

    {{-- Health grid --}}
    @if ($hasAnyData)
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a href="{{ route('servers.manage', ['server' => $server, 'section' => 'services']) }}" wire:navigate class="{{ $card }} block p-5 hover:border-brand-sage/40 hover:bg-brand-sand/10 transition-colors">
                <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Services') }}</dt>
                <dd class="mt-1 flex items-baseline gap-2">
                    <span class="text-2xl font-semibold text-brand-ink">{{ $servicesRunning }}</span>
                    <span class="text-sm text-brand-moss">/ {{ $servicesTotal }} {{ __('running') }}</span>
                </dd>
                @if ($servicesFailed > 0)
                    <p class="mt-1 text-xs font-medium text-red-700">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-red-600"></span>
                        {{ trans_choice(':n failed|:n failed', $servicesFailed, ['n' => $servicesFailed]) }}
                    </p>
                @endif
            </a>

            <div class="{{ $card }} p-5">
                <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Root disk') }}</dt>
                <dd class="mt-1">
                    @if ($rootDiskLine && count($rootDiskLine) >= 5)
                        <span class="text-2xl font-semibold text-brand-ink">{{ $rootDiskLine[4] }}</span>
                        <span class="ml-2 text-sm text-brand-moss">{{ $rootDiskLine[2] }} / {{ $rootDiskLine[1] }}</span>
                    @else
                        <span class="text-sm text-brand-moss">{{ __('Unknown') }}</span>
                    @endif
                </dd>
            </div>

            <div class="{{ $card }} p-5">
                <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Memory') }}</dt>
                <dd class="mt-1">
                    @if ($memLine && count($memLine) >= 4)
                        <span class="text-2xl font-semibold text-brand-ink">{{ $memLine[2] }}</span>
                        <span class="ml-2 text-sm text-brand-moss">/ {{ $memLine[1] }}</span>
                    @else
                        <span class="text-sm text-brand-moss">{{ __('Unknown') }}</span>
                    @endif
                </dd>
            </div>

            <a href="{{ route('servers.manage', ['server' => $server, 'section' => 'updates']) }}" wire:navigate class="{{ $card }} block p-5 hover:border-brand-sage/40 hover:bg-brand-sand/10 transition-colors">
                <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Updates') }}</dt>
                <dd class="mt-1 flex items-baseline gap-2">
                    <span class="text-2xl font-semibold text-brand-ink">{{ $upgrades ?? '—' }}</span>
                    <span class="text-sm text-brand-moss">{{ __('upgradable') }}</span>
                </dd>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                    @if ($securityCount > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-1.5 py-0.5 font-semibold uppercase tracking-wide text-red-800">
                            {{ trans_choice(':n security|:n security', $securityCount, ['n' => $securityCount]) }}
                        </span>
                    @endif
                    @if ($reboot === true)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 font-semibold uppercase tracking-wide text-amber-900">
                            {{ __('Reboot pending') }}
                        </span>
                    @endif
                </p>
            </a>
        </dl>
    @else
        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-white px-6 py-8 text-center text-sm text-brand-moss">
            <p>{{ __('No state data yet.') }}</p>
            @if ($opsReady && ! $isDeployer)
                <button
                    type="button"
                    wire:click="refreshServerInventoryDetails"
                    wire:loading.attr="disabled"
                    wire:target="refreshServerInventoryDetails"
                    class="{{ $btnPrimary }} mt-4"
                >
                    <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                    <span wire:loading.remove wire:target="refreshServerInventoryDetails">{{ __('Refresh server state') }}</span>
                    <span wire:loading wire:target="refreshServerInventoryDetails">{{ __('Refreshing…') }}</span>
                </button>
            @endif
        </div>
    @endif

    {{-- Quick actions --}}
    @if ($opsReady && ! $isDeployer)
        <div class="{{ $card }} p-6 sm:p-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Quick actions') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('The most-used actions, duplicated here for convenience. Each lives under its subsystem tab too.') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach (['reload_nginx', 'restart_php_fpm', 'apt_update'] as $key)
                    @if (! empty($serviceActions[$key]))
                        @php $action = $serviceActions[$key]; @endphp
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $key }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), false)"
                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                            {{ $action['label'] }}
                        </button>
                    @endif
                @endforeach
                @if (! empty($dangerousActions['reboot']))
                    @php $reboot = $dangerousActions['reboot']; @endphp
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedAction', ['reboot'], @js($reboot['label']), @js($reboot['confirm']), @js($reboot['label']), true)"
                        class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-900 hover:bg-red-100"
                    >
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                        {{ $reboot['label'] }}
                    </button>
                @endif
            </div>
        </div>
    @endif

    {{-- Recent activity --}}
    @if (! empty($recentActions) && $recentActions->count() > 0)
        <div class="{{ $card }} p-6 sm:p-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Recent activity') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('The most recent manage actions queued for this server.') }}</p>
            <ul class="mt-4 divide-y divide-brand-ink/10 text-sm">
                @foreach ($recentActions as $row)
                    @php
                        $statusTone = match ($row->status) {
                            'finished' => ['classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest', 'label' => __('Finished')],
                            'running' => ['classes' => 'bg-amber-100 text-amber-900', 'dot' => 'bg-amber-500', 'label' => __('Running')],
                            'queued' => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __('Queued')],
                            'failed' => ['classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600', 'label' => __('Failed')],
                            default => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __($row->status)],
                        };
                        $duration = $row->started_at && $row->finished_at
                            ? $row->started_at->diffForHumans($row->finished_at, ['short' => true, 'parts' => 1, 'syntax' => \Carbon\Carbon::DIFF_ABSOLUTE])
                            : null;
                    @endphp
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $statusTone['classes'] }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusTone['dot'] }}"></span>
                            {{ $statusTone['label'] }}
                        </span>
                        <span class="font-medium text-brand-ink">{{ $row->label }}</span>
                        @if ($duration)
                            <span class="text-xs text-brand-mist">{{ $duration }}</span>
                        @endif
                        @if ($row->error_message)
                            <span class="text-xs text-red-700 truncate max-w-md" title="{{ $row->error_message }}">{{ $row->error_message }}</span>
                        @endif
                        <span class="ml-auto text-xs text-brand-moss">{{ $row->created_at?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project operations context') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('Manage actions on this server can affect the rest of the :project project. Use the project operations page for runbooks, activity review, and alert routing before making broader stack changes.', ['project' => $server->workspace->name]) }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project resources') }}</a>
            </div>
        </div>
    @endif
</section>
