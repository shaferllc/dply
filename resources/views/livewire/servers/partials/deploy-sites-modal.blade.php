{{--
    "Deploy host" modal — pick which attached sites to include before launching.
    Driven by WatchesSiteDeploys: $deployModalOpen, $deployModalSiteIds (selected,
    checkbox-bound), and the $this->deployModalSites computed list. Only opens when
    the host has ≥2 deployable sites; one site deploys straight away with no modal.
--}}
@if ($deployModalOpen)
    @php
        $modalSites = $this->deployModalSites;
        $allIds = array_column($modalSites, 'id');
        $selectedCount = count($deployModalSiteIds);
    @endphp
    @teleport('body')
        <div class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" wire:key="deploy-sites-modal">
            <div class="absolute inset-0 bg-brand-ink/40" wire:click="closeDeployModal"></div>

            <div class="relative flex w-full max-w-lg flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
                    <div class="flex items-center gap-3">
                        <x-icon-badge>
                            <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Deploy host') }}</h2>
                            <p class="text-xs text-brand-moss">{{ __('Choose which sites to deploy on this server.') }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeDeployModal" class="rounded-lg px-2 py-1 text-brand-mist hover:text-brand-ink" title="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <div class="flex items-center justify-between border-b border-brand-ink/10 px-6 py-2.5 text-xs">
                    <span class="font-semibold text-brand-moss">{{ trans_choice(':count of :total selected|:count of :total selected', $selectedCount, ['count' => $selectedCount, 'total' => count($modalSites)]) }}</span>
                    <span class="flex items-center gap-3">
                        <button type="button" wire:click="$set('deployModalSiteIds', @js($allIds))" class="font-semibold text-brand-forest hover:underline">{{ __('Select all') }}</button>
                        <button type="button" wire:click="$set('deployModalSiteIds', [])" class="font-semibold text-brand-moss hover:underline">{{ __('None') }}</button>
                    </span>
                </div>

                <div class="max-h-[50vh] overflow-y-auto px-6 py-3">
                    <div class="space-y-1.5">
                        @foreach ($modalSites as $modalSite)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-brand-ink/10 px-3 py-2.5 transition hover:bg-brand-sand/20 has-[:checked]:border-brand-sage/40 has-[:checked]:bg-brand-sage/10">
                                <input type="checkbox" wire:model.live="deployModalSiteIds" value="{{ $modalSite['id'] }}"
                                    class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                                <span class="inline-flex items-center gap-2 min-w-0">
                                    <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                    <span class="min-w-0 truncate text-sm font-medium text-brand-ink">{{ $modalSite['name'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                    <button type="button" wire:click="closeDeployModal" class="text-sm font-semibold text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="confirmServerDeploy" wire:loading.attr="disabled" wire:target="confirmServerDeploy"
                        @disabled($selectedCount === 0)
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-50">
                        <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" wire:loading.remove wire:target="confirmServerDeploy" />
                        <span wire:loading wire:target="confirmServerDeploy" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>
                        {{ $selectedCount > 0 ? __('Deploy :count', ['count' => $selectedCount]) : __('Deploy') }}
                    </button>
                </div>
            </div>
        </div>
    @endteleport
@endif
