<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Snippets') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Inject small HTML into every matching page without a redeploy of your app.') }}</p>
        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable snippets') }}</span>
            </label>

            @foreach ($items as $i => $item)
                <div class="space-y-3 rounded-xl border border-brand-ink/10 p-3" wire:key="snip-{{ $i }}">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <x-input-label :value="__('Name')" />
                            <x-text-input wire:model="items.{{ $i }}.name" type="text" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                        </div>
                        <div>
                            <x-input-label :value="__('Inject')" />
                            <select wire:model="items.{{ $i }}.phase" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)>
                                <option value="head">{{ __('Before </head>') }}</option>
                                <option value="body">{{ __('Before </body>') }}</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label :value="__('Path')" />
                            <x-text-input wire:model="items.{{ $i }}.path" type="text" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                        </div>
                    </div>
                    <div>
                        <x-input-label :value="__('HTML')" />
                        <textarea wire:model="items.{{ $i }}.html" rows="4" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs dark:bg-zinc-900" @disabled(! $managedDelivery)></textarea>
                    </div>
                    @if (count($items) > 1)
                        <button type="button" wire:click="removeItem({{ $i }})" class="text-xs font-semibold text-red-600">{{ __('Remove') }}</button>
                    @endif
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="addItem" class="text-sm font-semibold text-brand-sage" @disabled(! $managedDelivery)>{{ __('Add snippet') }}</button>
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>
</div>
