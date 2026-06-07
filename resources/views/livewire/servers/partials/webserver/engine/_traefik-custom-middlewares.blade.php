@if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'middlewares' || ($optimisticEngineSubtabs ?? false)))
    <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'middlewares'" x-cloak @endif class="space-y-4 mb-6" wire:key="traefik-custom-middlewares">
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Custom middlewares') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Standalone middlewares in dply-custom-mw-{slug}.yml — reference them from routers by name.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="openAddTraefikCustomMiddlewareForm" @disabled($isDeployer || $actionInFlight)
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">
                        <x-heroicon-o-plus class="h-4 w-4" /> {{ __('Add middleware') }}
                    </button>
                    <button type="button" wire:click="loadTraefikCustomMiddlewaresConfig" wire:loading.attr="disabled" wire:target="loadTraefikCustomMiddlewaresConfig"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium hover:bg-brand-sand/40">
                        {{ __('Reload') }}
                    </button>
                </div>
            </div>
            @if ($traefik_custom_middlewares_flash)<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_custom_middlewares_flash }}</div>@endif
            @if ($traefik_custom_middlewares_error)<div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">{{ $traefik_custom_middlewares_error }}</div>@endif

            @if ($traefik_custom_middlewares_show_add)
                <form wire:submit.prevent="submitAddTraefikCustomMiddleware" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 space-y-3">
                    <p class="text-sm font-semibold">{{ __('New middleware') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label><span class="text-xs">{{ __('Slug') }}</span>
                            <input type="text" wire:model.lazy="traefik_custom_middlewares_new.slug" class="mt-1 w-full rounded-md font-mono text-sm" required /></label>
                        <label><span class="text-xs">{{ __('Type') }}</span>
                            <select wire:model.live="traefik_custom_middlewares_new.type" class="mt-1 w-full rounded-md text-sm">
                                @foreach (\App\Services\Servers\TraefikCustomMiddlewaresConfig::TYPES as $mwType)
                                    <option value="{{ $mwType }}">{{ $mwType }}</option>
                                @endforeach
                            </select></label>
                        @if ($traefik_custom_middlewares_new['type'] === 'stripPrefix')
                            <label class="sm:col-span-2"><span class="text-xs">{{ __('Prefix') }}</span>
                                <input type="text" wire:model.lazy="traefik_custom_middlewares_new.prefix" class="mt-1 w-full rounded-md font-mono text-sm" /></label>
                        @elseif ($traefik_custom_middlewares_new['type'] === 'redirectScheme')
                            <label><span class="text-xs">{{ __('Scheme') }}</span>
                                <select wire:model="traefik_custom_middlewares_new.scheme" class="mt-1 w-full rounded-md text-sm"><option value="https">https</option><option value="http">http</option></select></label>
                        @elseif ($traefik_custom_middlewares_new['type'] === 'headers')
                            <label><span class="text-xs">{{ __('Header') }}</span><input type="text" wire:model.lazy="traefik_custom_middlewares_new.header_key" class="mt-1 w-full rounded-md text-sm" /></label>
                            <label><span class="text-xs">{{ __('Value') }}</span><input type="text" wire:model.lazy="traefik_custom_middlewares_new.header_value" class="mt-1 w-full rounded-md text-sm" /></label>
                        @elseif ($traefik_custom_middlewares_new['type'] === 'basicAuth')
                            <label class="sm:col-span-2"><span class="text-xs">{{ __('Users (user:hash per line)') }}</span>
                                <textarea wire:model.lazy="traefik_custom_middlewares_new.users" rows="3" class="mt-1 w-full rounded-md font-mono text-xs"></textarea></label>
                        @endif
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelAddTraefikCustomMiddlewareForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Create') }}</button>
                    </div>
                </form>
            @endif
        </div>

        @if ($traefik_custom_middlewares_loaded && $traefik_custom_middlewares_form !== [])
            @foreach ($traefik_custom_middlewares_form as $mwSlug => $mwFields)
                <form wire:submit.prevent="saveTraefikCustomMiddleware(@js($mwSlug))" class="{{ $card }} p-5" wire:key="traefik-mw-{{ $mwSlug }}">
                    <div class="flex justify-between">
                        <p class="font-mono text-sm font-semibold">dply-custom-mw-{{ $mwSlug }}</p>
                        <button type="button" wire:click="openConfirmActionModal('removeTraefikCustomMiddleware', [@js($mwSlug)], @js(__('Remove middleware')), @js(__('Delete this file?')), @js(__('Remove')), true)"
                            class="text-[11px] text-rose-800">{{ __('Remove') }}</button>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label><span class="text-xs">{{ __('Type') }}</span>
                            <select wire:model.live="traefik_custom_middlewares_form.{{ $mwSlug }}.type" class="mt-1 w-full rounded-md text-sm">
                                @foreach (\App\Services\Servers\TraefikCustomMiddlewaresConfig::TYPES as $mwType)
                                    <option value="{{ $mwType }}">{{ $mwType }}</option>
                                @endforeach
                            </select></label>
                        @if (($traefik_custom_middlewares_form[$mwSlug]['type'] ?? '') === 'stripPrefix')
                            <label class="sm:col-span-2"><span class="text-xs">{{ __('Prefix') }}</span>
                                <input type="text" wire:model.lazy="traefik_custom_middlewares_form.{{ $mwSlug }}.prefix" class="mt-1 w-full rounded-md font-mono text-sm" /></label>
                        @endif
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Save') }}</button>
                    </div>
                </form>
            @endforeach
        @endif
    </div>
@endif
