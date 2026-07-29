<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Jobs') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Background queues for middleware and SSR workers — enqueue work without a Cloud app.') }}</p>
        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable Edge jobs UX') }}</span>
            </label>

            <div>
                <x-input-label for="default_queue" :value="__('Default queue binding name')" />
                <x-text-input id="default_queue" wire:model="default_queue" type="text" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Must match a queue binding on Bindings (e.g. JOBS).') }}</p>
            </div>

            <div class="rounded-xl border border-brand-ink/10 p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Queue bindings') }}</p>
                @if ($queueBindings === [])
                    <p class="mt-2 text-sm text-brand-moss">{{ __('No queue bindings yet.') }}</p>
                @else
                    <ul class="mt-2 space-y-1 text-sm text-brand-ink">
                        @foreach ($queueBindings as $binding)
                            <li class="font-mono text-xs">{{ $binding['name'] ?? '—' }}</li>
                        @endforeach
                    </ul>
                @endif
                <a href="{{ $bindingsUrl }}" wire:navigate class="mt-3 inline-flex text-sm font-semibold text-brand-sage">{{ __('Manage bindings') }}</a>
            </div>

            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss dark:bg-brand-sand/10">
                {{ __('From middleware/SSR: `await env.JOBS.send({ type: "…", … })`. Consumers run in your bound worker scripts.') }}
            </div>

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>
</div>
