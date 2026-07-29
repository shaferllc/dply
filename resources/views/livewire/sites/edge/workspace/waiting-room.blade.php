<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-waiting-room',
            'what' => __('Waiting room queues excess visitors during launches or flash traffic so your site stays up instead of melting under a stampede.'),
            'steps' => [
                __('Set max active visitors (how many can browse at once) and how many new people to admit per minute.'),
                __('Set session length — how long someone stays “active” before they may need to re-queue.'),
                __('List paths that should use the room (one per line, e.g. / or /checkout/*). Leave empty only if you intend site-wide.'),
                __('Enable and Save before the traffic spike; turn off when the event ends.'),
            ],
            'tips' => [
                __('Start conservative (lower max active) and raise once you see the room drain cleanly.'),
                __('Static marketing pages can stay outside the path list so the wait page itself stays snappy.'),
            ],
        ])

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
