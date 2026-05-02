@php
    $webhookSecretStored = isset($server->meta['server_event_webhook_url'])
        && is_string($server->meta['server_event_webhook_url'])
        && $server->meta['server_event_webhook_url'] !== ''
        && ! empty($server->meta['server_event_webhook_secret']);
    $webhookUrlConfigured = isset($server->meta['server_event_webhook_url'])
        && is_string($server->meta['server_event_webhook_url'])
        && trim($server->meta['server_event_webhook_url']) !== '';
    $statusBadge = function (string $status): array {
        return match ($status) {
            \App\Models\OutboundWebhookDelivery::STATUS_SENT => ['Sent', 'bg-green-100 text-green-900 ring-green-200'],
            \App\Models\OutboundWebhookDelivery::STATUS_PENDING => ['Pending', 'bg-amber-100 text-amber-900 ring-amber-200'],
            \App\Models\OutboundWebhookDelivery::STATUS_FAILED => ['Failed', 'bg-red-100 text-red-900 ring-red-200'],
            \App\Models\OutboundWebhookDelivery::STATUS_WOULD_SEND => ['Would send', 'bg-zinc-100 text-zinc-700 ring-zinc-200'],
            default => [ucfirst($status), 'bg-zinc-100 text-zinc-700 ring-zinc-200'],
        };
    };
@endphp

<section id="settings-group-webhook" class="space-y-6" aria-labelledby="settings-group-webhook-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-webhook-title',
        'kicker' => __('Integrations'),
        'title' => __('Outbound webhook'),
        'description' => __('Register a URL to receive server-scoped events (created, provisioned, health changed, deleted, authorized keys synced, sites created/deleted). Every emitted event is recorded below — even when no URL is set, so you can audit what would be sent before wiring up.'),
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
                @else
                    <p class="mt-1 text-xs text-brand-moss">{{ __('When set, requests include X-Dply-Timestamp and X-Dply-Signature (HMAC-SHA256, format t=<unix>,v1=<hex> over <unix>.<body>). Without a secret, deliveries are unsigned but still recorded.') }}</p>
                @endif
                <x-input-error :messages="$errors->get('settingsWebhookSecret')" class="mt-2" />
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($this->canEditServerSettings)
                    <button
                        type="button"
                        wire:click="sendTestWebhook"
                        wire:loading.attr="disabled"
                        wire:target="sendTestWebhook"
                        class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0 opacity-90" />
                        <span wire:loading.remove wire:target="sendTestWebhook">{{ __('Send test') }}</span>
                        <span wire:loading wire:target="sendTestWebhook">{{ __('Sending…') }}</span>
                    </button>
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save webhook') }}</x-primary-button>
                @endif
            </div>
        </form>
    </div>

    <div class="{{ $card }} scroll-mt-24">
        <div class="flex flex-col gap-1 border-b border-brand-ink/10 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <div>
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Recent deliveries') }}</h2>
                <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                    {{ __('Last 30 outbound webhook attempts for this server. “Would send” rows show the payload that would have fired with no URL configured.') }}
                </p>
            </div>
            <span class="text-xs text-brand-moss">{{ __(':count rows', ['count' => $webhookDeliveries->count()]) }}</span>
        </div>

        @if ($webhookDeliveries->isEmpty())
            <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                {{ __('No webhook deliveries yet. Click “Send test” above to fire one.') }}
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10" x-data="{ openId: null }">
                @foreach ($webhookDeliveries as $delivery)
                    @php
                        [$badgeLabel, $badgeClasses] = $statusBadge($delivery->status);
                        $payloadJson = json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    @endphp
                    <li class="px-6 py-3 sm:px-8" :class="openId === @js($delivery->id) ? 'bg-brand-sand/20' : ''">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 text-left"
                            x-on:click="openId = openId === @js($delivery->id) ? null : @js($delivery->id)"
                        >
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $badgeClasses }}">
                                {{ $badgeLabel }}
                                @if ($delivery->http_status)
                                    <span class="ml-1 font-mono text-[10px] opacity-80">{{ $delivery->http_status }}</span>
                                @endif
                            </span>
                            <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink">{{ $delivery->event_key }}</span>
                            <span class="hidden shrink-0 text-xs text-brand-moss sm:inline">
                                {{ $delivery->created_at?->diffForHumans(short: true) ?? '—' }}
                            </span>
                            <span class="shrink-0 text-brand-mist" :class="openId === @js($delivery->id) ? 'rotate-90' : ''" style="transition: transform 120ms ease;">
                                <x-heroicon-o-chevron-right class="h-4 w-4" />
                            </span>
                        </button>

                        <div x-show="openId === @js($delivery->id)" x-cloak class="mt-3 space-y-3 text-xs">
                            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                                @if ($delivery->summary)
                                    <div class="sm:col-span-2">
                                        <dt class="font-medium text-brand-moss">{{ __('Summary') }}</dt>
                                        <dd class="text-brand-ink">{{ $delivery->summary }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt class="font-medium text-brand-moss">{{ __('URL') }}</dt>
                                    <dd class="break-all font-mono text-brand-ink">{{ $delivery->url ?? __('— not configured at the time —') }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-brand-moss">{{ __('Signed') }}</dt>
                                    <dd class="text-brand-ink">{{ $delivery->signed ? __('Yes (HMAC-SHA256)') : __('No') }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-brand-moss">{{ __('Attempts') }}</dt>
                                    <dd class="text-brand-ink">{{ $delivery->attempt_count }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-brand-moss">{{ __('Created') }}</dt>
                                    <dd class="text-brand-ink">{{ $delivery->created_at?->toIso8601String() }}</dd>
                                </div>
                                @if ($delivery->completed_at)
                                    <div>
                                        <dt class="font-medium text-brand-moss">{{ __('Completed') }}</dt>
                                        <dd class="text-brand-ink">{{ $delivery->completed_at->toIso8601String() }}</dd>
                                    </div>
                                @endif
                                @if ($delivery->error_message)
                                    <div class="sm:col-span-2">
                                        <dt class="font-medium text-red-700">{{ __('Error') }}</dt>
                                        <dd class="text-red-900">{{ $delivery->error_message }}</dd>
                                    </div>
                                @endif
                            </dl>

                            <div>
                                <p class="mb-1 font-medium text-brand-moss">{{ __('Payload') }}</p>
                                <pre class="max-h-64 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-[11px] leading-relaxed text-zinc-100">{{ $payloadJson }}</pre>
                            </div>

                            @if ($delivery->response_excerpt)
                                <div>
                                    <p class="mb-1 font-medium text-brand-moss">{{ __('Response excerpt') }}</p>
                                    <pre class="max-h-48 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-[11px] leading-relaxed text-zinc-100">{{ $delivery->response_excerpt }}</pre>
                                </div>
                            @endif

                            @if ($this->canEditServerSettings && $delivery->url !== null && $delivery->status !== \App\Models\OutboundWebhookDelivery::STATUS_PENDING)
                                <div class="flex justify-end">
                                    <button
                                        type="button"
                                        wire:click="resendWebhookDelivery(@js($delivery->id))"
                                        wire:loading.attr="disabled"
                                        wire:target="resendWebhookDelivery(@js($delivery->id))"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                                    >
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                        {{ __('Resend') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
