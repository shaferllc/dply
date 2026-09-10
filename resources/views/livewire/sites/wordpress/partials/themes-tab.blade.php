{{--
    Themes: the installed list plus the WordPress.org directory — search,
    recommendations, details with a compatibility check, version pinning, bulk
    actions and child themes. Directory reads run on the control plane (cached
    globally); only installs and changes run wp-cli on the site.
--}}
<div>
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Themes') }}</h3>
            <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Live list pulled from `wp theme list`. Find themes on WordPress.org, pin versions and create child themes.') }}</p>
        </div>
        {{-- Shortcut to the Git tab, where themes from their own repos live. --}}
        @if ($gitSourcesSupported)
        <button type="button" wire:click="$set('tab', 'git')"
            class="ml-auto inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
            <x-heroicon-o-code-bracket class="h-3.5 w-3.5" aria-hidden="true" />
            {{ __('From Git') }}
        </button>
        @endif
        @if ($themesLoaded)
            <button type="button" wire:click="loadThemes" wire:loading.attr="disabled" wire:target="loadThemes" class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                <span wire:loading.remove wire:target="loadThemes" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                    {{ __('Refresh') }}
                </span>
                <span wire:loading wire:target="loadThemes" class="inline-flex items-center gap-1.5">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Refreshing…') }}
                </span>
            </button>
        @endif
    </div>

    {{-- Up top, not under the list: most errors come from the search and the
         detail card, and a long theme list would push them out of sight. --}}
    @error('themes')
        <p class="border-b border-rose-200/70 bg-rose-50/60 px-3 py-2 text-xs text-rose-700 sm:px-4">{{ $message }}</p>
    @enderror

    @if ($canMutate)
        {{-- 1. Search WordPress.org. Enter opens the details for the exact slug typed. --}}
        <div class="relative border-b border-brand-ink/10 bg-white px-3 py-3 sm:px-4" x-data="{ open: true }" @click.outside="open = false">
            <x-input-label for="wp_theme_search" :value="__('Add a theme')" class="text-xs" />
            <div class="relative mt-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                <x-text-input
                    id="wp_theme_search"
                    type="search"
                    autocomplete="off"
                    wire:model.live.debounce.300ms="themeSearch"
                    @focus="open = true"
                    @keydown.enter.prevent="$wire.themeSearch.trim() && $wire.showThemeDetail($wire.themeSearch.trim())"
                    @keydown.escape="open = false"
                    class="block w-full pl-9 text-sm"
                    placeholder="{{ __('Search WordPress.org — try blog, portfolio, shop…') }}"
                />
                <span wire:loading wire:target="themeSearch" class="absolute right-3 top-1/2 -translate-y-1/2"><x-spinner size="sm" /></span>
            </div>

            @if ($themeSuggestions !== [])
                <ul x-show="open" role="listbox" class="absolute left-3 right-3 z-20 mt-1 max-h-80 overflow-auto rounded-lg border border-brand-ink/10 bg-white shadow-lg sm:left-4 sm:right-4">
                    @foreach ($themeSuggestions as $suggestion)
                        <li wire:key="wp-theme-sugg-{{ $suggestion['slug'] }}" role="option">
                            <button type="button" wire:click="showThemeDetail(@js($suggestion['slug']))" class="flex w-full items-start gap-3 px-3 py-2 text-left hover:bg-brand-sand/40">
                                @if ($suggestion['screenshot'] !== '')
                                    <img src="{{ $suggestion['screenshot'] }}" alt="" class="h-9 w-12 shrink-0 rounded object-cover object-top ring-1 ring-brand-ink/10" loading="lazy" />
                                @else
                                    <span class="flex h-9 w-12 shrink-0 items-center justify-center rounded bg-brand-sand/60"><x-heroicon-o-paint-brush class="h-4 w-4 text-brand-moss" aria-hidden="true" /></span>
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
            @elseif (mb_strlen(trim($themeSearch)) >= 2)
                <p wire:loading.remove wire:target="themeSearch" class="mt-2 text-xs text-brand-moss">{{ __('No matches on WordPress.org. Press Enter to look up the exact slug.') }}</p>
            @endif
        </div>

        {{-- 3 + 4. What it looks like, whether it fits this site, which version,
             and whether to switch the live site to it. --}}
        @if ($themeDetail)
            @php
                $detail = $themeDetail;
                $compat = $detail['compatibility'] ?? ['blockers' => [], 'warnings' => []];
            @endphp
            <div class="border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-3 sm:px-4" wire:key="wp-theme-detail-{{ $detail['slug'] }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    @if ($detail['screenshot'] !== '')
                        <img src="{{ $detail['screenshot'] }}" alt="{{ __(':name screenshot', ['name' => $detail['name']]) }}" class="aspect-[4/3] w-full max-w-[14rem] shrink-0 rounded-lg object-cover object-top ring-1 ring-brand-ink/10" />
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $detail['name'] }}</p>
                                    <span class="font-mono text-2xs text-brand-mist">{{ $detail['slug'] }}</span>
                                    @if ($detail['installed'])
                                        <span class="rounded-full bg-emerald-50 px-1.5 text-2xs font-semibold text-emerald-800 ring-1 ring-emerald-200">{{ __('installed') }}</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ $detail['description'] }}</p>
                            </div>
                            <button type="button" wire:click="closeThemeDetail" class="text-brand-mist hover:text-brand-ink" aria-label="{{ __('Close') }}">
                                <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>

                        <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-2xs text-brand-moss">
                            <div><dt class="inline font-semibold">{{ __('By') }}</dt> <dd class="inline">{{ $detail['author'] ?: '—' }}</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Latest') }}</dt> <dd class="inline font-mono">{{ $detail['version'] ?: '—' }}</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Installs') }}</dt> <dd class="inline">{{ number_format($detail['active_installs']) }}+</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Rating') }}</dt> <dd class="inline">★ {{ number_format($detail['rating'] / 20, 1) }} ({{ number_format($detail['num_ratings']) }})</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Updated') }}</dt> <dd class="inline">{{ $detail['last_updated'] ?: '—' }}</dd></div>
                            <div><dt class="inline font-semibold">{{ __('Requires') }}</dt> <dd class="inline">WP {{ $detail['requires'] ?: '?' }} · PHP {{ $detail['requires_php'] ?: '?' }}</dd></div>
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

                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                            @if (! empty($detail['versions']))
                                <select wire:model="themeDetailVersion" aria-label="{{ __('Version') }}" class="rounded-md border-brand-ink/15 py-1 text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                                    <option value="">{{ __('Latest (:v)', ['v' => $detail['version']]) }}</option>
                                    @foreach ($detail['versions'] as $v)
                                        @if ($v !== $detail['version'])
                                            <option value="{{ $v }}">{{ $v }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            @endif
                            <label class="inline-flex items-center gap-1.5 text-xs text-brand-ink" title="{{ __('Switches the live site straight away. Customizer settings and menu locations are stored per theme, so they will not carry over.') }}">
                                <input type="checkbox" wire:model="themeDetailActivate" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                {{ __('Activate after install') }}
                            </label>
                            <x-spinner-button size="xs" variant="primary" type="button" target="installThemeFromDirectory" wire:click="installThemeFromDirectory" :disabled="$compat['blockers'] !== []">
                                {{ $detail['installed'] ? __('Reinstall / switch version') : __('Install') }}
                            </x-spinner-button>
                            @if ($detail['preview_url'] !== '')
                                <a href="{{ $detail['preview_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-brand-moss underline hover:text-brand-ink">
                                    <x-heroicon-o-eye class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Live demo') }}
                                </a>
                            @endif
                            <a href="https://wordpress.org/themes/{{ $detail['slug'] }}/" target="_blank" rel="noopener" class="text-xs text-brand-moss underline hover:text-brand-ink">{{ __('View on WordPress.org') }}</a>
                        </div>
                        <p class="mt-1.5 text-2xs text-brand-mist">{{ __('Installing leaves the live site alone unless you tick Activate — customizer settings and menu locations are stored per theme.') }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- 2. Recommendations from WordPress.org, minus what is already installed. --}}
        <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4" wire:init="loadThemeRecommendations">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Recommended from WordPress.org') }}</p>
                <div class="inline-flex rounded-md border border-brand-ink/10 p-0.5">
                    @foreach (['popular' => __('Popular'), 'featured' => __('Featured'), 'new' => __('New')] as $listKey => $listLabel)
                        <button type="button" wire:click="setThemeRecommendationList('{{ $listKey }}')" @class([
                            'rounded px-2 py-0.5 text-2xs font-semibold',
                            'bg-brand-ink text-brand-cream' => $themeRecommendationList === $listKey,
                            'text-brand-moss hover:bg-brand-sand/40' => $themeRecommendationList !== $listKey,
                        ])>{{ $listLabel }}</button>
                    @endforeach
                </div>
            </div>

            @if (! $themeRecommendationsLoaded)
                <p class="mt-2 flex items-center gap-2 text-xs text-brand-moss"><x-spinner size="sm" /> {{ __('Loading suggestions…') }}</p>
            @elseif ($themeRecommendations === [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('No suggestions right now — WordPress.org may be unreachable.') }}</p>
            @else
                <div class="mt-2 grid grid-cols-2 gap-2 lg:grid-cols-4">
                    @foreach ($themeRecommendations as $pick)
                        {{-- Re-checked here: recommendations can load before the installed list does. --}}
                        @continue(collect($themes)->contains('name', $pick['slug']))
                        <button type="button" wire:key="wp-theme-rec-{{ $pick['slug'] }}" wire:click="showThemeDetail(@js($pick['slug']))" class="overflow-hidden rounded-lg border border-brand-ink/10 bg-white text-left transition hover:border-brand-ink/20 hover:bg-brand-sand/30">
                            @if ($pick['screenshot'] !== '')
                                <img src="{{ $pick['screenshot'] }}" alt="" class="aspect-[4/3] w-full border-b border-brand-ink/10 object-cover object-top" loading="lazy" />
                            @else
                                <span class="flex aspect-[4/3] w-full items-center justify-center border-b border-brand-ink/10 bg-brand-sand/60"><x-heroicon-o-paint-brush class="h-5 w-5 text-brand-moss" aria-hidden="true" /></span>
                            @endif
                            <span class="block px-2 py-1.5">
                                <span class="block truncate text-xs font-semibold text-brand-ink">{{ $pick['name'] }}</span>
                                <span class="block text-2xs text-brand-mist">{{ number_format($pick['active_installs']) }}+ {{ __('installs') }} · ★ {{ number_format($pick['rating'] / 20, 1) }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if (! $themesLoaded)
        <div wire:init="loadThemes" class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Loading themes…') }}
        </div>
    @elseif (empty($themes))
        <p class="px-6 py-8 text-sm text-brand-moss">{{ __('No themes installed.') }}</p>
    @else
        {{-- 5. Bulk actions: one wp-cli call across every ticked theme. --}}
        @if ($canMutate && $selectedThemes !== [])
            <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/30 px-3 py-2 sm:px-4">
                <span class="text-xs font-semibold text-brand-ink">{{ trans_choice('{1} 1 selected|[2,*] :count selected', count($selectedThemes), ['count' => count($selectedThemes)]) }}</span>
                @foreach ([
                    'update' => __('Update'),
                    'auto-on' => __('Auto-updates on'),
                    'auto-off' => __('Auto-updates off'),
                ] as $bulkKey => $bulkLabel)
                    <x-spinner-button size="xs" variant="secondary" type="button" target="bulkThemeAction" wire:click="bulkThemeAction('{{ $bulkKey }}')">{{ $bulkLabel }}</x-spinner-button>
                @endforeach
                <button type="button" wire:click="$set('selectedThemes', [])" class="ml-auto text-xs text-brand-moss underline hover:text-brand-ink">{{ __('Clear') }}</button>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        @if ($canMutate)
                            <th class="w-8 py-3 pl-4 sm:pl-6">
                                <input type="checkbox" wire:click="toggleSelectAllThemes" @checked(count($selectedThemes) > 0 && count($selectedThemes) === count($themes)) aria-label="{{ __('Select all themes') }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                            </th>
                        @endif
                        <th class="px-4 py-3 sm:px-6">{{ __('Theme') }}</th>
                        <th class="px-4 py-3">{{ __('Version') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Update') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($themes as $theme)
                        @php
                            $active = $theme['status'] === 'active';
                            // 6. One child per parent, and none for a theme that already is one.
                            $canChild = ! str_ends_with($theme['name'], '-child')
                                && ! collect($themes)->contains('name', $theme['name'].'-child');
                        @endphp
                        <tr wire:key="wp-theme-{{ $theme['name'] }}">
                            @if ($canMutate)
                                <td class="w-8 py-3 pl-4 sm:pl-6">
                                    <input type="checkbox" wire:model.live="selectedThemes" value="{{ $theme['name'] }}" aria-label="{{ __('Select :name', ['name' => $theme['name']]) }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                </td>
                            @endif
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">
                                <button type="button" wire:click="showThemeDetail(@js($theme['name']))" class="hover:underline" title="{{ __('Details, versions and rollback') }}">{{ $theme['name'] }}</button>
                            </td>
                            <td class="px-4 py-3 text-brand-moss">v{{ $theme['version'] }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                    'bg-brand-sage/15 text-brand-forest' => $active,
                                    'bg-brand-sand/40 text-brand-moss' => ! $active,
                                ])>{{ $theme['status'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if ($theme['update'] === 'available')
                                    <span class="rounded-full bg-brand-gold/20 px-2 py-0.5 font-semibold text-brand-ink">{{ __('Update available') }}</span>
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($canMutate)
                                    <div class="inline-flex flex-wrap justify-end gap-1.5" wire:loading.class="opacity-50">
                                        @if ($theme['update'] === 'available')
                                            <button type="button" wire:click="updateTheme(@js($theme['name']))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Update') }}</button>
                                        @endif
                                        @unless ($active)
                                            <button type="button" wire:click="activateTheme(@js($theme['name']))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Activate') }}</button>
                                        @endunless

                                        {{-- Auto-updates: the difference between a
                                             CVE patched overnight and one that waits
                                             for someone to notice. --}}
                                        @php $themeAutoOn = ($theme['auto_update'] ?? 'off') === 'on'; @endphp
                                        <button
                                            type="button"
                                            wire:click="toggleThemeAutoUpdate(@js($theme['name']), {{ $themeAutoOn ? 'false' : 'true' }})"
                                            title="{{ $themeAutoOn ? __('Auto-updates on — click to disable') : __('Auto-updates off — click to enable') }}"
                                            @class([
                                                'rounded-md border px-2 py-1 text-xs font-medium',
                                                'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' => $themeAutoOn,
                                                'border-brand-ink/15 text-brand-moss hover:bg-brand-sand/40' => ! $themeAutoOn,
                                            ])
                                        >{{ $themeAutoOn ? __('Auto ✓') : __('Auto') }}</button>
                                        {{-- Admin/owner: `scaffold` runs at the Destructive tier. --}}
                                        @if ($canDestroy && $canChild)
                                            <button type="button" wire:click="createChildTheme(@js($theme['name']))" title="{{ __('Creates :child for your customizations, so parent updates cannot overwrite them. Not activated.', ['child' => $theme['name'].'-child']) }}" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Child theme') }}</button>
                                        @endif
                                        @if ($canDestroy && ! $active)
                                            <button type="button" wire:click="confirmDeleteTheme(@js($theme['name']))" class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100">{{ __('Delete') }}</button>
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
        <p class="px-6 py-3 text-xs text-brand-mist">{{ __('Click a theme name for its versions and rollback. Activation and updates queue and apply in the background — refresh to see the new state.') }}</p>
    @endif
</div>
