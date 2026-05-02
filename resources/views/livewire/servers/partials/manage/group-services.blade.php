@php
    $meta = $server->meta ?? [];
    $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];
    $checkedAt = $meta['inventory_checked_at'] ?? null;

    // Map a unit name to the per-row action keys (action defs live in config/server_manage.service_actions).
    // Discovered php{X}-fpm units share the same action set as the canonical 'php-fpm' alias.
    $unitActions = [
        'nginx' => ['restart_nginx', 'reload_nginx'],
        'redis-server' => ['restart_redis'],
        'redis' => ['restart_redis'],
        'mysql' => ['restart_mysql'],
        'mariadb' => ['restart_mysql'],
        'fail2ban' => [],
        'supervisor' => [],
        'supervisord' => [],
    ];
    $isPhpFpm = fn (string $unit) => (bool) preg_match('/^php[\d.]+-fpm$/', $unit);

    $statePill = function (?string $active): array {
        return match ($active) {
            'active' => ['classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest', 'label' => __('Active')],
            'failed' => ['classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600', 'label' => __('Failed')],
            'inactive' => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __('Inactive')],
            'activating' => ['classes' => 'bg-amber-100 text-amber-900', 'dot' => 'bg-amber-500', 'label' => __('Starting')],
            'deactivating' => ['classes' => 'bg-amber-100 text-amber-900', 'dot' => 'bg-amber-500', 'label' => __('Stopping')],
            default => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __($active ?: 'unknown')],
        };
    };

    $formatBytes = function (?int $bytes): string {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $val = (float) $bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }

        return rtrim(rtrim(number_format($val, $val < 10 ? 1 : 0), '0'), '.').' '.$units[$i];
    };

    $formatSince = function (?string $ts): string {
        if (! $ts || $ts === '') {
            return '—';
        }
        try {
            return \Illuminate\Support\Carbon::parse($ts)->diffForHumans(['short' => true, 'parts' => 1]);
        } catch (\Throwable) {
            return '—';
        }
    };

    // Parse listening-ports block for a small port table.
    $portRows = [];
    if (! empty($meta['manage_listening_ports'])) {
        foreach (explode("\n", $meta['manage_listening_ports']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // ss -lntpH columns: State Recv-Q Send-Q Local Address:Port Peer Address:Port Process
            $cols = preg_split('/\s+/', $line);
            if (count($cols) < 5) {
                continue;
            }
            $local = $cols[3] ?? '';
            if (! preg_match('/(\S+):(\d+)$/', $local, $m)) {
                continue;
            }
            $bind = $m[1];
            $port = $m[2];
            $proc = '';
            if (! empty($cols[5]) && preg_match('/users:\(\("([^"]+)"/', implode(' ', array_slice($cols, 5)), $pm)) {
                $proc = $pm[1];
            }
            $portRows[] = ['port' => $port, 'bind' => $bind, 'process' => $proc];
        }
        usort($portRows, fn ($a, $b) => (int) $a['port'] <=> (int) $b['port']);
    }
@endphp

<section class="space-y-6" aria-labelledby="manage-services-title">
    <div class="{{ $card }} p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h2 id="manage-services-title" class="text-lg font-semibold text-brand-ink">{{ __('Services') }}</h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                    {{ __('Live status of watched systemd units. Each row’s actions are queued over SSH; output streams into the panel above.') }}
                </p>
            </div>
            @if ($opsReady && ! $isDeployer)
                <button
                    type="button"
                    wire:click="refreshServerInventoryDetails"
                    wire:loading.attr="disabled"
                    wire:target="refreshServerInventoryDetails"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
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
        </div>

        @if (empty($units))
            <div class="mt-6 rounded-xl border border-dashed border-brand-ink/15 px-4 py-6 text-center text-sm text-brand-moss">
                {{ __('No unit data yet — click “Refresh state” above to probe the server.') }}
            </div>
        @else
            <div class="mt-6 overflow-hidden rounded-xl border border-brand-ink/10">
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                        <tr>
                            <th class="px-4 py-2 font-semibold">{{ __('Status') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Unit') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Since') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Memory') }}</th>
                            <th class="px-4 py-2 font-semibold text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                        @foreach ($units as $u)
                            @php
                                $unitName = (string) ($u['unit'] ?? '');
                                $pill = $statePill($u['active_state'] ?? null);
                                $actionKeys = $isPhpFpm($unitName)
                                    ? ['restart_php_fpm']
                                    : ($unitActions[$unitName] ?? []);
                            @endphp
                            <tr>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $pill['classes'] }}">
                                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                                        {{ $pill['label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $unitName }}</td>
                                <td class="px-4 py-2 text-xs text-brand-moss">{{ $formatSince($u['active_enter_at'] ?? null) }}</td>
                                <td class="px-4 py-2 text-xs text-brand-moss">{{ $formatBytes($u['memory_current_bytes'] ?? null) }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($opsReady && ! $isDeployer && ! empty($actionKeys))
                                        <div class="inline-flex flex-wrap justify-end gap-1.5">
                                            @foreach ($actionKeys as $actionKey)
                                                @if (! empty($serviceActions[$actionKey]))
                                                    @php $action = $serviceActions[$actionKey]; @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), false)"
                                                        class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                    >{{ $action['label'] }}</button>
                                                @endif
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-[11px] text-brand-mist">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if (! empty($portRows))
        <div class="{{ $card }} p-6 sm:p-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Listening ports') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('From `ss -lntp`. Useful for sanity-checking which process is bound where.') }}</p>
            <div class="mt-4 overflow-hidden rounded-xl border border-brand-ink/10">
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                        <tr>
                            <th class="px-4 py-2 font-semibold">{{ __('Port') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Process') }}</th>
                            <th class="px-4 py-2 font-semibold">{{ __('Bind address') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                        @foreach ($portRows as $row)
                            <tr>
                                <td class="px-4 py-1.5 font-mono text-xs text-brand-ink">{{ $row['port'] }}</td>
                                <td class="px-4 py-1.5 font-mono text-xs text-brand-ink">{{ $row['process'] ?: '—' }}</td>
                                <td class="px-4 py-1.5 font-mono text-xs text-brand-moss">{{ $row['bind'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($checkedAt)
        <p class="text-right text-xs text-brand-moss">
            {{ __('State refreshed :t', ['t' => \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans()]) }}
        </p>
    @endif
</section>
