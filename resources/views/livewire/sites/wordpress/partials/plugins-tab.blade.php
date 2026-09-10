<div>
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Plugins') }}</h3>
            <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Live list pulled from `wp plugin list`. Each row is cross-checked against Wordfence Intelligence for known CVEs.') }}</p>
        </div>
        <div class="ml-auto flex shrink-0 flex-wrap items-center gap-2">
            {{-- Shortcut to the Git tab, where plugins from their own repos live. --}}
            @if ($gitSourcesSupported)
            <button type="button" wire:click="$set('tab', 'git')"
                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                <x-heroicon-o-code-bracket class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('From Git') }}
            </button>
            @endif
            @if ($pluginsLoaded && $canMutate && collect($plugins)->where('update', 'available')->isNotEmpty())
                <button
                    type="button"
                    wire:click="updateAllPlugins"
                    wire:loading.attr="disabled"
                    wire:target="updateAllPlugins"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-up-circle class="h-4 w-4" aria-hidden="true" />
                    {{ __('Update all') }}
                </button>
            @endif
            @if ($pluginsLoaded)
                <button type="button" wire:click="loadPlugins" wire:loading.attr="disabled" wire:target="loadPlugins" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                    <span wire:loading.remove wire:target="loadPlugins" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                        {{ __('Refresh') }}
                    </span>
                    <span wire:loading wire:target="loadPlugins" class="inline-flex items-center gap-1.5">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Refreshing…') }}
                    </span>
                </button>
            @endif
        </div>
    </div>

    @if ($canMutate)
        {{-- 1. Search WordPress.org. Suggestions come from a globally cached query
             on the control plane — typing costs nothing on the customer's server.
             Enter opens the details for whatever slug is typed. --}}
        <div class="relative border-b border-brand-ink/10 bg-white px-3 py-3 sm:px-4" x-data="{ open: true }" @click.outside="open = false">
            <x-input-label for="wp_plugin_search" :value="__('Add a plugin')" class="text-xs" />
            <div class="relative mt-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                <x-text-input
                    id="wp_plugin_search"
                    type="search"
                    autocomplete="off"
                    wire:model.live.debounce.300ms="pluginSearch"
                    @focus="open = true"
                    @keydown.enter.prevent="$wire.pluginSearch.trim() && $wire.showPluginDetail($wire.pluginSearch.trim())"
                    @keydown.escape="open = false"
                    class="block w-full pl-9 text-sm"
                    placeholder="{{ __('Search WordPress.org — try seo, forms, cache…') }}"
                />
                <span wire:loading wire:target="pluginSearch" class="absolute right-3 top-1/2 -translate-y-1/2"><x-spinner size="sm" /></span>
            </div>

            @if ($pluginSuggestions !== [])
                <ul x-show="open" role="listbox" class="absolute left-3 right-3 z-20 mt-1 max-h-80 overflow-auto rounded-lg border border-brand-ink/10 bg-white shadow-lg sm:left-4 sm:right-4">
                    @foreach ($pluginSuggestions as $suggestion)
                        <li wire:key="wp-sugg-{{ $suggestion['slug'] }}" role="option">
                            <button type="button" wire:click="showPluginDetail(@js($suggestion['slug']))" class="flex w-full items-start gap-3 px-3 py-2 text-left hover:bg-brand-sand/40">
                                @if ($suggestion['icon'] !== '')
                                    <img src="{{ $suggestion['icon'] }}" alt="" class="h-8 w-8 shrink-0 rounded" loading="lazy" />
                                @else
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-brand-sand/60"><x-heroicon-o-puzzle-piece class="h-4 w-4 text-brand-moss" aria-hidden="true" /></span>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $suggestion['name'] }}</span>
                                        @if ($suggestion['installed'])
                                            <span class="shrink-0 rounded-full bg-emerald-50 px-1.5 text-2xs font-semibold text-emerald-800 ring-1 ring-emerald-200">{{ __('installed') }}</span>
                                        @endif
                                    </span>
                                    <span class="block truncate text-xs text-brand-moss">{{ $suggestion['description'] }}</span>
                                    <span class="mt-0.5 block text-2xs text-brand-mist">
                                        {{ number_format($suggestion['active_installs']) }}+ {{ __('installs') }} · ★ {{ number_format($suggestion['rating'] / 20, 1) }} · <span class="font-mono">{{ $suggestion['slug'] }}</span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (mb_strlen(trim($pluginSearch)) >= 2)
                <p wire:loading.remove wire:target="pluginSearch" class="mt-2 text-xs text-brand-moss">{{ __('No matches on WordPress.org. Press Enter to look up the exact slug.') }}</p>
            @endif
        </div>

        {{-- 2 + 3 + 4. What you are about to install, whether it fits this site, and
             which version. A pinned version on an installed plugin is a rollback. --}}
        @if ($pluginDetail)
            @php
                $detail = $pluginDetail;
                $compat = $detail['compatibility'] ?? ['blockers' => [], 'warnings' => []];
            @endphp
            <div class="border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-3 sm:px-4" wire:key="wp-detail-{{ $detail['slug'] }}">
                <div class="flex items-start gap-3">
                    @if ($detail['icon'] !== '')
                        <img src="{{ $detail['icon'] }}" alt="" class="h-12 w-12 shrink-0 rounded-lg ring-1 ring-brand-ink/10" />
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-brand-ink">{{ $detail['name'] }}</p>
                            <span class="font-mono text-2xs text-brand-mist">{{ $detail['slug'] }}</span>
                            @if ($detail['installed'])
                                <span class="rounded-full bg-emerald-50 px-1.5 text-2xs font-semibold text-emerald-800 ring-1 ring-emerald-200">{{ __('installed') }}</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ $detail['description'] }}</p>

                        <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-2xs text-brand-moss">
                            <div><dt class="inline font-semibold">{{ __('By') }}</dt> <dd class="inline">{{ $detail['author'] ?: '—' }}</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Latest') }}</dt> <dd class="inline font-mono">{{ $detail['version'] ?: '—' }}</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Installs') }}</dt> <dd class="inline">{{ number_format($detail['active_installs']) }}+</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Rating') }}</dt> <dd class="inline">★ {{ number_format($detail['rating'] / 20, 1) }} ({{ number_format($detail['num_ratings']) }})</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Updated') }}</dt> <dd class="inline">{{ $detail['last_updated'] ?: '—' }}</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Requires') }}</dt> <dd class="inline">WP {{ $detail['requires'] ?: '?' }} · PHP {{ $detail['requires_php'] ?: '?' }}</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Tested to') }}</dt> <dd class="inline">{{ $detail['tested'] ?: '?' }}</dd></div>
                        </dl>

                        @foreach ($compat['blockers'] as $blocker)
                            <p class="mt-2 rounded-md bg-rose-50 px-2 py-1 text-xs text-rose-800 ring-1 ring-rose-200">{{ $blocker }}</p>
                        @endforeach
                        @foreach ($compat['warnings'] as $warning)
                            <p class="mt-2 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-900 ring-1 ring-amber-200">{{ $warning }}</p>
                        @endforeach
                        @if ($compat['blockers'] === [] && $compat['warnings'] === [])
                            <p class="mt-2 text-xs text-emerald-700">✓ {{ __('Compatible with this site.') }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="closePluginDetail" class="text-brand-mist hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if (! empty($detail['versions']))
                        <select wire:model="pluginDetailVersion" aria-label="{{ __('Version') }}" class="rounded-md border-brand-ink/15 py-1 text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                            <option value="">{{ __('Latest (:v)', ['v' => $detail['version']]) }}</option>
                            @foreach ($detail['versions'] as $v)
                                @if ($v !== $detail['version'])
                                    <option value="{{ $v }}">{{ $v }}</option>
                                @endif
                            @endforeach
                        </select>
                    @endif
                    <x-spinner-button size="xs" variant="primary" type="button" target="installFromDirectory" wire:click="installFromDirectory" :disabled="$compat['blockers'] !== []">
                        {{ $detail['installed'] ? __('Reinstall / switch version') : __('Install & activate') }}
                    </x-spinner-button>
                    <a href="https://wordpress.org/plugins/{{ $detail['slug'] }}/" target="_blank" rel="noopener" class="text-xs text-brand-moss underline hover:text-brand-ink">{{ __('View on WordPress.org') }}</a>
                </div>
            </div>
        @endif

        {{-- Recommendations from WordPress.org, minus what is already installed. --}}
        <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4" wire:init="loadPluginRecommendations">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Recommended from WordPress.org') }}</p>
                <div class="inline-flex rounded-md border border-brand-ink/10 p-0.5">
                    @foreach (['popular' => __('Popular'), 'featured' => __('Featured')] as $listKey => $listLabel)
                        <button type="button" wire:click="setPluginRecommendationList('{{ $listKey }}')" @class([
                            'rounded px-2 py-0.5 text-2xs font-semibold',
                            'bg-brand-ink text-brand-cream' => $pluginRecommendationList === $listKey,
                            'text-brand-moss hover:bg-brand-sand/40' => $pluginRecommendationList !== $listKey,
                        ])>{{ $listLabel }}</button>
                    @endforeach
                </div>
            </div>

            @if (! $pluginRecommendationsLoaded)
                <p class="mt-2 flex items-center gap-2 text-xs text-brand-moss"><x-spinner size="sm" /> {{ __('Loading suggestions…') }}</p>
            @elseif ($pluginRecommendations === [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('No suggestions right now — WordPress.org may be unreachable.') }}</p>
            @else
                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($pluginRecommendations as $pick)
                        {{-- Re-checked here: recommendations can load before the
                             installed list does. --}}
                        @continue(collect($plugins)->contains('name', $pick['slug']))
                        <button type="button" wire:key="wp-rec-{{ $pick['slug'] }}" wire:click="showPluginDetail(@js($pick['slug']))" class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-white p-2 text-left transition hover:border-brand-ink/20 hover:bg-brand-sand/30">
                            @if ($pick['icon'] !== '')
                                <img src="{{ $pick['icon'] }}" alt="" class="h-8 w-8 shrink-0 rounded" loading="lazy" />
                            @else
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-brand-sand/60"><x-heroicon-o-puzzle-piece class="h-4 w-4 text-brand-moss" aria-hidden="true" /></span>
                            @endif
                            <span class="min-w-0">
                                <span class="block truncate text-xs font-semibold text-brand-ink">{{ $pick['name'] }}</span>
                                <span class="block text-2xs text-brand-mist">{{ number_format($pick['active_installs']) }}+ {{ __('installs') }} · ★ {{ number_format($pick['rating'] / 20, 1) }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if (! $pluginsLoaded)
        <div wire:init="loadPlugins" class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Loading plugins…') }}
        </div>
    @elseif (empty($plugins))
        <p class="px-6 py-8 text-sm text-brand-moss">{{ __('No plugins installed.') }}</p>
    @else
        {{-- 5. Bulk actions: one wp-cli call across every ticked plugin. Delete
             is not offered here — it is irreversible and keeps its per-row
             confirmation. --}}
        @if ($canMutate && $selectedPlugins !== [])
            <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/30 px-3 py-2 sm:px-4">
                <span class="text-xs font-semibold text-brand-ink">{{ trans_choice('{1} 1 selected|[2,*] :count selected', count($selectedPlugins), ['count' => count($selectedPlugins)]) }}</span>
                @foreach ([
                    'activate' => __('Activate'),
                    'deactivate' => __('Deactivate'),
                    'update' => __('Update'),
                    'auto-on' => __('Auto-updates on'),
                    'auto-off' => __('Auto-updates off'),
                ] as $bulkKey => $bulkLabel)
                    <x-spinner-button size="xs" variant="secondary" type="button" target="bulkPluginAction" wire:click="bulkPluginAction('{{ $bulkKey }}')">{{ $bulkLabel }}</x-spinner-button>
                @endforeach
                <button type="button" wire:click="$set('selectedPlugins', [])" class="ml-auto text-xs text-brand-moss underline hover:text-brand-ink">{{ __('Clear') }}</button>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        @if ($canMutate)
                            <th class="w-8 py-3 pl-4 sm:pl-6">
                                <input type="checkbox" wire:click="toggleSelectAllPlugins" @checked(count($selectedPlugins) > 0 && count($selectedPlugins) === count($plugins)) aria-label="{{ __('Select all plugins') }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                            </th>
                        @endif
                        <th class="px-4 py-3 sm:px-6">{{ __('Plugin') }}</th>
                        <th class="px-4 py-3">{{ __('Version') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Health') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($plugins as $plugin)
                        @php $active = $plugin['status'] === 'active'; @endphp
                        <tr wire:key="wp-plugin-{{ $plugin['name'] }}">
                            @if ($canMutate)
                                <td class="w-8 py-3 pl-4 sm:pl-6">
                                    <input type="checkbox" wire:model.live="selectedPlugins" value="{{ $plugin['name'] }}" aria-label="{{ __('Select :name', ['name' => $plugin['name']]) }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                </td>
                            @endif
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">{{ $plugin['name'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">v{{ $plugin['version'] }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                    'bg-brand-sage/15 text-brand-forest' => $active,
                                    'bg-brand-sand/40 text-brand-moss' => ! $active,
                                ])>{{ $plugin['status'] }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                                    @if ($plugin['update'] === 'available')
                                        <span class="rounded-full bg-brand-gold/20 px-2 py-0.5 font-semibold text-brand-ink">{{ __('Update available') }}</span>
                                    @endif
                                    @foreach ($plugin['advisories'] as $advisory)
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-700"
                                            title="{{ $advisory['title'] }}{{ $advisory['cve'] ? ' ('.$advisory['cve'].')' : '' }}{{ $advisory['patched'] ? ' — patched in '.$advisory['patched'] : '' }}"
                                        >
                                            <x-heroicon-m-shield-exclamation class="h-3 w-3" aria-hidden="true" />
                                            {{ strtoupper($advisory['severity']) }}
                                        </span>
                                    @endforeach
                                    @if ($plugin['update'] !== 'available' && empty($plugin['advisories']))
                                        <span class="text-brand-mist">—</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($canMutate)
                                    <div class="inline-flex flex-wrap justify-end gap-1.5" wire:loading.class="opacity-50">
                                        @if ($plugin['update'] === 'available')
                                            <button type="button" wire:click="updatePlugin(@js($plugin['name']))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Update') }}</button>
                                        @endif
                                        @if ($active)
                                            <button type="button" wire:click="deactivatePlugin(@js($plugin['name']))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Deactivate') }}</button>
                                        @else
                                            <button type="button" wire:click="activatePlugin(@js($plugin['name']))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Activate') }}</button>
                                        @endif
                                        @php $autoOn = ($plugin['auto_update'] ?? 'off') === 'on'; @endphp
                                        {{-- Auto-updates: the difference between a
                                             CVE patched overnight and one that waits
                                             for someone to notice. --}}
                                        <button
                                            type="button"
                                            wire:click="togglePluginAutoUpdate(@js($plugin['name']), {{ $autoOn ? 'false' : 'true' }})"
                                            title="{{ $autoOn ? __('Auto-updates on — click to disable') : __('Auto-updates off — click to enable') }}"
                                            @class([
                                                'rounded-md border px-2 py-1 text-xs font-medium',
                                                'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' => $autoOn,
                                                'border-brand-ink/15 text-brand-moss hover:bg-brand-sand/40' => ! $autoOn,
                                            ])
                                        >{{ $autoOn ? __('Auto ✓') : __('Auto') }}</button>
                                        @if ($canDestroy)
                                            <button type="button" wire:click="confirmDeletePlugin(@js($plugin['name']))" class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100">{{ __('Delete') }}</button>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-xs text-brand-mist">{{ __('Read-only') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="px-6 py-3 text-xs text-brand-mist">{{ __('Vulnerability data: Wordfence Intelligence (free tier, 24h cache). Updates and activation changes queue and apply in the background — refresh to see the new state.') }}</p>
    @endif

    <x-input-error :messages="$errors->get('plugins')" class="px-6 pb-4" />
</div>
