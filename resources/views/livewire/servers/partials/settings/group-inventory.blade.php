@php
    $osVersions = $osVersions ?? config('server_settings.os_versions', []);

    // Parse `apt list --upgradable` preview into structured rows.
    // Format: package/source[,source ...] new_version arch [upgradable from: current]
    $upgradableRows = [];
    $securityCount = 0;
    if (! empty($pkgPreview)) {
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
                $upgradableRows[] = [
                    'name' => $m[1],
                    'sources' => $sources,
                    'new_version' => $m[3],
                    'arch' => $m[4],
                    'current_version' => $m[5] ?? null,
                    'is_security' => $isSecurity,
                ];
            } else {
                $upgradableRows[] = [
                    'name' => $line,
                    'sources' => null,
                    'new_version' => null,
                    'arch' => null,
                    'current_version' => null,
                    'is_security' => false,
                ];
            }
        }
    }

    // Split extended snapshot into named sections (script emits `\n---\n` between blocks).
    $extSections = [];
    if (! empty($extSnap)) {
        $parts = preg_split('/\R---\R/', $extSnap);
        $labels = [
            __('Disk usage (df -h)'),
            __('Uptime / load'),
            __('Memory (free -h)'),
            __('fail2ban'),
        ];
        foreach ($parts as $i => $body) {
            $body = trim((string) $body);
            if ($body === '') {
                continue;
            }
            $extSections[] = [
                'label' => $labels[$i] ?? __('Section :n', ['n' => $i + 1]),
                'body' => $body,
            ];
        }
    }
@endphp

