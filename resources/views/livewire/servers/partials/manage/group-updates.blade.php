@php
    $meta = $server->meta ?? [];
    $upgrades = $meta['inventory_upgradable_packages'] ?? null;
    $reboot = $meta['inventory_reboot_required'] ?? null;
    $lastAptUpdate = $meta['manage_last_apt_update'] ?? null;
    $unattended = is_array($meta['manage_unattended_upgrades'] ?? null) ? $meta['manage_unattended_upgrades'] : ['present' => false, 'enabled' => null];
    $pkgPreview = isset($meta['inventory_upgradable_preview']) && is_string($meta['inventory_upgradable_preview']) ? $meta['inventory_upgradable_preview'] : '';

    // Parse upgradable preview into structured rows (same as Settings → Inventory).
    $rows = [];
    $securityCount = 0;
    foreach (explode("\n", $pkgPreview) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'Listing') || str_starts_with($line, '[dply]')) {
            continue;
        }
        if (preg_match('#^([^/\s]+)/(\S+)\s+(\S+)\s+(\S+)(?:\s+\[upgradable from:\s*(.+?)\])?$#', $line, $m)) {
            $sources = $m[2];
            $isSecurity = (bool) preg_match('/-security|esm-/i', $sources);
            if ($isSecurity) {
                $securityCount++;
            }
            $rows[] = [
                'name' => $m[1], 'sources' => $sources, 'new_version' => $m[3],
                'arch' => $m[4], 'current_version' => $m[5] ?? null, 'is_security' => $isSecurity,
            ];
        }
    }
@endphp

