<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-forms',
            'what' => __('Edge Forms turns a path on your site into a mail-backed form endpoint — visitors POST, Dply emails you the fields. No app server or serverless function required.'),
            'steps' => [
                __('Add an endpoint path (e.g. /api/contact) and the inbox that should receive submissions.'),
                __('In your HTML, set form method="POST" action="https://your-site…/api/contact" (same path).'),
                __('Include a hidden honeypot input (name must match below). Bots that fill it are discarded.'),
                __('Optional: enable Bot protection and check “Require bot check”, then add the challenge token field to the form.'),
            ],
            'tips' => [
                __('Paths are matched on your Edge hostname after Save republishes delivery.'),
                __('Use one endpoint per form. Remove unused endpoints so they stop accepting mail.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable Edge forms') }}</span>
            </label>

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
