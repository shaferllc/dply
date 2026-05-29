<section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4">
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Themes') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Live list pulled from `wp theme list`. Activate a theme or push available updates.') }}</p>
        </div>
        @if ($themesLoaded)
            <button type="button" wire:click="loadThemes" wire:loading.attr="disabled" wire:target="loadThemes" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                <span wire:loading.remove wire:target="loadThemes" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Refresh') }}
                </span>
                <span wire:loading wire:target="loadThemes" class="inline-flex items-center gap-1.5">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Refreshing…') }}
                </span>
            </button>
        @endif
    </div>

    @if ($canMutate)
        <div class="flex flex-wrap items-end gap-2 border-b border-brand-ink/10 bg-white px-6 py-3">
            <div class="min-w-0 flex-1">
                <x-input-label for="wp_theme_install" :value="__('Install theme (wp.org slug)')" class="text-[11px]" />
                <x-text-input id="wp_theme_install" wire:model="themeInstallSlug" wire:keydown.enter="installTheme" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="twentytwentyfive" />
            </div>
            <button
                type="button"
                wire:click="installTheme"
                wire:loading.attr="disabled"
                wire:target="installTheme"
                class="inline-flex h-10 items-center gap-1.5 rounded-md bg-brand-forest px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-ink disabled:opacity-60"
            >
                <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                <span wire:loading.remove wire:target="installTheme">{{ __('Install') }}</span>
                <span wire:loading wire:target="installTheme">{{ __('Queueing…') }}</span>
            </button>
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
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3 sm:px-6">{{ __('Theme') }}</th>
                        <th class="px-4 py-3">{{ __('Version') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Update') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($themes as $theme)
                        @php $active = $theme['status'] === 'active'; @endphp
                        <tr wire:key="wp-theme-{{ $theme['name'] }}">
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">{{ $theme['name'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">v{{ $theme['version'] }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                    'bg-brand-sage/15 text-brand-forest' => $active,
                                    'bg-brand-sand/40 text-brand-moss' => ! $active,
                                ])>{{ $theme['status'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-[11px]">
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
        <p class="px-6 py-3 text-[11px] text-brand-mist">{{ __('Activation and updates queue and apply in the background — refresh to see the new state.') }}</p>
    @endif

    <x-input-error :messages="$errors->get('themes')" class="px-6 pb-4" />
</section>
