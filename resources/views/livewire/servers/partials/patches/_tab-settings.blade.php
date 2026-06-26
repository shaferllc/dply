@php
    $extSections = [];
    if (! empty($extendedSnapshot)) {
        $parts = preg_split('/\R---\R/', $extendedSnapshot);
        $extLabels = [
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
                'label' => $extLabels[$i] ?? __('Section :n', ['n' => $i + 1]),
                'body' => $body,
            ];
        }
    }

    $unattendedEnabled = $report['unattended']['enabled'];
    $unattendedPresent = $report['unattended']['present'];

    $statusPill = match (true) {
        ! $unattendedPresent => ['label' => __('Not installed'), 'classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist'],
        $unattendedEnabled === true => ['label' => __('Enabled'), 'classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest'],
        $unattendedEnabled === false => ['label' => __('Disabled'), 'classes' => 'bg-amber-100 text-amber-900', 'dot' => 'bg-amber-500'],
        default => ['label' => __('Unknown'), 'classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist'],
    };

    $statusSummary = match (true) {
        ! $unattendedPresent => __('The unattended-upgrades package was not detected on the last scan.'),
        $unattendedEnabled === true => __('Security updates can apply automatically in the background.'),
        $unattendedEnabled === false => __('Automatic security updates are turned off on this server.'),
        default => __('Enable state could not be determined — run Refresh scan, then enable or disable below.'),
    };

    $showEnableAction = $unattendedPresent && $unattendedEnabled !== true;
    $showDisableAction = $unattendedPresent && $unattendedEnabled === true;
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-cog-6-tooth class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Scan') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Scan settings') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Basic: OS + apt list. Extended: also captures disk, memory, uptime, and fail2ban.') }}
            </p>
        </div>
        @if ($opsReady && ! $isDeployer)
            <button
                type="button"
                wire:click="refreshServerInventoryDetails"
                wire:loading.attr="disabled"
                wire:target="refreshServerInventoryDetails"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                    {{ __('Refresh scan') }}
                </span>
                <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                    {{ __('Scanning…') }}
                </span>
            </button>
        @endif
    </div>

    <div class="space-y-6 px-6 py-5 sm:px-7">
        @if (! $isDeployer)
            <form wire:submit="saveInventoryDepthPreference" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="min-w-[min(100%,20rem)] flex-1">
                    <label for="patch-inventory-depth" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Scan depth') }}</label>
                    <select
                        id="patch-inventory-depth"
                        wire:model="settingsInventoryDepth"
                        class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    >
                        @foreach ($inventoryDepths as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('settingsInventoryDepth')
                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                <x-primary-button type="submit" class="!py-2.5" wire:loading.attr="disabled">{{ __('Save depth') }}</x-primary-button>
            </form>
        @endif

        @if (! empty($extSections))
            <div>
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Host snapshot') }}</h3>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Disk, memory, uptime, and fail2ban from the extended inventory probe.') }}</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ($extSections as $section)
                        <div class="rounded-xl border border-brand-ink/10 bg-white p-3 shadow-sm">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $section['label'] }}</div>
                            <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all font-mono text-[11px] leading-relaxed text-brand-ink">{{ $section['body'] }}</pre>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Automatic') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Unattended-upgrades') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Server-side automatic security updates (Debian/Ubuntu).') }}</p>
        </div>
        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $statusPill['classes'] }}">
            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusPill['dot'] }}"></span>
            {{ $statusPill['label'] }}
        </span>
    </div>

    <div class="space-y-5 px-6 py-5 sm:px-7">
        <div @class([
            'flex items-start gap-4 rounded-2xl border p-4',
            'border-brand-sage/30 bg-brand-sage/5' => $unattendedEnabled === true,
            'border-amber-200/70 bg-amber-50/30' => $unattendedEnabled === false,
            'border-brand-ink/10 bg-brand-sand/15' => $unattendedEnabled !== true && $unattendedEnabled !== false,
        ])>
            <span @class([
                'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1',
                'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => $unattendedEnabled === true,
                'bg-amber-50 text-amber-900 ring-amber-200' => $unattendedEnabled === false,
                'bg-white text-brand-moss ring-brand-ink/10' => $unattendedEnabled !== true && $unattendedEnabled !== false,
            ])>
                @if ($unattendedEnabled === true)
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                @elseif ($unattendedEnabled === false)
                    <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                @else
                    <x-heroicon-o-question-mark-circle class="h-5 w-5" aria-hidden="true" />
                @endif
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-brand-ink">{{ $statusPill['label'] }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $statusSummary }}</p>
            </div>
        </div>

        @if (! empty($report['unattended']['snippet']))
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('/etc/apt/apt.conf.d/20auto-upgrades') }}</p>
                <pre class="mt-2 max-h-32 overflow-auto rounded-xl border border-brand-ink/10 bg-brand-ink/[0.03] p-3 font-mono text-[11px] leading-relaxed text-brand-ink">{{ $report['unattended']['snippet'] }}</pre>
            </div>
        @endif

        @if ($opsReady && ! $isDeployer)
            @if ($showEnableAction && ! empty($serviceActions['unattended_upgrades_enable']))
                @php $enableAction = $serviceActions['unattended_upgrades_enable']; @endphp
                <button
                    type="button"
                    wire:click="openConfirmActionModal('runAllowlistedManageAction', ['unattended_upgrades_enable'], @js($enableAction['label']), @js($enableAction['confirm']), @js($enableAction['label']), false)"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-brand-forest/20 bg-brand-forest px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-ink sm:w-auto"
                >
                    <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                    {{ $enableAction['label'] }}
                </button>
            @elseif ($showDisableAction && ! empty($serviceActions['unattended_upgrades_disable']))
                @php $disableAction = $serviceActions['unattended_upgrades_disable']; @endphp
                <button
                    type="button"
                    wire:click="openConfirmActionModal('runAllowlistedManageAction', ['unattended_upgrades_disable'], @js($disableAction['label']), @js($disableAction['confirm']), @js($disableAction['label']), false)"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-amber-300 bg-white px-4 py-2.5 text-sm font-semibold text-amber-950 shadow-sm transition hover:bg-amber-50 sm:w-auto"
                >
                    <x-heroicon-o-no-symbol class="h-4 w-4" aria-hidden="true" />
                    {{ $disableAction['label'] }}
                </button>
            @elseif (! $unattendedPresent && ! empty($serviceActions['unattended_upgrades_install']))
                @php $installAction = $serviceActions['unattended_upgrades_install']; @endphp
                <div class="space-y-2">
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedManageAction', ['unattended_upgrades_install'], @js($installAction['label']), @js($installAction['confirm']), @js($installAction['label']), false)"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-brand-forest/20 bg-brand-forest px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-ink sm:w-auto"
                    >
                        <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                        {{ $installAction['label'] }}
                    </button>
                    <p class="text-xs leading-relaxed text-brand-moss">{{ __('Installs the package, enables daily security updates, and rescans automatically.') }}</p>
                </div>
            @endif
        @endif

        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:p-5">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-brand-moss ring-1 ring-brand-ink/10">
                    <x-heroicon-o-calendar-days class="h-4 w-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Cadence preference') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-mist">{{ __('Recorded in Dply for future scheduling — does not change unattended-upgrades on the server today.') }}</p>
                </div>
            </div>

            <form wire:submit="saveManageMetadata" class="mt-4 space-y-4">
                <div>
                    <label for="patch_auto_updates_interval" class="sr-only">{{ __('Cadence preference') }}</label>
                    <select
                        id="patch_auto_updates_interval"
                        wire:model="manage_auto_updates_interval"
                        @disabled($isDeployer)
                        class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                    >
                        @foreach ($autoUpdateIntervals as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('manage_auto_updates_interval')
                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
                <x-primary-button type="submit" class="!py-2.5" :disabled="$isDeployer">{{ __('Save preference') }}</x-primary-button>
            </form>
        </div>
    </div>
</section>
