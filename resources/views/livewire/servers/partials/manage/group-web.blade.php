@php
    $meta = $server->meta ?? [];
    $activeWebserver = strtolower((string) ($meta['webserver'] ?? 'nginx'));
    $nginx = is_array($meta['manage_nginx'] ?? null) ? $meta['manage_nginx'] : [];
    $phpFpm = is_array($meta['manage_php_fpm'] ?? null) ? $meta['manage_php_fpm'] : ['versions' => []];
    $certbot = is_array($meta['manage_certbot'] ?? null) ? $meta['manage_certbot'] : ['present' => false];
    $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];
    $defaultPhp = (string) ($meta['default_php_version'] ?? '8.3');

    $unitFor = function (string $unit) use ($units) {
        foreach ($units as $u) {
            if (($u['unit'] ?? null) === $unit) {
                return $u;
            }
        }
        return null;
    };

    $nginxUnit = $unitFor('nginx');
    $nginxVersion = (string) ($nginx['version'] ?? '');
    if ($nginxVersion !== '' && preg_match('#nginx/(\S+)#', $nginxVersion, $vm)) {
        $nginxVersion = $vm[1];
    }

    // Catalog of all webservers dply knows about. Order = display order.
    $webserverCatalog = [
        'nginx' => ['label' => 'nginx', 'icon' => 'heroicon-o-bolt', 'systemd' => 'nginx'],
        'caddy' => ['label' => 'Caddy', 'icon' => 'heroicon-o-shield-check', 'systemd' => 'caddy'],
        'apache' => ['label' => 'Apache', 'icon' => 'heroicon-o-cube', 'systemd' => 'apache2'],
        'openlitespeed' => ['label' => 'OpenLiteSpeed', 'icon' => 'heroicon-o-rocket-launch', 'systemd' => 'lshttpd'],
        'traefik' => ['label' => 'Traefik', 'icon' => 'heroicon-o-arrow-path-rounded-square', 'systemd' => 'traefik'],
        'haproxy' => ['label' => 'HAProxy', 'icon' => 'heroicon-o-scale', 'systemd' => 'haproxy'],
    ];

    // Parse certbot output into a structured table (best-effort regex).
    $certs = [];
    if (! empty($certbot['certs_raw']) && is_string($certbot['certs_raw'])) {
        $name = null;
        $domains = null;
        $expiry = null;
        $valid = null;
        foreach (explode("\n", $certbot['certs_raw']) as $line) {
            $line = trim($line);
            if (preg_match('/^Certificate Name:\s*(.+)$/', $line, $m)) {
                if ($name !== null) {
                    $certs[] = compact('name', 'domains', 'expiry', 'valid');
                }
                $name = $m[1];
                $domains = null;
                $expiry = null;
                $valid = null;
            } elseif (preg_match('/^Domains:\s*(.+)$/', $line, $m)) {
                $domains = $m[1];
            } elseif (preg_match('/^Expiry Date:\s*(.+?)\s*\((INVALID|VALID:\s*([\d.]+)\s*days?)\)/', $line, $m)) {
                $expiry = $m[1];
                $valid = str_starts_with($m[2], 'VALID') ? (int) $m[3] : -1;
            }
        }
        if ($name !== null) {
            $certs[] = compact('name', 'domains', 'expiry', 'valid');
        }
    }

    $statePill = function (?string $active): array {
        return match ($active) {
            'active' => ['classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest', 'label' => __('Active')],
            'failed' => ['classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600', 'label' => __('Failed')],
            'inactive' => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __('Inactive')],
            default => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __($active ?: 'unknown')],
        };
    };

    $inflightSwitch = $this->hasInflightWebserverSwitch();
    $preflight = app(\App\Services\Servers\WebserverSwitchPreflight::class);
    $recentSwitches = \App\Models\ServerWebserverAuditEvent::query()
        ->where('server_id', $server->id)
        ->orderByDesc('created_at')
        ->limit(5)
        ->get();
@endphp

