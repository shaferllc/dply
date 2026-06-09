<div class="space-y-8">
    <x-page-header
        eyebrow="Realtime"
        title="New realtime app"
        description="Provisions a Pusher/Reverb-compatible channel app on dply's edge and hands you the credentials."
    />

    <div class="max-w-xl">
        <x-section-card>
            <form wire:submit="create" class="space-y-6">
                <div>
                    <x-input-label for="name" value="App name" :required="true" />
                    <x-text-input
                        id="name"
                        wire:model="form.name"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="my-app realtime"
                        autofocus
                    />
                    <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                    <p class="mt-2 text-sm text-brand-moss">A label to recognise this app. You can run many apps in one organization.</p>
                </div>

                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-brand-ink">Price</span>
                        <span class="font-semibold text-brand-ink">${{ number_format($priceCents / 100, 2) }} / month</span>
                    </div>
                    <p class="mt-1">Flat per active app. Includes up to {{ number_format($maxConnections) }} concurrent connections.</p>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="create">Create app</span>
                        <span wire:loading wire:target="create">Provisioning…</span>
                    </x-primary-button>
                    <a href="{{ route('realtime.index') }}" wire:navigate>
                        <x-secondary-button type="button">Cancel</x-secondary-button>
                    </a>
                </div>
            </form>
        </x-section-card>
    </div>
</div>
