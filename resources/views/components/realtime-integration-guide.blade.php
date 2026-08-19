@props([
    /** @var \App\Modules\Realtime\Models\RealtimeApp The app whose credentials fill the snippets. */
    'app',
])

{{--
    The secret is admin-only — same gate as the Credentials panel
    (`can('update', $organization)`). View-only members can open /realtime
    but must not receive PUSHER_APP_SECRET / X-Dply-Secret in HTML.
--}}
@can('update', $app->organization)
@php
    /*
     * Snippets are filled in with the app's real credentials so they are
     * copy-and-run rather than placeholders to hunt down. Only org admins
     * reach this block; do not interpolate app_secret outside it.
     */
    $envSnippet = <<<ENV
    BROADCAST_CONNECTION=pusher

    PUSHER_APP_ID={$app->id}
    PUSHER_APP_KEY={$app->app_key}
    PUSHER_APP_SECRET={$app->app_secret}
    PUSHER_HOST={$app->host()}
    PUSHER_PORT=443
    PUSHER_SCHEME=https
    PUSHER_APP_CLUSTER=mt1

    VITE_PUSHER_APP_KEY="\${PUSHER_APP_KEY}"
    VITE_PUSHER_HOST="\${PUSHER_HOST}"
    VITE_PUSHER_PORT="\${PUSHER_PORT}"
    VITE_PUSHER_SCHEME="\${PUSHER_SCHEME}"
    VITE_PUSHER_APP_CLUSTER="\${PUSHER_APP_CLUSTER}"
    ENV;

    $serverSnippet = <<<'PHP'
    // app/Events/OrderShipped.php
    class OrderShipped implements ShouldBroadcast
    {
        public function __construct(public Order $order) {}

        public function broadcastOn(): Channel
        {
            return new Channel('orders');
        }

        public function broadcastAs(): string
        {
            return 'order.shipped';
        }
    }

    // Anywhere in your app:
    OrderShipped::dispatch($order);
    PHP;

    $clientSnippet = <<<'JS'
    // resources/js/echo.js
    import Echo from 'laravel-echo';
    import Pusher from 'pusher-js';

    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        wsHost: import.meta.env.VITE_PUSHER_HOST,
        wssPort: 443,
        forceTLS: true,
        enabledTransports: ['ws', 'wss'],
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    });

    // Note the leading dot: it tells Echo the name is already fully
    // qualified (broadcastAs) rather than a class it should namespace.
    window.Echo.channel('orders')
        .listen('.order.shipped', (e) => console.log(e));
    JS;

    $curlSnippet = <<<CURL
    curl -X POST {$app->publishEndpoint()} \\
      -H 'Content-Type: application/json' \\
      -H 'X-Dply-Key: {$app->app_key}' \\
      -H 'X-Dply-Secret: {$app->app_secret}' \\
      -d '{"name":"order.shipped","channel":"orders","data":{"id":42}}'
    CURL;

    $guideTabs = [
        [
            'key' => 'env',
            'label' => __('1. Configure'),
            'lang' => 'env',
            'code' => $envSnippet,
            'hint' => __('Drop these into your site’s .env. dply injects the same values automatically when you attach this app to a site from its Resources tab.'),
        ],
        [
            'key' => 'server',
            'label' => __('2. Broadcast'),
            'lang' => 'php',
            'code' => $serverSnippet,
            'hint' => __('Any Laravel event implementing ShouldBroadcast now goes through the relay — no code change beyond the config above.'),
        ],
        [
            'key' => 'client',
            'label' => __('3. Listen'),
            'lang' => 'js',
            'code' => $clientSnippet,
            'hint' => __('Standard laravel-echo with pusher-js. Point wsHost at the relay and everything else is unchanged.'),
        ],
        [
            'key' => 'curl',
            'label' => __('Publish directly'),
            'lang' => 'bash',
            'code' => $curlSnippet,
            'hint' => __('For non-Laravel services. The relay also accepts a standard Pusher REST signature if you already have a Pusher SDK wired up.'),
        ],
    ];
@endphp

<section {{ $attributes->class(['border-b border-brand-ink/10 px-5 py-5 sm:px-6']) }} x-data="{ tab: 'env' }">
    <h3 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
        <x-heroicon-o-code-bracket class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
        {{ __('Wire it into your app') }}
    </h3>
    <p class="mt-1 max-w-2xl text-xs leading-relaxed text-brand-moss">
        {{ __('Filled in with :app’s real credentials — copy and run. The relay is Pusher-protocol compatible, so any Pusher or Reverb client works unchanged.', ['app' => $app->name]) }}
    </p>

    <div class="mt-4 flex flex-wrap gap-1.5">
        @foreach ($guideTabs as $guideTab)
            <button
                type="button"
                x-on:click="tab = @js($guideTab['key'])"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                :class="tab === @js($guideTab['key'])
                    ? 'bg-brand-ink text-brand-cream'
                    : 'bg-brand-sand/40 text-brand-moss hover:bg-brand-sand/70'"
            >{{ $guideTab['label'] }}</button>
        @endforeach
    </div>

    @foreach ($guideTabs as $guideTab)
        <div x-show="tab === @js($guideTab['key'])" x-cloak class="mt-3">
            <div
                class="overflow-hidden rounded-xl bg-brand-ink ring-1 ring-inset ring-brand-ink/20"
                x-data="{ copied: false, async copyVal() { try { await navigator.clipboard.writeText(@js($guideTab['code'])); this.copied = true; setTimeout(() => this.copied = false, 1400); } catch (e) {} } }"
            >
                <div class="flex items-center justify-between gap-2 border-b border-brand-cream/10 px-3 py-2">
                    <span class="font-mono text-2xs uppercase tracking-[0.14em] text-brand-cream/40">{{ $guideTab['lang'] }}</span>
                    <button type="button" x-on:click="copyVal()" class="inline-flex items-center gap-1 text-2xs font-semibold text-brand-cream/50 hover:text-brand-cream">
                        <span x-show="! copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak class="text-brand-sage">{{ __('Copied') }}</span>
                    </button>
                </div>
                {{-- Long lines scroll inside the block rather than pushing the
                     page sideways. --}}
                <pre class="overflow-x-auto px-3 py-3 font-mono text-2xs leading-relaxed text-brand-cream/85"><code>{{ $guideTab['code'] }}</code></pre>
            </div>
            <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ $guideTab['hint'] }}</p>
        </div>
    @endforeach
</section>
@endcan
