@if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'services' || ($optimisticEngineSubtabs ?? false)))
    <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'services'" x-cloak @endif class="space-y-4 mb-6" wire:key="traefik-http-services-crud">
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom HTTP services') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Standalone load balancers in dply-svc-{slug}.yml — reference from router middleware chains or custom routes.') }}</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="openAddTraefikHttpServiceForm" @disabled($isDeployer || $actionInFlight)
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">
                        <x-heroicon-o-plus class="h-4 w-4" /> {{ __('Add service') }}
                    </button>
                    <button type="button" wire:click="loadTraefikHttpServicesConfig" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium hover:bg-brand-sand/40">{{ __('Reload') }}</button>
                </div>
            </div>
            @if ($traefik_http_services_flash)<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_http_services_flash }}</div>@endif
            @if ($traefik_http_services_error)<div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">{{ $traefik_http_services_error }}</div>@endif

            @if ($traefik_http_services_show_add)
                <form wire:submit.prevent="submitAddTraefikHttpService" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 space-y-3">
                    <label><span class="text-xs font-medium">{{ __('Slug') }}</span><input type="text" wire:model.lazy="traefik_http_services_new.slug" class="mt-1 w-full max-w-xs rounded-md font-mono text-sm" required /></label>
                    <label><span class="text-xs font-medium">{{ __('Server URLs (one per line)') }}</span>
                        <textarea wire:model.lazy="traefik_http_services_new.servers" rows="3" class="mt-1 w-full rounded-md font-mono text-sm" placeholder="http://127.0.0.1:3000"></textarea></label>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelAddTraefikHttpServiceForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create') }}</button>
                    </div>
                </form>
            @endif

            @if ($traefik_http_services_loaded && $traefik_http_services_form !== [])
                @foreach ($traefik_http_services_form as $svcSlug => $svcFields)
                    <form wire:submit.prevent="saveTraefikHttpService(@js($svcSlug))" class="mt-4 rounded-xl border border-brand-ink/10 p-4" wire:key="traefik-svc-{{ $svcSlug }}">
                        <div class="flex justify-between"><p class="font-mono text-sm font-semibold">dply-svc-{{ $svcSlug }}</p>
                            <button type="button" wire:click="openConfirmActionModal('removeTraefikHttpService', [@js($svcSlug)], @js(__('Remove service')), @js(__('Delete this service file?')), @js(__('Remove')), true)" class="text-[11px] text-rose-800">{{ __('Remove') }}</button></div>
                        <label class="mt-3 block"><span class="text-xs">{{ __('Server URLs') }}</span>
                            <textarea wire:model.lazy="traefik_http_services_form.{{ $svcSlug }}.servers" rows="3" class="mt-1 w-full rounded-md font-mono text-sm"></textarea></label>
                        <div class="mt-3 flex justify-end"><button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Save') }}</button></div>
                    </form>
                @endforeach
            @endif
        </div>
    </div>
@endif
