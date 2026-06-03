@php
    $statusTone = [
        \App\Models\RealtimeApp::STATUS_ACTIVE => 'success',
        \App\Models\RealtimeApp::STATUS_PROVISIONING => 'warning',
        \App\Models\RealtimeApp::STATUS_FAILED => 'danger',
        \App\Models\RealtimeApp::STATUS_PAUSED => 'neutral',
    ];

    $echoSnippet = <<<JS
    import Echo from 'laravel-echo';
    import Pusher from 'pusher-js';
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: '{$app->app_key}',
        wsHost: '{$app->host()}',
        wsPort: 443,
        wssPort: 443,
        forceTLS: true,
        enabledTransports: ['ws', 'wss'],
        cluster: 'mt1', // ignored by the relay; pusher-js requires a value
    });
    JS;
@endphp

<div class="space-y-8"
     @if ($app->status === \App\Models\RealtimeApp::STATUS_PROVISIONING) wire:poll.3s="refreshStatus" @endif
>
    <x-page-header eyebrow="Realtime" :title="$app->name" :toolbar="true">
        <x-slot name="description">
            <span class="inline-flex items-center gap-2">
                <x-badge :tone="$statusTone[$app->status] ?? 'neutral'" size="sm">{{ ucfirst($app->status) }}</x-badge>
                <span>${{ number_format($priceCents / 100, 2) }} / month while active</span>
            </span>
        </x-slot>
        <x-slot name="actions">
            <div x-data="{ confirming: false }" class="inline-flex">
                <x-danger-button type="button" x-on:click="confirming = true" x-show="! confirming">Delete</x-danger-button>
                <span x-show="confirming" x-cloak class="inline-flex items-center gap-2">
                    <span class="text-sm text-brand-moss">Delete this app?</span>
                    <x-danger-button type="button" wire:click="delete" wire:loading.attr="disabled">Yes, delete</x-danger-button>
                    <x-secondary-button type="button" x-on:click="confirming = false">Cancel</x-secondary-button>
                </span>
            </div>
        </x-slot>
    </x-page-header>

    @if ($app->status === \App\Models\RealtimeApp::STATUS_FAILED && $app->error_message)
        <x-alert tone="danger">
            <p class="font-medium">Provisioning failed</p>
            <p class="mt-1 text-sm">{{ $app->error_message }}</p>
        </x-alert>
    @endif

    @if ($app->status === \App\Models\RealtimeApp::STATUS_PROVISIONING)
        <x-alert tone="info">Provisioning… this page will update automatically.</x-alert>
    @endif

    <x-section-card>
        <div x-data="{
            copy(value) { navigator.clipboard.writeText(value); this.copied = true; setTimeout(() => this.copied = false, 1200); },
            copied: false,
            revealSecret: false,
        }" class="space-y-5">
            <h2 class="text-lg font-semibold text-brand-ink">Credentials</h2>

            @php
                $rows = [
                    ['App ID', $app->id, false],
                    ['App key', $app->app_key, false],
                    ['App secret', $app->app_secret, true],
                    ['WebSocket URL', $app->websocketUrl(), false],
                    ['Publish endpoint', $app->publishEndpoint(), false],
                ];
            @endphp

            <dl class="divide-y divide-brand-ink/5">
                @foreach ($rows as [$label, $value, $secret])
                    <div class="grid grid-cols-1 gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                        <dt class="text-sm font-medium text-brand-moss">{{ $label }}</dt>
                        <dd class="sm:col-span-2">
                            <div class="flex items-center gap-2">
                                @if ($secret)
                                    <code class="flex-1 truncate rounded bg-brand-sand/30 px-2 py-1 font-mono text-xs text-brand-ink" x-show="revealSecret">{{ $value }}</code>
                                    <code class="flex-1 truncate rounded bg-brand-sand/30 px-2 py-1 font-mono text-xs text-brand-ink" x-show="! revealSecret">••••••••••••••••••••</code>
                                    <button type="button" class="text-xs font-medium text-brand-forest hover:underline" x-on:click="revealSecret = ! revealSecret" x-text="revealSecret ? 'Hide' : 'Reveal'"></button>
                                @else
                                    <code class="flex-1 truncate rounded bg-brand-sand/30 px-2 py-1 font-mono text-xs text-brand-ink">{{ $value }}</code>
                                @endif
                                <button type="button" class="text-xs font-medium text-brand-forest hover:underline" x-on:click="copy(@js($value))">Copy</button>
                            </div>
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="text-xs text-brand-moss">Keep the app secret private — it signs channel auth and server publishes. Treat it like an API key.</p>
        </div>
    </x-section-card>

    <x-section-card>
        <div class="space-y-3">
            <h2 class="text-lg font-semibold text-brand-ink">Connect with Laravel Echo</h2>
            <p class="text-sm text-brand-moss">The relay is Pusher-wire-compatible, so the stock <code>pusher</code> broadcaster works. Server-side, point Laravel's <code>pusher</code> broadcast driver at the host above with this key/secret.</p>
            <pre class="overflow-x-auto rounded-xl bg-brand-ink px-4 py-3 text-xs leading-relaxed text-brand-cream"><code>{{ $echoSnippet }}</code></pre>
        </div>
    </x-section-card>
</div>
