@if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'tcprouters' || $engine_subtab === 'tcpservices' || ($optimisticEngineSubtabs ?? false)))
    <div @if ($optimisticEngineSubtabs ?? false) x-show="['tcprouters','tcpservices'].includes(subtab)" x-cloak @endif class="space-y-4 mb-6" wire:key="traefik-tcp-crud">
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Manage TCP routes') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Each route is a dply-tcp-{slug}.yml file with a TCP router and service. Hot-reloaded automatically.') }}</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="openAddTraefikTcpRouteForm" @disabled($isDeployer || $actionInFlight)
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">
                        <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Add TCP route') }}
                    </button>
                    <button type="button" wire:click="loadTraefikTcpRoutesConfig" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium hover:bg-brand-sand/40">{{ __('Reload') }}</button>
                </div>
            </div>
            @if ($traefik_tcp_routes_flash)<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_tcp_routes_flash }}</div>@endif
            @if ($traefik_tcp_routes_error)<div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">{{ $traefik_tcp_routes_error }}</div>@endif

            @if ($traefik_tcp_routes_show_add)
                <form wire:submit.prevent="submitAddTraefikTcpRoute" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label><span class="text-xs font-medium">{{ __('Slug') }}</span><input type="text" wire:model.lazy="traefik_tcp_routes_new.slug" class="mt-1 w-full rounded-md font-mono text-sm" required /></label>
                        <label><span class="text-xs font-medium">{{ __('Backend address') }}</span><input type="text" wire:model.lazy="traefik_tcp_routes_new.server_address" placeholder=":3306" class="mt-1 w-full rounded-md font-mono text-sm" required /></label>
                        <label class="sm:col-span-2"><span class="text-xs font-medium">{{ __('Rule') }}</span><input type="text" wire:model.lazy="traefik_tcp_routes_new.rule" class="mt-1 w-full rounded-md font-mono text-sm" /></label>
                        <label class="sm:col-span-2"><span class="text-xs font-medium">{{ __('Entry points') }}</span><input type="text" wire:model.lazy="traefik_tcp_routes_new.entry_points" placeholder="web" class="mt-1 w-full rounded-md text-sm" /></label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelAddTraefikTcpRouteForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create') }}</button>
                    </div>
                </form>
            @endif

            @if ($traefik_tcp_routes_loaded && $traefik_tcp_routes_form !== [])
                @foreach ($traefik_tcp_routes_form as $tcpSlug => $tcpFields)
                    <form wire:submit.prevent="saveTraefikTcpRoute(@js($tcpSlug))" class="mt-4 rounded-xl border border-brand-ink/10 p-4" wire:key="traefik-tcp-{{ $tcpSlug }}">
                        <div class="flex justify-between"><p class="font-mono text-sm font-semibold">dply-tcp-{{ $tcpSlug }}</p>
                            <button type="button" wire:click="openConfirmActionModal('removeTraefikTcpRoute', [@js($tcpSlug)], @js(__('Remove TCP route')), @js(__('Delete this TCP config?')), @js(__('Remove')), true)" class="text-[11px] text-rose-800">{{ __('Remove') }}</button></div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="sm:col-span-2"><span class="text-xs">{{ __('Rule') }}</span><input type="text" wire:model.lazy="traefik_tcp_routes_form.{{ $tcpSlug }}.rule" class="mt-1 w-full rounded-md font-mono text-sm" /></label>
                            <label><span class="text-xs">{{ __('Entry points') }}</span><input type="text" wire:model.lazy="traefik_tcp_routes_form.{{ $tcpSlug }}.entry_points" class="mt-1 w-full rounded-md text-sm" /></label>
                            <label><span class="text-xs">{{ __('Backend') }}</span><input type="text" wire:model.lazy="traefik_tcp_routes_form.{{ $tcpSlug }}.server_address" class="mt-1 w-full rounded-md font-mono text-sm" /></label>
                        </div>
                        <div class="mt-3 flex justify-end"><button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Save') }}</button></div>
                    </form>
                @endforeach
            @endif
        </div>
    </div>
@endif
