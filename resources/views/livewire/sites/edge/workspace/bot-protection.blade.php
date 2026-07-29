<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Bot protection') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Challenge bots on forms or every page. Keys stay on Dply-hosted delivery.') }}</p>
        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4" @disabled(! $managedDelivery)>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span>
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Enable bot protection') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Uses a privacy-friendly challenge widget on your Edge site.') }}</span>
                </span>
            </label>

            <div>
                <x-input-label for="mode" :value="__('Mode')" />
                <select id="mode" wire:model="mode" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)>
                    <option value="forms">{{ __('Forms only') }}</option>
                    <option value="all">{{ __('All HTML pages') }}</option>
                </select>
            </div>

            <div>
                <x-input-label for="site_key" :value="__('Site key')" />
                <x-text-input id="site_key" wire:model="site_key" type="text" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                <x-input-error :messages="$errors->get('site_key')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="secret_key" :value="__('Secret key')" />
                <x-text-input id="secret_key" wire:model="secret_key" type="password" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                <x-input-error :messages="$errors->get('secret_key')" class="mt-2" />
            </div>

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" @disabled(! $managedDelivery)>
                    <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </section>
</div>
