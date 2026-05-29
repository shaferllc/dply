@if ($heroTool)
    <div class="{{ $card }} flex flex-col p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                    <x-dynamic-component :component="$heroTool['icon']" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ $heroTool['label'] }}</h3>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Install, default, and uninstall language runtimes for the deploy user.') }}</p>
                </div>
            </div>
            <span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 {{ $misePresent ? $tonePalette['sky'] : $tonePalette['mist'] }}">
                <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusBadgeDot($misePresent ? 'sky' : 'mist') }}"></span>
                {{ $misePresent ? __('Installed') : __('Not detected') }}
            </span>
        </div>

        <dl class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-xs">
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                <dd class="mt-0.5 font-mono text-brand-ink">{{ $miseVersion ?? '—' }}</dd>
            </div>
            @if ($heroTool['docs_url'])
                <div class="flex items-end">
                    <a href="{{ $heroTool['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-brand-moss hover:text-brand-ink">
                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                        {{ __('Docs') }}
                    </a>
                </div>
            @endif
        </dl>

        @if ($miseAction && $heroTool['action_key'] && $opsReady && ! $isDeployer)
            @php
                $heroInstallBusy = $toolActionIsActive($heroTool['action_key']);
                $heroPruneBusy = $toolActionIsActive('mise_prune');
                $heroReshimBusy = $toolActionIsActive('mise_reshim');
            @endphp
            <div class="mt-4 flex flex-wrap gap-2 border-t border-brand-ink/10 pt-4">
                <button
                    type="button"
                    wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $heroTool['action_key'] }}'], @js($miseAction['label']), @js($miseAction['confirm']), @js($miseAction['label']), false)"
                    wire:loading.attr="disabled"
                    wire:target="confirmActionModal"
                    @disabled($heroInstallBusy)
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                >
                    @if ($heroInstallBusy)
                        <x-spinner variant="forest" size="sm" />
                        {{ $activeToolActionOps[$heroTool['action_key']]['message'] ?? __('Installing…') }}
                    @else
                        {{ $miseAction['label'] }}
                    @endif
                </button>
                @if ($misePresent && $misePruneAction)
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedAction', ['mise_prune'], @js($misePruneAction['label']), @js($misePruneAction['confirm']), @js($misePruneAction['label']), false)"
                        wire:loading.attr="disabled"
                        wire:target="confirmActionModal"
                        @disabled($heroPruneBusy)
                        class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                    >
                        @if ($heroPruneBusy)
                            <x-spinner variant="forest" size="sm" />
                            {{ $activeToolActionOps['mise_prune']['message'] ?? __('Running…') }}
                        @else
                            {{ $misePruneAction['label'] }}
                        @endif
                    </button>
                @endif
                @if ($misePresent && $miseReshimAction)
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedAction', ['mise_reshim'], @js($miseReshimAction['label']), @js($miseReshimAction['confirm']), @js($miseReshimAction['label']), false)"
                        wire:loading.attr="disabled"
                        wire:target="confirmActionModal"
                        @disabled($heroReshimBusy)
                        class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                    >
                        @if ($heroReshimBusy)
                            <x-spinner variant="forest" size="sm" />
                            {{ $activeToolActionOps['mise_reshim']['message'] ?? __('Running…') }}
                        @else
                            {{ $miseReshimAction['label'] }}
                        @endif
                    </button>
                @endif
            </div>
        @endif

        @if ($misePresent)
            <div class="mt-5 border-t border-brand-ink/10 pt-4">
                <h4 class="text-sm font-semibold text-brand-ink">{{ __('Managed runtimes') }}</h4>

                @if (! $miseRuntimesProbed)
                    <p class="mt-3 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-xs text-brand-moss">
                        {{ __('No runtime probe data yet — use Refresh probe in the header.') }}
                    </p>
                @else
                    <div class="mt-3 space-y-2">
                        @foreach ($runtimeCatalog as $runtime => $catalog)
                            @php
                                $entry = is_array($manageMiseRuntimes[$runtime] ?? null) ? $manageMiseRuntimes[$runtime] : ['versions' => [], 'active' => null];
                                $versions = is_array($entry['versions'] ?? null) ? $entry['versions'] : [];
                                $active = is_string($entry['active'] ?? null) && $entry['active'] !== '' ? $entry['active'] : null;
                                $hasVersions = ! empty($versions);

                                $systemEntry = is_array($manageSystemRuntimes[$runtime] ?? null) ? $manageSystemRuntimes[$runtime] : ['present' => false, 'version' => null];
                                $systemPresent = ! empty($systemEntry['present']);
                                $systemVersion = is_string($systemEntry['version'] ?? null) && $systemEntry['version'] !== '' ? $systemEntry['version'] : null;

                                $miseOp = $activeMiseRuntimeOps[$runtime] ?? null;
                                $isMiseBusy = $miseOp !== null;
                                $openByDefault = in_array($runtime, ['node', 'python'], true);
                            @endphp
                            <details
                                @class([
                                    'group rounded-xl border transition-colors',
                                    'border-brand-sage/30 bg-brand-sage/5' => $isMiseBusy,
                                    'border-brand-ink/10 bg-white' => ! $isMiseBusy,
                                ])
                                @if ($openByDefault) open @endif
                                wire:key="mise-runtime-row-{{ $runtime }}"
                            >
                                <summary class="cursor-pointer list-none px-4 py-3 marker:content-none [&::-webkit-details-marker]:hidden">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition group-open:rotate-90" aria-hidden="true" />
                                            <span class="text-sm font-semibold text-brand-ink">{{ $catalog['label'] }}</span>
                                            @if ($active)
                                                <span class="font-mono text-[11px] text-brand-moss">{{ $active }}</span>
                                            @elseif ($hasVersions)
                                                <span class="text-[11px] text-brand-mist">{{ trans_choice(':count version|:count versions', count($versions), ['count' => count($versions)]) }}</span>
                                            @endif
                                        </div>
                                        @if ($isMiseBusy)
                                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-forest">
                                                <x-spinner variant="forest" size="sm" />
                                                {{ $miseOp['message'] }}
                                            </span>
                                        @endif
                                    </div>
                                </summary>

                                <div class="space-y-3 border-t border-brand-ink/5 px-4 pb-4 pt-2">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-mist">
                                        @if ($active)
                                            <span>{{ __('mise default: ') }}<span class="font-mono text-brand-ink">{{ $active }}</span></span>
                                        @elseif ($hasVersions)
                                            <span>{{ __('mise: no global default set.') }}</span>
                                        @else
                                            <span>{{ __('mise: not installed.') }}</span>
                                        @endif
                                        @if ($systemPresent)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-medium text-brand-ink">
                                                {{ __('system') }}: <span class="font-mono">{{ $systemVersion ?? '—' }}</span>
                                            </span>
                                        @endif
                                    </div>

                                    @if ($opsReady && ! $isDeployer && ! $isMiseBusy)
                                        @php
                                            $availableVersions = $mise_available_versions[$runtime] ?? null;
                                        @endphp
                                        @if ($availableVersions === null)
                                            <button
                                                type="button"
                                                wire:click="loadMiseAvailableVersions('{{ $runtime }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="loadMiseAvailableVersions('{{ $runtime }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                            >
                                                <span wire:loading.remove wire:target="loadMiseAvailableVersions('{{ $runtime }}')" class="inline-flex items-center gap-1.5">
                                                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Load versions') }}
                                                </span>
                                                <span wire:loading wire:target="loadMiseAvailableVersions('{{ $runtime }}')" class="inline-flex items-center gap-1.5">
                                                    <x-spinner variant="forest" size="sm" />
                                                    {{ __('Fetching…') }}
                                                </span>
                                            </button>
                                        @elseif ($availableVersions === [])
                                            <button
                                                type="button"
                                                wire:click="loadMiseAvailableVersions('{{ $runtime }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-100"
                                            >
                                                {{ __('Retry fetch') }}
                                            </button>
                                        @else
                                            <form
                                                x-data="{ v: '' }"
                                                x-on:submit.prevent="if (v !== '') { $wire.miseInstallRuntime(@js($runtime), v); v = ''; }"
                                                class="flex flex-wrap items-end gap-2"
                                            >
                                                <label class="sr-only" for="mise-install-{{ $runtime }}">{{ __('Install and activate :runtime version', ['runtime' => $catalog['label']]) }}</label>
                                                <select
                                                    id="mise-install-{{ $runtime }}"
                                                    x-model="v"
                                                    class="min-w-[11rem] rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-3 pr-8 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                                                    title="{{ $catalog['hint'] }}"
                                                >
                                                    <option value="">{{ __('Select version') }}</option>
                                                    @foreach ($availableVersions as $v)
                                                        <option value="{{ $v }}">{{ $v }}</option>
                                                    @endforeach
                                                </select>
                                                <button
                                                    type="submit"
                                                    x-bind:disabled="v === ''"
                                                    wire:loading.attr="disabled"
                                                    wire:target="miseInstallRuntime"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                                >
                                                    <span wire:loading.remove wire:target="miseInstallRuntime">{{ __('Install & activate') }}</span>
                                                    <span wire:loading wire:target="miseInstallRuntime" class="inline-flex items-center gap-1.5">
                                                        <x-spinner variant="forest" size="sm" />
                                                        {{ __('Queuing…') }}
                                                    </span>
                                                </button>
                                                <button type="button" wire:click="loadMiseAvailableVersions('{{ $runtime }}')" class="text-[11px] text-brand-mist hover:text-brand-ink">
                                                    <x-heroicon-o-arrow-path class="h-3 w-3" aria-hidden="true" />
                                                </button>
                                            </form>
                                        @endif
                                    @endif

                                    @if ($hasVersions)
                                        <div @class(['flex flex-wrap gap-2', 'opacity-60' => $isMiseBusy])>
                                            @foreach ($versions as $v)
                                                @php
                                                    $isActive = $active !== null && $active === $v;
                                                    $confirmRemove = __('Uninstall :runtime :version? The deploy user\'s mise data directory drops the install; sites already pinned to this version will fall back to the runtime default.', [
                                                        'runtime' => $catalog['label'],
                                                        'version' => $v,
                                                    ]);
                                                    $confirmDefault = __('Set :runtime :version as the deploy user\'s global default? New sites without a pinned version will use this one.', [
                                                        'runtime' => $catalog['label'],
                                                        'version' => $v,
                                                    ]);
                                                @endphp
                                                <span
                                                    @class([
                                                        'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                                        'border-brand-forest/20 bg-brand-sage/15 text-brand-forest' => $isActive,
                                                        'border-brand-ink/15 bg-white text-brand-ink' => ! $isActive,
                                                    ])
                                                >
                                                    <span class="font-mono">{{ $v }}</span>
                                                    @if ($isActive)
                                                        <span class="text-[10px] uppercase tracking-wide opacity-80">{{ __('default') }}</span>
                                                    @elseif ($opsReady && ! $isDeployer && ! $isMiseBusy)
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('miseSetRuntimeDefault', ['{{ $runtime }}', @js($v)], @js(__('Set :v as default', ['v' => $v])), @js($confirmDefault), @js(__('Set :runtime default to :v', ['runtime' => $catalog['label'], 'v' => $v])), false)"
                                                            class="text-[10px] font-semibold uppercase tracking-wide text-brand-ink/70 hover:text-brand-ink"
                                                        >
                                                            {{ __('set default') }}
                                                        </button>
                                                    @endif
                                                    @if (! $isActive && $opsReady && ! $isDeployer && ! $isMiseBusy)
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('miseUninstallRuntime', ['{{ $runtime }}', @js($v)], @js(__('Uninstall :v', ['v' => $v])), @js($confirmRemove), @js(__('Uninstall :runtime :v', ['runtime' => $catalog['label'], 'v' => $v])), true)"
                                                            class="text-brand-ink/50 hover:text-rose-700"
                                                        >
                                                            <x-heroicon-o-x-mark class="h-3 w-3" aria-hidden="true" />
                                                        </button>
                                                    @endif
                                                </span>
                                            @endforeach
                                        </div>

                                        @if ($opsReady && ! $isDeployer && ! $isMiseBusy)
                                            <form x-data="{ uv: '' }" class="flex flex-wrap items-end gap-2 border-t border-brand-ink/5 pt-3">
                                                <label class="sr-only" for="mise-uninstall-{{ $runtime }}">{{ __('Uninstall :runtime version', ['runtime' => $catalog['label']]) }}</label>
                                                <select
                                                    id="mise-uninstall-{{ $runtime }}"
                                                    x-model="uv"
                                                    class="min-w-[11rem] rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-3 pr-8 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                                                >
                                                    <option value="">{{ __('Uninstall version') }}</option>
                                                    @foreach ($versions as $v)
                                                        <option value="{{ $v }}" @disabled($active !== null && $active === $v)>{{ $v }}@if ($active !== null && $active === $v) ({{ __('default') }})@endif</option>
                                                    @endforeach
                                                </select>
                                                <button
                                                    type="button"
                                                    x-bind:disabled="uv === '' || uv === @js($active)"
                                                    x-on:click="if (uv !== '' && uv !== @js($active)) { $wire.promptMiseUninstallRuntime(@js($runtime), uv); }"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-medium text-rose-800 hover:bg-rose-100 disabled:opacity-50"
                                                >
                                                    <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Uninstall') }}
                                                </button>
                                                @if ($active !== null)
                                                    <p class="w-full text-[11px] text-brand-mist">{{ __('The global default cannot be uninstalled until you set a different default.') }}</p>
                                                @endif
                                            </form>
                                        @endif
                                    @elseif ($isMiseBusy)
                                        <p class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
                                            <x-spinner variant="forest" size="sm" />
                                            {{ $miseOp['message'] }}
                                        </p>
                                    @else
                                        <p class="text-xs text-brand-mist">{{ __('No versions installed yet.') }}</p>
                                    @endif
                                </div>
                            </details>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <p class="mt-4 text-xs text-brand-moss">{{ __('Install mise from the Tools tab before managing runtimes.') }}</p>
        @endif
    </div>
@endif
