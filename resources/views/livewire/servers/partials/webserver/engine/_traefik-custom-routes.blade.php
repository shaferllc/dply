@if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'routers' || ($optimisticEngineSubtabs ?? false)))
    <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'routers'" x-cloak @endif class="space-y-4 mb-6" wire:key="traefik-custom-routes">
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom HTTP routers') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Add routes as dply-custom-{slug}.yml — hot-reloaded without restart. Site routes from provisioning are separate.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="openAddTraefikCustomRouteForm" @disabled($isDeployer || $actionInFlight)
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">
                        <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Add router') }}
                    </button>
                    <button type="button" wire:click="loadTraefikCustomRoutesConfig" wire:loading.attr="disabled" wire:target="loadTraefikCustomRoutesConfig"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium hover:bg-brand-sand/40">
                        {{ __('Reload') }}
                    </button>
                </div>
            </div>
            @if ($traefik_custom_routes_flash)<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_custom_routes_flash }}</div>@endif
            @if ($traefik_custom_routes_error)<div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900"><pre class="text-xs whitespace-pre-wrap">{{ $traefik_custom_routes_error }}</pre></div>@endif

            @if ($traefik_custom_routes_show_add)
                <form wire:submit.prevent="submitAddTraefikCustomRoute" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 space-y-3">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('New custom router') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block"><span class="text-xs font-medium">{{ __('Slug') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_new.slug" class="mt-1 w-full rounded-md border-brand-ink/15 font-mono text-sm" required /></label>
                        <label class="block sm:col-span-2"><span class="text-xs font-medium">{{ __('Hostnames') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_new.hosts" placeholder="api.example.com" class="mt-1 w-full rounded-md border-brand-ink/15 text-sm" required /></label>
                        <label class="block sm:col-span-2"><span class="text-xs font-medium">{{ __('Upstream URL') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_new.upstream" placeholder="http://127.0.0.1:3000" class="mt-1 w-full rounded-md border-brand-ink/15 font-mono text-sm" required /></label>
                        <label class="block sm:col-span-2"><span class="text-xs font-medium">{{ __('Rule (optional)') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_new.rule" class="mt-1 w-full rounded-md border-brand-ink/15 font-mono text-sm" /></label>
                        <label class="block sm:col-span-2"><span class="text-xs font-medium">{{ __('Middlewares (optional)') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_new.middlewares" placeholder="dply-custom-mw-strip" class="mt-1 w-full rounded-md border-brand-ink/15 font-mono text-sm" /></label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelAddTraefikCustomRouteForm" class="text-xs font-medium text-brand-moss">{{ __('Cancel') }}</button>
                        <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create') }}</button>
                    </div>
                </form>
            @endif
        </div>

        @if ($traefik_custom_routes_loaded && $traefik_custom_routes_form !== [])
            @foreach ($traefik_custom_routes_form as $routeSlug => $routeFields)
                <form wire:submit.prevent="saveTraefikCustomRoute(@js($routeSlug))" class="{{ $card }} p-5" wire:key="traefik-route-{{ $routeSlug }}">
                    <div class="flex justify-between gap-2">
                        <p class="font-mono text-sm font-semibold">dply-custom-{{ $routeSlug }}.yml</p>
                        <button type="button" wire:click="openConfirmActionModal('removeTraefikCustomRoute', [@js($routeSlug)], @js(__('Remove custom route')), @js(__('Delete this dynamic route file?')), @js(__('Remove')), true)"
                            class="text-[11px] font-medium text-rose-800">{{ __('Remove') }}</button>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="sm:col-span-2"><span class="text-xs">{{ __('Hostnames') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_form.{{ $routeSlug }}.hosts" class="mt-1 w-full rounded-md border-brand-ink/15 text-sm" /></label>
                        <label class="sm:col-span-2"><span class="text-xs">{{ __('Upstream') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_form.{{ $routeSlug }}.upstream" class="mt-1 w-full rounded-md border-brand-ink/15 font-mono text-sm" /></label>
                        <label class="sm:col-span-2"><span class="text-xs">{{ __('Rule') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_form.{{ $routeSlug }}.rule" class="mt-1 w-full rounded-md border-brand-ink/15 font-mono text-sm" /></label>
                        <label class="sm:col-span-2"><span class="text-xs">{{ __('Middlewares') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_routes_form.{{ $routeSlug }}.middlewares" class="mt-1 w-full rounded-md border-brand-ink/15 font-mono text-sm" /></label>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Save route') }}</button>
                    </div>
                </form>
            @endforeach
        @endif
    </div>
@endif
