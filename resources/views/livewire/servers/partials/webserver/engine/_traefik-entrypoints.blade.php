@if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'entrypoints' || ($optimisticEngineSubtabs ?? false)))
    <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'entrypoints'" x-cloak @endif class="space-y-4 mb-6" wire:key="traefik-entrypoints-crud">
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Manage entry points') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Entry points live in traefik.yml. Saving restarts Traefik. `web`, `traefik`, and `metrics` are required for dply but addresses can be edited.') }}</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="openAddTraefikEntrypointForm" @disabled($isDeployer || $actionInFlight)
                        class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">
                        <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Add entry point') }}
                    </button>
                    <button type="button" wire:click="loadTraefikEntrypointsConfig" wire:loading.attr="disabled" wire:target="loadTraefikEntrypointsConfig"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium hover:bg-brand-sand/40">
                        {{ __('Reload') }}
                    </button>
                </div>
            </div>
            @if ($traefik_entrypoints_flash)<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_entrypoints_flash }}</div>@endif
            @if ($traefik_entrypoints_error)<div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">{{ $traefik_entrypoints_error }}</div>@endif

            @if ($traefik_entrypoints_show_add)
                <form wire:submit.prevent="submitAddTraefikEntrypoint" class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 grid gap-3 sm:grid-cols-2">
                    <label><span class="text-xs font-medium">{{ __('Name') }}</span>
                        <input type="text" wire:model.lazy="traefik_entrypoints_new.name" placeholder="internal" class="mt-1 w-full rounded-md font-mono text-sm" required /></label>
                    <label><span class="text-xs font-medium">{{ __('Address') }}</span>
                        <input type="text" wire:model.lazy="traefik_entrypoints_new.address" placeholder=":8080" class="mt-1 w-full rounded-md font-mono text-sm" required /></label>
                    <div class="sm:col-span-2 flex justify-end gap-2">
                        <button type="button" wire:click="cancelAddTraefikEntrypointForm">{{ __('Cancel') }}</button>
                        <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Add and restart') }}</button>
                    </div>
                </form>
            @endif

            @if ($traefik_entrypoints_loaded && $traefik_entrypoints_form !== [])
                <div class="mt-6 space-y-3">
                    @foreach ($traefik_entrypoints_form as $epName => $epFields)
                        @php $locked = in_array($epName, \App\Services\Servers\TraefikEntrypointsConfig::LOCKED_NAMES, true); @endphp
                        <form wire:submit.prevent="saveTraefikEntrypoint(@js($epName))" class="rounded-xl border border-brand-ink/10 p-4" wire:key="traefik-ep-{{ $epName }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-mono text-sm font-semibold">{{ $epName }} @if ($locked)<span class="text-[10px] font-normal text-brand-moss">({{ __('dply core') }})</span>@endif</p>
                                @if (! $locked)
                                    <button type="button" wire:click="openConfirmActionModal('removeTraefikEntrypoint', [@js($epName)], @js(__('Remove entry point')), @js(__('Remove :name from traefik.yml and restart Traefik?', ['name' => $epName])), @js(__('Remove')), true)"
                                        class="text-[11px] font-medium text-rose-800">{{ __('Remove') }}</button>
                                @endif
                            </div>
                            <label class="mt-3 block max-w-md">
                                <span class="text-xs text-brand-moss">{{ __('Listen address') }}</span>
                                <input type="text" wire:model.lazy="traefik_entrypoints_form.{{ $epName }}.address" class="mt-1 w-full rounded-md font-mono text-sm" />
                            </label>
                            <div class="mt-3 flex justify-end">
                                <button type="submit" class="rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Save and restart') }}</button>
                            </div>
                        </form>
                    @endforeach
                </div>
            @elseif ($traefik_entrypoints_loaded)
                <x-empty-state class="mt-6" icon="heroicon-o-signal" :title="__('No entry points in traefik.yml')" :description="__('Add one above or reinstall the edge proxy.')" />
            @endif
        </div>
    </div>
@endif
