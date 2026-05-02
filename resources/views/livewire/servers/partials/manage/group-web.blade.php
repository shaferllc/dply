@php
    $meta = $server->meta ?? [];
    $nginx = is_array($meta['manage_nginx'] ?? null) ? $meta['manage_nginx'] : [];
    $phpFpm = is_array($meta['manage_php_fpm'] ?? null) ? $meta['manage_php_fpm'] : ['versions' => []];
    $certbot = is_array($meta['manage_certbot'] ?? null) ? $meta['manage_certbot'] : ['present' => false];
    $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];
    $defaultPhp = (string) ($meta['default_php_version'] ?? '8.3');

    // Find nginx unit + last reload (ActiveEnterTimestamp).
    $nginxUnit = null;
    foreach ($units as $u) {
        if (($u['unit'] ?? null) === 'nginx') {
            $nginxUnit = $u;
            break;
        }
    }

    $nginxVersion = (string) ($nginx['version'] ?? '');
    if ($nginxVersion !== '' && preg_match('#nginx/(\S+)#', $nginxVersion, $vm)) {
        $nginxVersion = $vm[1];
    }

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
                if (str_starts_with($m[2], 'VALID')) {
                    $valid = (int) $m[3];
                } else {
                    $valid = -1;
                }
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
@endphp

<section class="space-y-6" aria-labelledby="manage-web-title">
    <h2 id="manage-web-title" class="sr-only">{{ __('Web stack') }}</h2>

    {{-- nginx --}}
    @if (! empty($nginx['present']))
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('nginx') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Test the configuration before reloading. Restart only when necessary.') }}</p>
                </div>
                @if ($nginxUnit)
                    @php $pill = $statePill($nginxUnit['active_state'] ?? null); @endphp
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $pill['classes'] }}">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                        {{ $pill['label'] }}
                    </span>
                @endif
            </div>

            <dl class="mt-5 grid gap-4 sm:grid-cols-3 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $nginxVersion ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Sites enabled') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $nginx['sites_enabled_count'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('conf.d files') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $nginx['conf_d_count'] ?? '—' }}</dd>
                </div>
            </dl>

            @if ($opsReady && ! $isDeployer)
                <div class="mt-6 flex flex-wrap gap-2">
                    @foreach (['nginx_test_config', 'reload_nginx', 'restart_nginx'] as $key)
                        @if (! empty($serviceActions[$key]))
                            @php $action = $serviceActions[$key]; @endphp
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $key }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), {{ $key === 'restart_nginx' ? 'true' : 'false' }})"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                                {{ $action['label'] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @else
        <div class="{{ $card }} p-6 sm:p-8 text-sm text-brand-moss">
            {{ __('nginx is not detected on this server.') }}
        </div>
    @endif

    {{-- PHP-FPM --}}
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

    {{-- Certbot --}}
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

    @if (empty($nginx['present']) && empty($phpFpm['versions']) && empty($certbot['present']))
        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-white px-6 py-8 text-center text-sm text-brand-moss">
            <p>{{ __('No web-stack data yet. Run a probe refresh from the Overview tab to detect nginx, PHP-FPM, and certbot.') }}</p>
        </div>
    @endif
</section>
