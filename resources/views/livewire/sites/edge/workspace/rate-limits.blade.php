<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Rate limits') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Cap requests per IP. Optionally challenge instead of blocking when bot protection is on.') }}</p>
        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable rate limits') }}</span>
            </label>

            @foreach ($rules as $i => $rule)
                <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3 sm:grid-cols-4" wire:key="rl-{{ $i }}">
                    <div>
                        <x-input-label :value="__('Path')" />
                        <x-text-input wire:model="rules.{{ $i }}.path" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="/*" @disabled(! $managedDelivery) />
                    </div>
                    <div>
                        <x-input-label :value="__('Limit')" />
                        <x-text-input wire:model="rules.{{ $i }}.limit" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                    </div>
                    <div>
                        <x-input-label :value="__('Window (sec)')" />
                        <x-text-input wire:model="rules.{{ $i }}.window_seconds" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                    </div>
                    <div>
                        <x-input-label :value="__('Action')" />
                        <select wire:model="rules.{{ $i }}.action" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)>
                            <option value="block">{{ __('Block (429)') }}</option>
                            <option value="challenge">{{ __('Challenge') }}</option>
                        </select>
                        @if (count($rules) > 1)
                            <button type="button" wire:click="removeRule({{ $i }})" class="mt-2 text-xs font-semibold text-red-600">{{ __('Remove') }}</button>
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="addRule" class="text-sm font-semibold text-brand-sage" @disabled(! $managedDelivery)>{{ __('Add rule') }}</button>
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>
</div>
