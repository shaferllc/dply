@php
    $osVersions = $osVersions ?? config('server_settings.os_versions', []);

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
        'description' => __('Dply can SSH in and read OS/package state on Debian-based images (apt). Provider metadata comes from provisioning. Pending updates and package lists live on Patches — this tab is for scan controls and host metadata.'),
    ])

    <div id="settings-updates" class="{{ $card }} scroll-mt-24 overflow-hidden p-6 sm:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="max-w-2xl">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Refresh & scan') }}</h3>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                    {{ __('Run a fresh check over SSH to count upgradable packages, detect reboot flags, and optionally capture disk/memory/uptime. Apt actions and unattended-upgrades live on Patches.') }}
                </p>
                <p class="mt-3 text-sm">
                    @feature('workspace.patch_advisor')
                        <a
                            href="{{ route('servers.patches', $server).'#patch-unattended-upgrades' }}"
                            wire:navigate
                            class="font-medium text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:text-brand-ink"
                        >{{ __('Configure automatic security updates (Patches)') }}</a>
                    @else
                        <a
                            href="{{ route('servers.manage', ['server' => $server, 'section' => 'updates']) }}#manage-os-updates"
                            wire:navigate
                            class="font-medium text-brand-forest underline decoration-brand-sage/40 underline-offset-2 hover:text-brand-ink"
                        >{{ __('Configure automatic security updates (Manage)') }}</a>
                    @endfeature
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

        @if ($upgrades !== null || $reboot !== null)
            <div class="mt-8 rounded-xl border border-brand-ink/10 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h4 class="text-sm font-semibold text-brand-ink">{{ __('Pending updates') }}</h4>
                        <p class="mt-1 text-sm text-brand-moss">
                            @if ($upgrades !== null)
                                {{ trans_choice(':count package can be updated.|:count packages can be updated.', $upgrades, ['count' => $upgrades]) }}
                            @endif
                            @if ($reboot !== null)
                                · {{ __('Reboot pending') }}:
                                <span class="font-medium text-brand-ink">{{ $reboot ? __('Yes') : __('No') }}</span>
                            @endif
                        </p>
                    </div>
                    @feature('workspace.patch_advisor')
                        <a
                            href="{{ route('servers.patches', $server) }}"
                            wire:navigate
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            {{ __('Open Patches') }}
                            <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </a>
                    @else
                        <a
                            href="{{ route('servers.manage', ['server' => $server, 'section' => 'updates']) }}"
                            wire:navigate
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            {{ __('Manage → Updates') }}
                            <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </a>
                    @endfeature
                </div>
            </div>
        @endif

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
            <h4 class="text-sm font-semibold uppercase tracking-wide text-brand-mist">{{ __('OS label') }}</h4>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
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
