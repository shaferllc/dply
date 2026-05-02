@php
    $meta = $server->meta ?? [];
    $mysql = is_array($meta['manage_mysql'] ?? null) ? $meta['manage_mysql'] : [];
    $redis = is_array($meta['manage_redis'] ?? null) ? $meta['manage_redis'] : [];
    $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];

    $unitState = function (string $name) use ($units): ?string {
        foreach ($units as $u) {
            if (($u['unit'] ?? null) === $name) {
                return $u['active_state'] ?? null;
            }
        }

        return null;
    };

    // Parse Redis INFO lines into a flat key=>value map for the stat cards.
    $redisInfo = [];
    if (! empty($redis['info_raw']) && is_string($redis['info_raw'])) {
        foreach (explode("\n", $redis['info_raw']) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $redisInfo[trim($k)] = trim($v);
            }
        }
    }

    $hasCreds = ! empty($meta['manage_internal_db_password']);

    $statePill = function (?string $active): array {
        return match ($active) {
            'active' => ['classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest', 'label' => __('Active')],
            'failed' => ['classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600', 'label' => __('Failed')],
            'inactive' => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __('Inactive')],
            default => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __($active ?: 'unknown')],
        };
    };

    $mysqlPresent = ! empty($mysql['present']) || ! empty($mysql['mariadb_present']);
    $mysqlVersion = $mysql['version'] ?? ($mysql['mariadb_version'] ?? '');
    $mysqlFlavor = ! empty($mysql['mariadb_present']) ? 'MariaDB' : 'MySQL';
    $mysqlUnitName = ! empty($mysql['mariadb_present']) ? 'mariadb' : 'mysql';
    $mysqlState = $unitState($mysqlUnitName);

    // Format bytes for the redis used_memory card.
    $formatBytes = function ($bytes): string {
        if (! is_numeric($bytes) || $bytes <= 0) {
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

    $hitRate = null;
    if (isset($redisInfo['keyspace_hits'], $redisInfo['keyspace_misses'])) {
        $hits = (int) $redisInfo['keyspace_hits'];
        $misses = (int) $redisInfo['keyspace_misses'];
        if ($hits + $misses > 0) {
            $hitRate = round($hits / ($hits + $misses) * 100, 1);
        }
    }
@endphp

<section class="space-y-6" aria-labelledby="manage-data-title">
    <h2 id="manage-data-title" class="sr-only">{{ __('Data stores') }}</h2>

    {{-- MySQL / MariaDB --}}
    @if ($mysqlPresent)
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <h3 class="text-base font-semibold text-brand-ink">{{ $mysqlFlavor }}</h3>
                    @if ($mysqlVersion)
                        <p class="mt-1 font-mono text-xs text-brand-moss">{{ $mysqlVersion }}</p>
                    @endif
                </div>
                @if ($mysqlState !== null)
                    @php $pill = $statePill($mysqlState); @endphp
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $pill['classes'] }}">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                        {{ $pill['label'] }}
                    </span>
                @endif
            </div>

            @if (! $hasCreds)
                <div class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-xs text-brand-moss">
                    {{ __('Add a manage password in the Database connection hints below to unlock the processlist action.') }}
                </div>
            @endif

            @if ($opsReady && ! $isDeployer)
                <div class="mt-5 flex flex-wrap gap-2">
                    @foreach (['restart_mysql', 'mysql_processlist'] as $key)
                        @if (! empty($serviceActions[$key]))
                            @php $a = $serviceActions[$key]; @endphp
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $key }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), {{ $key === 'restart_mysql' ? 'true' : 'false' }})"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                                {{ $a['label'] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Redis --}}
    @if (! empty($redis['present']))
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Redis') }}</h3>
                    <p class="mt-1 font-mono text-xs text-brand-moss">
                        {{ $redisInfo['redis_version'] ?? '' }}
                        @if (! empty($redisInfo['uptime_in_seconds']))
                            · {{ __('uptime :u', ['u' => \Illuminate\Support\Carbon::now()->subSeconds((int) $redisInfo['uptime_in_seconds'])->diffForHumans(['short' => true, 'parts' => 1])]) }}
                        @endif
                    </p>
                </div>
                @php $pill = $statePill($unitState('redis-server') ?? $unitState('redis')); @endphp
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $pill['classes'] }}">
                    <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                    {{ $pill['label'] }}
                </span>
            </div>

            @if (! empty($redisInfo))
                <dl class="mt-5 grid gap-4 sm:grid-cols-4">
                    <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                        <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Connected clients') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ $redisInfo['connected_clients'] ?? '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                        <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Used memory') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ $redisInfo['used_memory_human'] ?? $formatBytes($redisInfo['used_memory'] ?? 0) }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                        <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Hit rate') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-brand-ink">{{ $hitRate !== null ? $hitRate.'%' : '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                        <dt class="text-xs uppercase tracking-wide text-brand-mist">{{ __('Last RDB save') }}</dt>
                        <dd class="mt-1 text-xs font-medium text-brand-ink">
                            @if (isset($redisInfo['rdb_last_save_time']))
                                {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $redisInfo['rdb_last_save_time'])->diffForHumans() }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>
            @endif

            @if ($opsReady && ! $isDeployer)
                <div class="mt-5 flex flex-wrap gap-2">
                    @foreach (['restart_redis', 'redis_info'] as $key)
                        @if (! empty($serviceActions[$key]))
                            @php $a = $serviceActions[$key]; @endphp
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $key }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), {{ $key === 'restart_redis' ? 'true' : 'false' }})"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                                {{ $a['label'] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if (! $mysqlPresent && empty($redis['present']))
        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-white px-6 py-8 text-center text-sm text-brand-moss">
            <p>{{ __('No data stores detected. Run a probe refresh from the Overview tab to scan for MySQL/MariaDB and Redis.') }}</p>
        </div>
    @endif

    {{-- Database connection hints (existing form, kept) --}}
    <div id="manage-data-hints" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Database connection hints') }}</h3>
        <p class="mt-1 text-sm text-brand-moss leading-relaxed">
            {{ __('Optional values for Dply features such as backups, and to populate live stats / processlist on this Manage tab.') }}
        </p>
        <form wire:submit="saveManageMetadata" class="mt-6 max-w-xl space-y-4">
            <div>
                <label for="manage_db_bind_host" class="block text-sm font-medium text-brand-ink">{{ __('Database bind address') }}</label>
                <input
                    id="manage_db_bind_host"
                    type="text"
                    wire:model="manage_db_bind_host"
                    placeholder="127.0.0.1"
                    autocomplete="off"
                    @disabled($isDeployer)
                    class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                />
                @error('manage_db_bind_host')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="manage_db_port" class="block text-sm font-medium text-brand-ink">{{ __('Database port') }}</label>
                <input
                    id="manage_db_port"
                    type="number"
                    wire:model="manage_db_port"
                    placeholder="3306"
                    min="1"
                    max="65535"
                    autocomplete="off"
                    @disabled($isDeployer)
                    class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                />
                @error('manage_db_port')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="manage_db_password" class="block text-sm font-medium text-brand-ink">{{ __('Internal database password') }}</label>
                <input
                    id="manage_db_password"
                    type="password"
                    wire:model="manage_db_password"
                    placeholder="{{ __('Leave blank to keep current') }}"
                    autocomplete="new-password"
                    @disabled($isDeployer)
                    class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                />
                <p class="mt-1 text-xs text-brand-mist">{{ __('Used to read live database stats and run the MySQL processlist action. Stored in server metadata; treat as sensitive.') }}</p>
            </div>
            <div>
                <x-primary-button type="submit" class="!py-2.5" :disabled="$isDeployer">{{ __('Save connection hints') }}</x-primary-button>
            </div>
        </form>
    </div>
</section>
