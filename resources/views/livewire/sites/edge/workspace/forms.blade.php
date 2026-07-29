<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Forms') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Accept POSTs on your Edge site and email the results — no app server required.') }}</p>
        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable Edge forms') }}</span>
            </label>

            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss dark:bg-brand-sand/10">
                {{ __('Point your HTML form action at the path below (POST). Add a honeypot field and optional bot-protection token field `cf-turnstile-response`.') }}
            </div>

            @foreach ($endpoints as $i => $endpoint)
                <div class="space-y-3 rounded-xl border border-brand-ink/10 p-3" wire:key="form-{{ $i }}">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label :value="__('Path')" />
                            <x-text-input wire:model="endpoints.{{ $i }}.path" type="text" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                        </div>
                        <div>
                            <x-input-label :value="__('Email to')" />
                            <x-text-input wire:model="endpoints.{{ $i }}.to_email" type="email" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label :value="__('Honeypot field')" />
                            <x-text-input wire:model="endpoints.{{ $i }}.honeypot" type="text" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                        </div>
                        <label class="flex items-center gap-2 pt-6 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="endpoints.{{ $i }}.require_turnstile" class="rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                            {{ __('Require bot check') }}
                        </label>
                    </div>
                    @if (count($endpoints) > 1)
                        <button type="button" wire:click="removeEndpoint({{ $i }})" class="text-xs font-semibold text-red-600">{{ __('Remove') }}</button>
                    @endif
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="addEndpoint" class="text-sm font-semibold text-brand-sage" @disabled(! $managedDelivery)>{{ __('Add endpoint') }}</button>
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>
</div>