<section class="space-y-6" aria-labelledby="manage-updates-title">
    <h2 id="manage-updates-title" class="sr-only">{{ __('Updates') }}</h2>

    {{-- Status strip --}}
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl border border-brand-ink/10 bg-white px-5 py-3 text-sm">
        <span class="inline-flex items-center gap-2">
            <span class="text-2xl font-semibold text-brand-ink leading-none">{{ $upgrades ?? '—' }}</span>
            <span class="text-xs text-brand-moss">{{ __('upgradable') }}</span>
        </span>
        @if ($securityCount > 0)
            <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-red-800">
                {{ trans_choice(':n security|:n security', $securityCount, ['n' => $securityCount]) }}
            </span>
        @endif
        @if ($reboot === true)
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900">
                {{ __('Reboot pending') }}
            </span>
        @endif
        <span class="ml-auto text-xs text-brand-moss">
            @if ($lastAptUpdate)
                {{ __('apt updated :t', ['t' => \Illuminate\Support\Carbon::parse($lastAptUpdate)->diffForHumans()]) }}
            @else
                {{ __('apt update timestamp unknown') }}
            @endif
        </span>
    </div>

    {{-- Apt actions --}}
    @if ($opsReady && ! $isDeployer)
        <div class="{{ $card }} p-6 sm:p-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Apt actions') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Each runs over SSH; output streams in the panel above. Long-running upgrades trigger a state refresh on completion.') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach (['apt_update', 'apt_upgrade', 'apt_dist_upgrade', 'apt_autoremove', 'apt_clean'] as $key)
                    @if (! empty($serviceActions[$key]))
                        @php
                            $a = $serviceActions[$key];
                            $danger = in_array($key, ['apt_upgrade', 'apt_dist_upgrade'], true);
                        @endphp
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $key }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), {{ $danger ? 'true' : 'false' }})"
                            @class([
                                'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                                'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $danger,
                                'border border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100' => $danger,
                            ])
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4 opacity-80" aria-hidden="true" />
                            {{ $a['label'] }}
                        </button>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- Outdated packages --}}
    @if (! empty($rows))
        <div
            class="{{ $card }} p-6 sm:p-8"
            x-data="{ filter: 'all', q: '' }"
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Outdated packages') }}</h3>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ trans_choice(':count package can be upgraded.|:count packages can be upgraded.', count($rows), ['count' => count($rows)]) }}
                        @if ($securityCount > 0)
                            · <span class="font-medium text-red-700">{{ trans_choice(':n flagged as security|:n flagged as security', $securityCount, ['n' => $securityCount]) }}</span>
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-lg border border-brand-ink/15 bg-white p-0.5 text-xs">
                        <button type="button" x-on:click="filter = 'all'" :class="filter === 'all' ? 'bg-brand-sage/15 text-brand-ink' : 'text-brand-moss hover:text-brand-ink'" class="rounded-md px-2.5 py-1 font-medium">{{ __('All') }}</button>
                        <button type="button" x-on:click="filter = 'security'" :class="filter === 'security' ? 'bg-red-100 text-red-800' : 'text-brand-moss hover:text-brand-ink'" class="rounded-md px-2.5 py-1 font-medium">{{ __('Security') }}</button>
                    </div>
                    <input
                        type="search"
                        x-model="q"
                        placeholder="{{ __('Filter by name…') }}"
                        class="w-44 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    />
                </div>
            </div>

            <div class="mt-3 max-h-80 overflow-auto rounded-lg border border-brand-ink/10">
                <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                    <thead class="sticky top-0 bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                        <tr>
                            <th class="px-3 py-2 font-semibold">{{ __('Package') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Current') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('New') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('Source') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @foreach ($rows as $row)
                            <tr
                                x-show="(filter === 'all' || {{ $row['is_security'] ? 'true' : 'false' }}) && (q === '' || @js($row['name']).toLowerCase().includes(q.toLowerCase()))"
                                class="{{ $row['is_security'] ? 'bg-red-50/40' : 'bg-white' }}"
                            >
                                <td class="px-3 py-1.5 font-mono text-brand-ink">
                                    {{ $row['name'] }}
                                    @if ($row['is_security'])
                                        <span class="ml-1 inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-800">{{ __('Security') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 font-mono text-brand-moss">{{ $row['current_version'] ?? '—' }}</td>
                                <td class="px-3 py-1.5 font-mono text-brand-ink">{{ $row['new_version'] ?? '—' }}</td>
                                <td class="px-3 py-1.5 font-mono text-brand-mist">{{ $row['sources'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Unattended upgrades --}}
    <div id="manage-os-updates" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Unattended-upgrades') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Server-side automatic update flag (Debian/Ubuntu). The cadence preference below is recorded for future Dply scheduling.') }}</p>
            </div>
            @php
                $statusPill = match (true) {
                    ! ($unattended['present'] ?? false) => ['label' => __('Not installed'), 'classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist'],
                    ($unattended['enabled'] ?? null) === true => ['label' => __('Enabled'), 'classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest'],
                    ($unattended['enabled'] ?? null) === false => ['label' => __('Disabled'), 'classes' => 'bg-amber-100 text-amber-900', 'dot' => 'bg-amber-500'],
                    default => ['label' => __('Unknown'), 'classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist'],
                };
            @endphp
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $statusPill['classes'] }}">
                <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusPill['dot'] }}"></span>
                {{ $statusPill['label'] }}
            </span>
        </div>

        @if (! empty($unattended['snippet']))
            <pre class="mt-4 max-h-32 overflow-auto rounded-lg border border-brand-ink/10 bg-brand-sand/15 p-3 font-mono text-[11px] leading-relaxed text-brand-ink">{{ $unattended['snippet'] }}</pre>
        @endif

        @if ($opsReady && ! $isDeployer)
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach (['unattended_upgrades_enable', 'unattended_upgrades_disable'] as $key)
                    @if (! empty($serviceActions[$key]))
                        @php $a = $serviceActions[$key]; @endphp
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $key }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), false)"
                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                        >{{ $a['label'] }}</button>
                    @endif
                @endforeach
            </div>
        @endif

        <form wire:submit="saveManageMetadata" class="mt-6 max-w-xl border-t border-brand-ink/10 pt-6 space-y-4">
            <div>
                <label for="manage_auto_updates_interval" class="block text-sm font-medium text-brand-ink">{{ __('Cadence preference (recorded only)') }}</label>
                <select
                    id="manage_auto_updates_interval"
                    wire:model="manage_auto_updates_interval"
                    @disabled($isDeployer)
                    class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                >
                    @foreach ($autoUpdateIntervals as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-brand-mist">{{ __('Saved on the server in Dply. Does not currently configure unattended-upgrades — use the buttons above for that.') }}</p>
                @error('manage_auto_updates_interval')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <x-primary-button type="submit" class="!py-2.5" :disabled="$isDeployer">{{ __('Save cadence') }}</x-primary-button>
            </div>
        </form>
    </div>

    {{-- Reboot pending card (only when relevant) --}}
    @if ($reboot === true)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-semibold">{{ __('Reboot is pending on this server') }}</p>
                    <p class="mt-1 text-xs">{{ __('Likely required after kernel or libc updates. Plan a maintenance window before rebooting.') }}</p>
                </div>
                @if ($opsReady && ! $isDeployer && ! empty($dangerousActions['reboot']))
                    @php $r = $dangerousActions['reboot']; @endphp
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedAction', ['reboot'], @js($r['label']), @js($r['confirm']), @js($r['label']), true)"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-900 hover:bg-red-100"
                    >
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                        {{ $r['label'] }}
                    </button>
                @endif
            </div>
        </div>
    @endif
</section>
