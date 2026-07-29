<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Waiting room') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Queue visitors during launches so your origin and Edge stay healthy.') }}</p>
        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable waiting room') }}</span>
            </label>

            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <x-input-label for="total_active_users" :value="__('Max active visitors')" />
                    <x-text-input id="total_active_users" wire:model="total_active_users" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                </div>
                <div>
                    <x-input-label for="new_users_per_minute" :value="__('New admits / minute')" />
                    <x-text-input id="new_users_per_minute" wire:model="new_users_per_minute" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                </div>
                <div>
                    <x-input-label for="session_duration_minutes" :value="__('Session (minutes)')" />
                    <x-text-input id="session_duration_minutes" wire:model="session_duration_minutes" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                </div>
            </div>

            <div>
                <x-input-label for="paths" :value="__('Paths (one per line)')" />
                <textarea id="paths" wire:model="paths" rows="3" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)></textarea>
            </div>

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>
</div>
