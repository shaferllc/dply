@if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'udprouters' || $engine_subtab === 'udpservices' || ($optimisticEngineSubtabs ?? false)))
    <div @if ($optimisticEngineSubtabs ?? false) x-show="['udprouters','udpservices'].includes(subtab)" x-cloak @endif class="space-y-4 mb-6" wire:key="traefik-udp-crud">
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Manage UDP routes') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Each route is dply-udp-{slug}.yml with router + service. Hot-reloaded.') }}</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="openAddTraefikUdpRouteForm" @disabled($isDeployer || $actionInFlight)
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">
                        <x-heroicon-o-plus class="h-4 w-4" /> {{ __('Add UDP route') }}
                    </button>
                    <button type="button" wire:click="loadTraefikUdpRoutesConfig" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium hover:bg-brand-sand/40">{{ __('Reload') }}</button>
                </div>
            </div>
            @if ($traefik_udp_routes_flash)<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_udp_routes_flash }}</div>@endif
            @if ($traefik_udp_routes_error)<div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">{{ $traefik_udp_routes_error }}</div>@endif

            @if ($traefik_udp_routes_show_add)
                <form wire:submit.prevent="submitAddTraefikUdpRoute" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label><span class="text-xs font-medium">{{ __('Slug') }}</span><input type="text" wire:model.lazy="traefik_udp_routes_new.slug" class="mt-1 w-full rounded-md font-mono text-sm" required /></label>
                        <label><span class="text-xs font-medium">{{ __('Backend address') }}</span><input type="text" wire:model.lazy="traefik_udp_routes_new.server_address" placeholder=":53" class="mt-1 w-full rounded-md font-mono text-sm" required /></label>
                        <label class="sm:col-span-2"><span class="text-xs font-medium">{{ __('Entry points') }}</span><input type="text" wire:model.lazy="traefik_udp_routes_new.entry_points" class="mt-1 w-full rounded-md text-sm" /></label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelAddTraefikUdpRouteForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create') }}</button>
                    </div>
                </form>
            @endif

            @if ($traefik_udp_routes_loaded && $traefik_udp_routes_form !== [])
                @foreach ($traefik_udp_routes_form as $udpSlug => $udpFields)
                    <form wire:submit.prevent="saveTraefikUdpRoute(@js($udpSlug))" class="mt-4 rounded-xl border border-brand-ink/10 p-4" wire:key="traefik-udp-{{ $udpSlug }}">
                        <div class="flex justify-between"><p class="font-mono text-sm font-semibold">dply-udp-{{ $udpSlug }}</p>
                            <button type="button" wire:click="openConfirmActionModal('removeTraefikUdpRoute', [@js($udpSlug)], @js(__('Remove UDP route')), @js(__('Delete this UDP config?')), @js(__('Remove')), true)" class="text-[11px] text-rose-800">{{ __('Remove') }}</button></div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label><span class="text-xs">{{ __('Entry points') }}</span><input type="text" wire:model.lazy="traefik_udp_routes_form.{{ $udpSlug }}.entry_points" class="mt-1 w-full rounded-md text-sm" /></label>
                            <label><span class="text-xs">{{ __('Backend') }}</span><input type="text" wire:model.lazy="traefik_udp_routes_form.{{ $udpSlug }}.server_address" class="mt-1 w-full rounded-md font-mono text-sm" /></label>
                        </div>
                        <div class="mt-3 flex justify-end"><button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Save') }}</button></div>
                    </form>
                @endforeach
            @endif
        </div>
    </div>
@endif