<section id="settings-group-inventory" class="space-y-4" aria-labelledby="settings-group-inventory-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-inventory-title',
        'kicker' => __('Host'),
        'title' => __('Inventory & provider snapshot'),
        'description' => __('Dply can SSH in and read OS/package state on Debian-based images (apt). Provider metadata comes from provisioning. None of this changes the server—it is visibility for your team.'),
    ])

    <div id="settings-updates" class="{{ $card }} scroll-mt-24 overflow-hidden p-6 sm:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="max-w-2xl">
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Refresh & scan') }}</h3>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                    {{ __('Run a fresh check over SSH to count upgradable packages, detect reboot flags, and optionally capture disk/memory/uptime. Automatic unattended upgrades are configured under Manage, not here.') }}
                </p>
                <p class="mt-3 text-sm">
                    <a
                        href="{{ route('servers.manage', ['server' => $server, 'section' => 'updates']) }}#manage-os-updates"
                        class="font-medium text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:text-brand-ink"
                    >{{ __('Configure automatic security updates (Manage)') }}</a>
                </p>
            </div>
            @if ($this->canEditServerSettings)
                <div class="flex shrink-0 flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="refreshServerInventoryDetails"
                        wire:loading.attr="disabled"
                        class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="refreshServerInventoryDetails">{{ __('Refresh inventory') }}</span>
                        <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-2">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Running…') }}
                        </span>
                    </button>
                    <button
                        type="button"
                        wire:click="checkHealth"
                        wire:loading.attr="disabled"
                        wire:target="checkHealth"
                        class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="checkHealth">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="checkHealth" class="inline-flex items-center gap-2">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Probing…') }}
                        </span>
                    </button>
                </div>
            @endif
        </div>

        @if ($this->testConnectionResult !== null)
            @php $tc = $this->testConnectionResult; @endphp
            <div
                class="mt-4 rounded-xl border p-4 text-sm {{ $tc['ok'] ? 'border-brand-sage/40 bg-brand-sage/10 text-brand-ink' : 'border-red-200 bg-red-50 text-red-900' }}"
                role="status"
                aria-live="polite"
            >
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                    <span class="inline-flex items-center gap-2 font-semibold">
                        @if ($tc['ok'])
                            <span aria-hidden="true" class="inline-block h-2 w-2 rounded-full bg-brand-forest"></span>
                            {{ __('Reachable') }}
                        @else
                            <span aria-hidden="true" class="inline-block h-2 w-2 rounded-full bg-red-600"></span>
                            {{ __('Unreachable') }}
                        @endif
                    </span>
                    @if ($tc['latency_ms'] !== null)
                        <span class="text-xs">{{ __(':n ms', ['n' => $tc['latency_ms']]) }}</span>
                    @endif
                    @if ($tc['method'])
                        <span class="text-xs uppercase tracking-wide opacity-70">{{ $tc['method'] }}</span>
                    @endif
                    @if ($tc['host'] && $tc['port'])
                        <span class="font-mono text-xs">{{ $tc['host'] }}:{{ $tc['port'] }}</span>
                    @endif
                    @if ($tc['http_status'])
                        <span class="text-xs">{{ __('HTTP :s', ['s' => $tc['http_status']]) }}</span>
                    @endif
                    <span class="ml-auto text-xs opacity-70">
                        {{ __('Tested :t', ['t' => \Illuminate\Support\Carbon::parse($tc['tested_at'])->diffForHumans()]) }}
                    </span>
                </div>
                @if (! $tc['ok'] && ! empty($tc['error']))
                    <p class="mt-2 text-xs">{{ $tc['error'] }}</p>
                @endif
            </div>
        @endif

        <div class="mt-8 rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-5">
            <h4 class="text-sm font-semibold text-brand-ink">{{ __('Inventory scan depth') }}</h4>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Basic: OS detection and apt preview. Extended: adds disk usage, memory, uptime, and fail2ban status (slightly longer SSH run).') }}
            </p>
            <form wire:submit="saveInventoryDepthPreference" class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="min-w-[min(100%,20rem)] flex-1">
                    <x-input-label for="settings-inventory-depth" value="{{ __('Depth') }}" />
                    <select
                        id="settings-inventory-depth"
                        wire:model="settingsInventoryDepth"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        @disabled(! $this->canEditServerSettings)
                    >
                        @foreach ($inventoryDepths as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('settingsInventoryDepth')" class="mt-2" />
                </div>
                @if ($this->canEditServerSettings)
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save depth') }}</x-primary-button>
                @endif
            </form>
        </div>

        @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 18])

        <div class="mt-8 border-t border-brand-ink/10 pt-8">
            <h4 class="text-sm font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider & lifecycle') }}</h4>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                    <dt class="text-brand-mist">{{ __('Created in Dply') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $server->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Provider') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $providerLine }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Region') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $server->region ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Provider server ID') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $server->provider_id ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Status') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ __($server->status) }} @if ($server->health_status) / {{ __($server->health_status) }} @endif</dd>
                </div>
                @if ($invAt)
                    <div class="sm:col-span-2">
                        <dt class="text-brand-mist">{{ __('Inventory last checked') }}</dt>
                        <dd class="mt-0.5 text-xs text-brand-moss">{{ \Illuminate\Support\Carbon::parse($invAt)->timezone(config('app.timezone'))->toDayDateTimeString() }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="mt-8 border-t border-brand-ink/10 pt-8">
            <h4 class="text-sm font-semibold uppercase tracking-wide text-brand-mist">{{ __('Packages & OS detection') }}</h4>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                    <dt class="text-brand-mist">{{ __('Available updates (apt)') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">
                        @if ($upgrades !== null)
                            {{ trans_choice(':count package can be updated.|:count packages can be updated.', $upgrades, ['count' => $upgrades]) }}
                        @else
                            {{ __('Run “Refresh inventory” on a Debian/Ubuntu host to count upgradable packages.') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Reboot pending') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">
                        @if ($reboot === null)
                            {{ __('Unknown') }}
                        @elseif ($reboot)
                            {{ __('Yes') }}
                        @else
                            {{ __('No') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('OS label (in Dply)') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $osVersions[$meta['os_version'] ?? ''] ?? ($meta['os_version'] ?? '—') }}</dd>
                </div>
                @if (! empty($meta['inventory_os_pretty']))
                    <div class="sm:col-span-2">
                        <dt class="text-brand-mist">{{ __('Detected on server (/etc/os-release)') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">
                            <span class="font-medium">{{ $meta['inventory_os_pretty'] }}</span>
                            @if (isset($meta['inventory_os_detected_key']) && is_string($meta['inventory_os_detected_key']))
                                <span class="text-brand-moss"> — {{ $osVersions[$meta['inventory_os_detected_key']] ?? $meta['inventory_os_detected_key'] }}</span>
                            @endif
                            @if ($this->canEditServerSettings && isset($meta['inventory_os_detected_key']) && ($meta['os_version'] ?? '') !== ($meta['inventory_os_detected_key'] ?? ''))
                                <button
                                    type="button"
                                    wire:click="applyDetectedOsFromInventory"
                                    class="ml-2 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                >{{ __('Use detected label') }}</button>
                            @endif
                        </dd>
                    </div>
                @endif
            </dl>
        </div>

        @if (! empty($upgradableRows))
            <div
                class="mt-8 border-t border-brand-ink/10 pt-8"
                x-data="{ filter: 'all', q: '' }"
            >
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h4 class="text-sm font-semibold text-brand-ink">{{ __('Outdated packages') }}</h4>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ trans_choice(':count package can be upgraded.|:count packages can be upgraded.', count($upgradableRows), ['count' => count($upgradableRows)]) }}
                            @if ($securityCount > 0)
                                · <span class="font-medium text-red-700">{{ trans_choice(':n flagged as security|:n flagged as security', $securityCount, ['n' => $securityCount]) }}</span>
                            @endif
                            · {{ __('From apt list — not an install plan.') }}
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
                            @foreach ($upgradableRows as $row)
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
        @elseif ($pkgPreview !== null && $pkgPreview !== '')
            <div class="mt-8 border-t border-brand-ink/10 pt-8">
                <h4 class="text-sm font-semibold text-brand-ink">{{ __('Packages that may be upgraded (preview)') }}</h4>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Could not parse apt output — showing raw preview.') }}</p>
                <pre
                    class="mt-3 max-h-64 overflow-auto rounded-lg border border-brand-ink/10 bg-brand-sand/15 p-3 font-mono text-[11px] leading-relaxed text-brand-ink whitespace-pre-wrap break-all"
                    data-settings-upgradable-preview
                >{{ $pkgPreview }}</pre>
            </div>
        @endif

        @if (! empty($extSections))
            <div class="mt-8 border-t border-brand-ink/10 pt-8">
                <h4 class="text-sm font-semibold text-brand-ink">{{ __('Host snapshot') }}</h4>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Captured when scan depth is Extended.') }}</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ($extSections as $section)
                        <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $section['label'] }}</div>
                            <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all font-mono text-[11px] leading-relaxed text-brand-ink">{{ $section['body'] }}</pre>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($extSnap !== null && $extSnap !== '')
            <div class="mt-8 border-t border-brand-ink/10 pt-8">
                <h4 class="text-sm font-semibold text-brand-ink">{{ __('Extended snapshot') }}</h4>
                <pre
                    class="mt-3 max-h-64 overflow-auto rounded-lg border border-brand-ink/10 bg-brand-sand/15 p-3 font-mono text-[11px] leading-relaxed text-brand-ink whitespace-pre-wrap break-all"
                >{{ $extSnap }}</pre>
            </div>
        @endif
    </div>
</section>
