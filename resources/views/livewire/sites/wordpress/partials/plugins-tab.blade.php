<section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4">
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Plugins') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Live list pulled from `wp plugin list`. Each row is cross-checked against Wordfence Intelligence for known CVEs.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
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
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
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
        <div class="flex flex-wrap items-end gap-2 border-b border-brand-ink/10 bg-white px-6 py-3">
            <div class="min-w-0 flex-1">
                <x-input-label for="wp_plugin_install" :value="__('Install plugin (wp.org slug)')" class="text-[11px]" />
                <x-text-input id="wp_plugin_install" wire:model="pluginInstallSlug" wire:keydown.enter="installPlugin" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="wordpress-seo" />
            </div>
            <button
                type="button"
                wire:click="installPlugin"
                wire:loading.attr="disabled"
                wire:target="installPlugin"
                class="inline-flex h-10 items-center gap-1.5 rounded-md bg-brand-forest px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-ink disabled:opacity-60"
            >
                <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                <span wire:loading.remove wire:target="installPlugin">{{ __('Install & activate') }}</span>
                <span wire:loading wire:target="installPlugin">{{ __('Queueing…') }}</span>
            </button>
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
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
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
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">{{ $plugin['name'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">v{{ $plugin['version'] }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                    'bg-brand-sage/15 text-brand-forest' => $active,
                                    'bg-brand-sand/40 text-brand-moss' => ! $active,
                                ])>{{ $plugin['status'] }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
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
        <p class="px-6 py-3 text-[11px] text-brand-mist">{{ __('Vulnerability data: Wordfence Intelligence (free tier, 24h cache). Updates and activation changes queue and apply in the background — refresh to see the new state.') }}</p>
    @endif

    <x-input-error :messages="$errors->get('plugins')" class="px-6 pb-4" />
</section>
