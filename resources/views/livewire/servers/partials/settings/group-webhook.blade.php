@php
    $webhookSecretStored = isset($server->meta['server_event_webhook_url'])
        && is_string($server->meta['server_event_webhook_url'])
        && $server->meta['server_event_webhook_url'] !== ''
        && ! empty($server->meta['server_event_webhook_secret']);
@endphp

<section id="settings-group-webhook" class="space-y-4" aria-labelledby="settings-group-webhook-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-webhook-title',
        'kicker' => __('Integrations'),
        'title' => __('Outbound webhook'),
        'description' => __('If you automate outside Dply, you can register a URL for future server-level events. Payload shape and delivery are not guaranteed yet—treat this as experimental.'),
    ])

    <div id="settings-webhooks" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <form wire:submit="saveServerWebhooks" class="space-y-5">
            <div>
                <x-input-label for="settings-webhook-url" value="{{ __('Webhook URL') }}" />
                <input
                    id="settings-webhook-url"
                    type="url"
                    wire:model="settingsWebhookUrl"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="https://"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsWebhookUrl')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-webhook-secret" value="{{ __('Signing secret') }}" />
                <input
                    id="settings-webhook-secret"
                    type="password"
                    wire:model="settingsWebhookSecret"
                    autocomplete="new-password"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ $webhookSecretStored ? __('Enter a new secret to replace the stored one') : __('Optional') }}"
                    @disabled(! $this->canEditServerSettings)
                />
                @if ($webhookSecretStored)
                    <p class="mt-1 text-xs text-brand-moss">{{ __('A secret is already stored. Submit a new value only if you want to rotate it.') }}</p>
                @endif
                <x-input-error :messages="$errors->get('settingsWebhookSecret')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save webhook') }}</x-primary-button>
                </div>
            @endif
        </form>
    </div>
</section>
