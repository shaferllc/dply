@props([
    /** @var \App\Modules\Realtime\Models\RealtimeApp The app the console talks to. */
    'app',
    /** @var \Illuminate\Support\Collection|null Selectable active apps; omit to hide the picker. */
    'apps' => null,
    /** @var bool Whether the viewer may publish. */
    'canManage' => false,
    /** @var string Initial channel — mirrors the host component's $demoChannel. */
    'channel' => 'demo-channel',
])

{{--
    Live round-trip proof for a realtime app.

    Opens a WebSocket from the browser to the relay, subscribes, and lets the
    operator push a signed event through the control plane. If the frame comes
    back, the app works end to end — which is a much cheaper thing to establish
    here than halfway through wiring up a client.

    Shared by the org console and the per-app page so the two cannot drift. Both
    hosts must expose `demoChannel`, `demoEvent`, `demoMessage` and a
    `publishDemoEvent()` action; the `wire:model` bindings below resolve against
    whichever Livewire component renders this.
--}}
<section
    {{ $attributes->class(['border-b border-brand-ink/10 px-5 py-5 sm:px-6']) }}
    x-data="dplyRealtimeConsole({
        host: @js($app->host()),
        appKey: @js($app->app_key),
        channel: @js($channel),
    })"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                {{ __('Try it live') }}
            </h3>
            <p class="mt-1 max-w-2xl text-xs leading-relaxed text-brand-moss">
                {{ __('Opens a WebSocket from this page to :app and subscribes to a channel. Publishing sends a signed event through the relay — if it comes back in the log, your app is working end to end.', ['app' => $app->name]) }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            {{-- Connection state with a live dot, so a dropped socket is visible
                 without reading the log. --}}
            <span
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
                :class="{
                    'bg-brand-sand/55 text-brand-moss ring-brand-ink/10': state === 'idle' || state === 'closed',
                    'bg-amber-100 text-amber-700 ring-amber-200': state === 'connecting' || state === 'connected',
                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/25': state === 'subscribed',
                    'bg-red-100 text-red-700 ring-red-200': state === 'error',
                }"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-current" :class="state === 'subscribed' && 'animate-pulse'"></span>
                <span x-text="statusLabel"></span>
            </span>
            <x-secondary-button type="button" x-on:click="toggle()" class="text-xs">
                <span x-text="connected || state === 'connecting' ? @js(__('Disconnect')) : @js(__('Connect'))"></span>
            </x-secondary-button>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <div class="space-y-3">
            @if ($apps !== null)
                <div>
                    <label for="demo-app" class="block text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('App') }}</label>
                    <select id="demo-app" wire:model.live="demoAppId"
                        class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                        @foreach ($apps as $selectable)
                            <option value="{{ $selectable->id }}">{{ $selectable->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="demo-channel" class="block text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Channel') }}</label>
                    {{-- x-model keeps the socket's subscription in step with the
                         field; wire:model keeps the server's publish target in
                         step. Both are needed — they are two different consumers. --}}
                    <input id="demo-channel" type="text" wire:model.live.debounce.500ms="demoChannel" x-model="channel"
                        class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                </div>
                <div>
                    <label for="demo-event" class="block text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Event') }}</label>
                    <input id="demo-event" type="text" wire:model="demoEvent"
                        class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                </div>
            </div>

            <div>
                <label for="demo-message" class="block text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Payload message') }}</label>
                <input id="demo-message" type="text" wire:model="demoMessage"
                    class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
            </div>

            @if ($canManage)
                <x-primary-button type="button" wire:click="publishDemoEvent" wire:loading.attr="disabled" wire:target="publishDemoEvent" class="w-full justify-center">
                    <span wire:loading.remove wire:target="publishDemoEvent" class="inline-flex items-center gap-2">
                        <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Publish event') }}
                    </span>
                    <span wire:loading wire:target="publishDemoEvent" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Publishing…') }}</span>
                </x-primary-button>
                {{-- Publishing with nothing listening is valid but looks like a
                     failure, so say what will happen before they click. --}}
                <p x-show="! connected" x-cloak class="text-2xs text-brand-mist">
                    {{ __('Connect first, or the event will be published with nothing subscribed to receive it.') }}
                </p>
            @endif
        </div>

        {{-- Frame log --}}
        <div class="min-w-0 overflow-hidden rounded-xl bg-brand-ink ring-1 ring-inset ring-brand-ink/20">
            <div class="flex items-center justify-between gap-2 border-b border-brand-cream/10 px-3 py-2">
                <div class="flex items-center gap-2">
                    <x-mac-window-dots />
                    <span class="font-mono text-2xs text-brand-cream/50">{{ $app->host() }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="font-mono text-2xs text-brand-cream/40" x-text="`${received} received`"></span>
                    <button type="button" x-on:click="clear()" class="text-2xs font-semibold text-brand-cream/50 hover:text-brand-cream">{{ __('Clear') }}</button>
                </div>
            </div>
            <div class="h-64 overflow-y-auto px-3 py-2 font-mono text-2xs leading-relaxed">
                <template x-if="log.length === 0">
                    <p class="py-8 text-center text-brand-cream/35">{{ __('Nothing yet — hit Connect, then publish an event.') }}</p>
                </template>
                <template x-for="entry in log" :key="entry.id">
                    <div class="flex gap-2 border-b border-brand-cream/5 py-1 last:border-0">
                        <span class="shrink-0 text-brand-cream/30" x-text="entry.at"></span>
                        <span
                            class="shrink-0 font-semibold"
                            :class="{
                                'text-brand-sage': entry.kind === 'event',
                                'text-brand-cream/50': entry.kind === 'system' || entry.kind === 'open',
                                'text-brand-gold': entry.kind === 'close',
                                'text-brand-rust': entry.kind === 'error',
                            }"
                            x-text="entry.event ?? entry.kind"
                        ></span>
                        <span class="min-w-0 break-all text-brand-cream/75" x-text="entry.message"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</section>