<section class="space-y-6" aria-labelledby="manage-web-title">
    <h2 id="manage-web-title" class="sr-only">{{ __('Web stack') }}</h2>

    {{-- Active webserver card. Mirrors the MySQL/Redis card pattern in
         group-data.blade.php: title + version on the left, state pill on the
         right, action buttons below. The switching surface lives in its own
         card below — same shape as "Database connection hints" follows MySQL
         on the Data tab. --}}
    @php
        $activeInfo = $webserverCatalog[$activeWebserver] ?? null;
        $activeUnit = $activeInfo !== null ? $unitFor($activeInfo['systemd']) : null;
        $activePill = $statePill($activeUnit['active_state'] ?? null);
        $activeVersion = match ($activeWebserver) {
            'nginx' => $nginxVersion,
            default => '',
        };
        $activeActionTriad = match ($activeWebserver) {
            'nginx' => [['nginx_test_config', false], ['reload_nginx', false], ['restart_nginx', true]],
            'caddy' => [['caddy_test_config', false], ['reload_caddy', false], ['restart_caddy', true]],
            'apache' => [['apache_test_config', false], ['reload_apache', false], ['restart_apache', true]],
            'openlitespeed' => [['openlitespeed_test_config', false], ['reload_openlitespeed', false], ['restart_openlitespeed', true]],
            'traefik' => [['traefik_test_config', false], ['reload_traefik', true], ['restart_traefik', true]],
            'haproxy' => [['haproxy_test_config', false], ['reload_haproxy', false], ['restart_haproxy', true]],
            default => [],
        };
    @endphp

    @if ($activeInfo !== null)
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <div class="flex items-center gap-2">
                        <x-dynamic-component :component="$activeInfo['icon']" class="h-5 w-5 shrink-0 text-brand-forest" />
                        <h3 class="text-base font-semibold text-brand-ink">{{ $activeInfo['label'] }}</h3>
                    </div>
                    @if ($activeVersion !== '')
                        <p class="mt-1 font-mono text-xs text-brand-moss">{{ $activeVersion }}</p>
                    @endif
                </div>
                @if ($activeUnit !== null)
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $activePill['classes'] }}">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $activePill['dot'] }}"></span>
                        {{ $activePill['label'] }}
                    </span>
                @endif
            </div>

            @if ($opsReady && ! $isDeployer && $activeActionTriad !== [])
                <div class="mt-5 flex flex-wrap gap-2">
                    @foreach ($activeActionTriad as [$actionKey, $dangerous])
                        @if (! empty($serviceActions[$actionKey]))
                            @php $action = $serviceActions[$actionKey]; @endphp
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), {{ $dangerous ? 'true' : 'false' }})"
                                wire:loading.attr="disabled"
                                wire:target="openConfirmActionModal,runAllowlistedAction"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                            >
                                <span class="inline-flex items-center gap-2" wire:loading.remove wire:target="openConfirmActionModal,runAllowlistedAction">
                                    <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                                    {{ $action['label'] }}
                                </span>
                                <span class="inline-flex items-center gap-2" wire:loading wire:target="openConfirmActionModal,runAllowlistedAction">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Working…') }}
                                </span>
                            </button>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Switch webserver card. Companion to the active card, holds the
         description copy + alternative-webserver picker. Mirrors the
         "Database connection hints" full-width card below the active store. --}}
    <div class="{{ $card }} p-6 sm:p-8">
        <div class="max-w-2xl">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Switch webserver') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('One webserver per box. Switching reprovisions all sites under the new webserver — parallel install on :8080, then a brief service-swap to :80 (under 1 second blip).') }}
            </p>
        </div>

        @if ($inflightSwitch)
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
                {{ __('A webserver switch is currently running. Switch buttons are disabled until it settles — watch the progress banner at the top of this page.') }}
            </div>
        @endif

        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            @foreach ($webserverCatalog as $key => $info)
                @continue($key === $activeWebserver)
                @php
                    $isBlocked = $preflight->isBlocked($server, $key);
                @endphp
                <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                    <div class="flex items-start gap-2">
                        <x-dynamic-component :component="$info['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-brand-forest" />
                        <div class="min-w-0">
                            <p class="font-semibold text-brand-ink">{{ $info['label'] }}</p>
                        </div>
                    </div>

                    @if ($inflightSwitch)
                        <div class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-brand-sand/40 px-3 py-1.5 text-xs font-semibold text-brand-mist">
                            <x-spinner variant="forest" size="sm" />
                            <span>{{ __('Switching in progress…') }}</span>
                        </div>
                    @else
                        <button
                            type="button"
                            wire:click="openSwitchWebserver('{{ $key }}')"
                            wire:loading.attr="disabled"
                            wire:target="openSwitchWebserver"
                            @disabled($isDeployer || ! $opsReady || $isBlocked)
                            @class([
                                'mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition disabled:opacity-60',
                                'bg-brand-forest text-brand-cream shadow-sm shadow-brand-forest/20 hover:bg-brand-forest/90' => ! $isBlocked,
                                'cursor-not-allowed bg-brand-sand/40 text-brand-mist' => $isBlocked,
                            ])
                            title="{{ $isBlocked ? __('Unavailable — see preflight blocker') : '' }}"
                        >
                            <span class="inline-flex items-center gap-1.5" wire:loading.remove wire:target="openSwitchWebserver">
                                @if ($isBlocked)
                                    <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
                                    {{ __('Unavailable') }}
                                @else
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                    {{ __('Switch to :name', ['name' => $info['label']]) }}
                                @endif
                            </span>
                            <span class="inline-flex items-center gap-1.5" wire:loading wire:target="openSwitchWebserver">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Preparing…') }}
                            </span>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Recent webserver switches — surfaces ServerWebserverAuditEvent rows so
         operators can see who flipped what + when. Hidden when there are none
         to avoid noise on greenfield servers. --}}
    @if ($recentSwitches->isNotEmpty())
        <div class="{{ $card }} p-6 sm:p-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Switch history') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Recent webserver switches on this server.') }}</p>

            <ul class="mt-4 divide-y divide-brand-ink/8 overflow-hidden rounded-xl border border-brand-ink/10">
                @foreach ($recentSwitches as $event)
                    @php
                        $payload = is_array($event->payload) ? $event->payload : [];
                        $isSuccess = $event->result_status === \App\Models\ServerWebserverAuditEvent::RESULT_SUCCESS;
                        $isRollback = $event->action === \App\Models\ServerWebserverAuditEvent::ACTION_ROLLBACK;
                        $statusClasses = $isSuccess
                            ? 'bg-brand-sage/15 text-brand-forest ring-brand-sage/30'
                            : 'bg-rose-50 text-rose-800 ring-rose-200';
                        $sitesCount = (int) ($payload['sites_affected'] ?? 0);
                        $durationMs = (int) ($payload['duration_ms'] ?? 0);
                    @endphp
                    <li class="bg-white px-4 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-2 py-0.5 font-mono text-xs text-brand-ink">
                                    {{ $payload['from'] ?? '—' }}
                                    <x-heroicon-o-arrow-right class="h-3 w-3 text-brand-mist" />
                                    {{ $payload['to'] ?? '—' }}
                                </span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $statusClasses }}">
                                    @if ($isRollback)
                                        {{ __('rolled back') }}
                                    @elseif ($isSuccess)
                                        {{ __('success') }}
                                    @else
                                        {{ __('failed') }}
                                    @endif
                                </span>
                                @if ($sitesCount > 0)
                                    <span class="inline-flex items-center rounded-full bg-brand-ink/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                        {{ trans_choice(':n site|:n sites', $sitesCount, ['n' => $sitesCount]) }}
                                    </span>
                                @endif
                                @if (! empty($payload['tls_opt_in']))
                                    <span class="inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('TLS handover') }}</span>
                                @endif
                            </div>
                            <div class="text-right text-xs text-brand-moss">
                                <p>{{ optional($event->user)->name ?? __('system') }}</p>
                                <p class="text-brand-mist">{{ $event->created_at?->diffForHumans() }}</p>
                            </div>
                        </div>
                        @if (! $isSuccess && ! empty($payload['reason']))
                            <p class="mt-2 break-words font-mono text-[11px] text-rose-800">{{ $payload['reason'] }}</p>
                        @endif
                        @if ($durationMs > 0)
                            <p class="mt-1 text-[10px] text-brand-mist">
                                {{ __('Duration:') }} <span class="font-mono">{{ $durationMs < 1000 ? $durationMs.' ms' : round($durationMs / 1000, 1).' s' }}</span>
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Cascade confirmation modal — opened by openSwitchWebserver(). Renders the
         preflight blocker (if any), always-applied + opt-in cascades, computed
         downtime breakdown, and the manual list. Cancel + "Switch to <target>". --}}
    @if ($switch_plan !== null)
        <x-modal
            name="webserver-switch-modal"
            maxWidth="2xl"
            overlayClass="bg-brand-ink/40"
            panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        >
            <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Confirm switch') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Switch webserver?') }}</h2>
                <div class="mt-3 inline-flex flex-wrap items-center gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 font-mono text-sm text-brand-ink">
                    <span>{{ $switch_plan['from'] }}</span>
                    <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0 text-brand-mist" />
                    <span>{{ $switch_plan['to'] }}</span>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                @if ($switch_plan['blocker'] !== null)
                    <div class="rounded-xl border border-rose-200 bg-rose-50/70 px-4 py-3">
                        <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-900">
                            <x-heroicon-m-no-symbol class="h-3.5 w-3.5" />
                            {{ __('Cannot switch') }}
                        </p>
                        <p class="mt-2 text-sm leading-relaxed text-rose-900">{{ $switch_plan['blocker']['label'] }}</p>
                    </div>
                @else
                    {{-- Always applied --}}
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Always applied') }}</p>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($switch_plan['auto'] as $row)
                                <li class="flex items-start gap-2 text-sm text-brand-ink">
                                    <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                    <span>{{ $row['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- Opt-in --}}
                    @if (! empty($switch_plan['optIn']))
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Opt in') }}</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($switch_plan['optIn'] as $row)
                                    @php
                                        $wireModel = match ($row['key']) {
                                            'tls_to_caddy' => 'switch_tls_to_caddy',
                                            default => null,
                                        };
                                    @endphp
                                    @if ($wireModel)
                                        <li class="flex items-start gap-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                                            <input id="switch-optin-{{ $row['key'] }}" type="checkbox" wire:model="{{ $wireModel }}" class="mt-0.5 h-4 w-4 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                                            <label for="switch-optin-{{ $row['key'] }}" class="text-sm leading-relaxed text-brand-ink">{{ $row['label'] }}</label>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Computed downtime --}}
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Estimated timing') }}</p>
                        <ul class="mt-2 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                            @foreach ($switch_plan['downtime'] as $phase)
                                @php
                                    $secs = max(1, (int) round($phase['estimate_ms'] / 1000));
                                    $secLabel = $phase['estimate_ms'] < 1000
                                        ? trans_choice(':n ms|:n ms', $phase['estimate_ms'], ['n' => $phase['estimate_ms']])
                                        : trans_choice(':n second|:n seconds', $secs, ['n' => $secs]);
                                @endphp
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span class="text-brand-ink">{{ $phase['label'] }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="font-mono text-[11px] text-brand-moss">~{{ $secLabel }}</span>
                                        @if ($phase['blocking'])
                                            <span class="inline-flex items-center rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-rose-200">{{ __('downtime') }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">{{ __('live') }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- Manual / unfixable --}}
                    @if (! empty($switch_plan['manual']))
                        <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3">
                            <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-900">
                                <x-heroicon-m-information-circle class="h-3.5 w-3.5" />
                                {{ __('Cannot be fixed from here') }}
                            </p>
                            <ul class="mt-2 space-y-1 text-sm text-amber-900">
                                @foreach ($switch_plan['manual'] as $line)
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1.5 inline-block h-1 w-1 shrink-0 rounded-full bg-amber-700"></span>
                                        <span>{{ $line }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </div>

            <div class="shrink-0 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelSwitchWebserver">{{ __('Cancel') }}</x-secondary-button>
                @if ($switch_plan['blocker'] === null)
                    <x-primary-button type="button" wire:click="confirmSwitchWebserver" wire:loading.attr="disabled" wire:target="confirmSwitchWebserver">
                        <span wire:loading.remove wire:target="confirmSwitchWebserver">{{ __('Switch to :to', ['to' => $switch_plan['to']]) }}</span>
                        <span wire:loading wire:target="confirmSwitchWebserver">{{ __('Queueing…') }}</span>
                    </x-primary-button>
                @endif
            </div>
        </x-modal>
    @endif

    {{-- PHP-FPM (unchanged from previous render) --}}
    @if (! empty($phpFpm['versions']))
        <div class="{{ $card }} p-6 sm:p-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('PHP-FPM') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Detected installations under /etc/php/. Default is set in server meta and used by deploys and the per-row PHP-FPM actions.') }}
            </p>

            <div class="mt-4 overflow-hidden rounded-xl border border-brand-ink/10">
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                        <tr>
                            <th class="px-4 py-2 font-semibold">{{ __('Version') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Status') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Default') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Pools') }}</th>
                            @if ($opsReady && ! $isDeployer)
                                <th class="px-4 py-2 font-semibold text-right">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                        @foreach ($phpFpm['versions'] as $row)
                            @php $pill = $statePill($row['active'] ?? null); @endphp
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['version'] }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $pill['classes'] }}">
                                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                                        {{ $pill['label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    @if ($row['version'] === $defaultPhp)
                                        <span class="font-medium text-brand-forest">★ {{ __('Default') }}</span>
                                    @else
                                        <span class="text-brand-mist">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $row['pools_count'] }}</td>
                                @if ($opsReady && ! $isDeployer)
                                    <td class="px-4 py-2 text-right">
                                        <div class="inline-flex flex-wrap justify-end gap-1.5">
                                            @if (! empty($serviceActions['restart_php_fpm']))
                                                @php $a = $serviceActions['restart_php_fpm']; @endphp
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('runAllowlistedAction', ['restart_php_fpm'], @js($a['label']), @js($a['confirm']), @js($a['label']), false)"
                                                    class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                >{{ __('Restart') }}</button>
                                            @endif
                                            @if (! empty($serviceActions['reload_php_fpm']))
                                                @php $a = $serviceActions['reload_php_fpm']; @endphp
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('runAllowlistedAction', ['reload_php_fpm'], @js($a['label']), @js($a['confirm']), @js($a['label']), false)"
                                                    class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                >{{ __('Reload') }}</button>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-brand-mist">{{ __('Restart and Reload act on the default PHP version (set in server meta). Per-version targeting is on the roadmap.') }}</p>
        </div>
    @endif

    {{-- Certbot (unchanged from previous render) --}}
    @if (! empty($certbot['present']))
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('TLS / certbot') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Certificates managed by certbot on this server. Dry-run before renewing if you’re unsure.') }}</p>
                </div>
                @if ($opsReady && ! $isDeployer)
                    <div class="flex shrink-0 flex-wrap gap-2">
                        @foreach (['certbot_renew_dry_run', 'certbot_renew_all'] as $key)
                            @if (! empty($serviceActions[$key]))
                                @php $a = $serviceActions[$key]; @endphp
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $key }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), {{ $key === 'certbot_renew_all' ? 'true' : 'false' }})"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                >{{ $a['label'] }}</button>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            @if (! empty($certs))
                <div class="mt-5 overflow-hidden rounded-xl border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('Domains') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Expires') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Days remaining') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($certs as $cert)
                                @php
                                    $days = $cert['valid'];
                                    $tone = $days === null
                                        ? 'text-brand-moss'
                                        : ($days < 0 ? 'text-red-700 font-semibold' : ($days < 14 ? 'text-red-700 font-semibold' : ($days < 30 ? 'text-amber-700 font-medium' : 'text-brand-ink')));
                                @endphp
                                <tr>
                                    <td class="px-4 py-2 text-xs">
                                        <div class="font-medium text-brand-ink">{{ $cert['name'] }}</div>
                                        @if ($cert['domains'])
                                            <div class="font-mono text-[11px] text-brand-moss">{{ $cert['domains'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $cert['expiry'] ?: '—' }}</td>
                                    <td class="px-4 py-2 text-xs {{ $tone }}">
                                        @if ($days === null) — @elseif ($days < 0) {{ __('Invalid') }} @else {{ trans_choice(':n day|:n days', $days, ['n' => $days]) }} @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-brand-moss">{{ __('certbot is installed but no certificates are managed yet.') }}</p>
            @endif
        </div>
    @endif
</section>
