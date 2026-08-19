@php
    use App\Modules\Realtime\Models\RealtimeApp;

    $statusTone = [
        RealtimeApp::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        RealtimeApp::STATUS_PROVISIONING => 'bg-amber-100 text-amber-700 ring-amber-200',
        RealtimeApp::STATUS_PAUSED => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        RealtimeApp::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);

    $isActive = $app->status === RealtimeApp::STATUS_ACTIVE;
    $isPaused = $app->status === RealtimeApp::STATUS_PAUSED;

    $cap = max(1, $app->maxConnections());
    $peak = (int) ($app->peak_connections ?? 0);
    $peakPercent = min(100, (int) round($peak / $cap * 100));
    $headroom = max(0, $cap - $peak);

    // The dial tracks LIVE connections, not the peak: this page is the place an
    // operator watches an app right now. Peak gets its own readout below, where
    // it is honestly labelled as a high-water mark.
    $live = $liveConnections;
    $livePercent = $live !== null ? min(100, (int) round($live / $cap * 100)) : 0;

    // A real connection count can still round to 0%, and an empty arc reads as
    // "failed to load" rather than "barely used". Give any non-zero count a
    // visible sliver; the label keeps the true figure.
    $dialPercent = ($live ?? 0) > 0 ? max(2, $livePercent) : 0;

    $dialTone = match (true) {
        ! $isActive => 'text-brand-mist',
        $livePercent >= 90 => 'text-brand-rust',
        $livePercent >= 70 => 'text-brand-gold',
        default => 'text-brand-sage',
    };

    // Stats older than a day mean the number on screen is about the past.
    $isStale = $isActive && ($app->last_stats_at === null || $app->last_stats_at->lt(now()->subHours(24)));

    // Session sparkline. There is no stats history table — the relay reports a
    // current count and a high-water mark, nothing more — so this is drawn from
    // samples collected while the page has been open, and labelled as such.
    $sparkPoints = '';
    $sparkMax = 0;
    if (count($samples) > 1) {
        $sparkMax = max(1, max(array_column($samples, 'connections')));
        $step = 100 / (count($samples) - 1);
        $sparkPoints = implode(' ', array_map(
            fn (int $i, array $s): string => round($i * $step, 2).','.round(30 - ($s['connections'] / $sparkMax * 28), 2),
            array_keys($samples),
            $samples,
        ));
    }

    // Credential rows (secret masked + copyable) are built only for org
    // admins so view-only members never receive app_secret in HTML.
    $credentials = $canManage ? [
        ['label' => __('Host'), 'value' => $app->host(), 'secret' => false],
        ['label' => __('App ID'), 'value' => (string) $app->id, 'secret' => false],
        ['label' => __('App key'), 'value' => (string) $app->app_key, 'secret' => false],
        ['label' => __('App secret'), 'value' => (string) $app->app_secret, 'secret' => true],
        ['label' => __('WebSocket URL'), 'value' => $app->websocketUrl(), 'secret' => false],
        ['label' => __('Publish endpoint'), 'value' => $app->publishEndpoint(), 'secret' => false],
    ] : [];
@endphp

<div class="contents">
    <x-workspace-nav />

    {{-- Scoped motion, matching the org console: the dial draws itself and the
         emitting rings give a live app a pulse. --}}
    @verbatim
        <style>
            /* --dial-full is the full circumference, set inline on the arc. */
            @keyframes dply-dial-draw { from { stroke-dashoffset: var(--dial-full); } }
            @keyframes dply-meter-fill { from { width: 0; } to { width: var(--meter-w); } }
            @keyframes dply-ping-out {
                0%   { transform: scale(.82); opacity: .7; }
                70%  { transform: scale(1.28); opacity: 0; }
                100% { transform: scale(1.28); opacity: 0; }
            }
            .dply-dial { animation: dply-dial-draw 1s cubic-bezier(.16,1,.3,1) both; }
            .dply-meter { width: var(--meter-w); animation: dply-meter-fill .7s cubic-bezier(.16,1,.3,1) both; }
            .dply-ping { animation: dply-ping-out 3.2s cubic-bezier(0,0,.2,1) infinite; }
            .dply-ping-2 { animation-delay: 1.6s; }
            @media (prefers-reduced-motion: reduce) {
                .dply-dial, .dply-meter, .dply-ping { animation: none; }
                .dply-ping { opacity: 0; }
            }
        </style>
    @endverbatim

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="$breadcrumbs" />

        <x-profile-shell
            dense
            :title="$app->name"
            :description="$app->host()"
            icon="heroicon-o-signal"
        >
            <x-slot:actions>
                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$app->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                    @if ($app->status === RealtimeApp::STATUS_PROVISIONING)
                        <x-spinner size="sm" />
                    @endif
                    {{ ucfirst($app->status) }}
                </span>
                @if ($canManage)
                    <x-secondary-button type="button" wire:click="startRename" class="text-xs">
                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Rename') }}
                    </x-secondary-button>
                    {{-- Pause is the honest alternative to delete-and-recreate:
                         it closes the relay door and stops the charge without
                         throwing away the credentials every client is holding. --}}
                    @if ($isActive || $isPaused)
                        <x-secondary-button type="button" wire:click="togglePause" wire:loading.attr="disabled" wire:target="togglePause" class="text-xs">
                            @if ($isPaused)
                                <x-heroicon-o-play class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Resume') }}
                            @else
                                <x-heroicon-o-pause class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Pause') }}
                            @endif
                        </x-secondary-button>
                    @endif
                    <x-secondary-button type="button" wire:click="startTierChange" class="text-xs">
                        <x-heroicon-o-arrows-up-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Change tier') }}
                    </x-secondary-button>
                    <button type="button" wire:click="confirmDelete" class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 hover:text-red-700">
                        <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Delete') }}
                    </button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                {{-- The app console. Live connections against this app's own cap,
                     the number that decides whether clients are being served or
                     refused, on an inverted band so it lands before anything
                     else on the page. --}}
                {{-- wire:init pulls the first reading straight after paint rather
                     than blocking mount on a call to the relay; wire:poll keeps
                     it fresh from there. Without the init the dial would sit on
                     an em dash for the first 30 seconds. --}}
                <div
                    class="relative overflow-hidden bg-brand-ink text-brand-cream"
                    @if (in_array($app->status, [RealtimeApp::STATUS_ACTIVE, RealtimeApp::STATUS_PROVISIONING], true))
                        wire:init="pollStats" wire:poll.30s="pollStats"
                    @endif
                >
                    <div class="pointer-events-none absolute -right-20 -top-28 h-72 w-72 rounded-full bg-brand-sage/25 blur-3xl" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute -left-24 -bottom-24 h-64 w-64 rounded-full bg-brand-gold/15 blur-3xl" aria-hidden="true"></div>

                    <div class="relative grid gap-6 px-4 py-5 sm:px-6 sm:py-6 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,22rem)] lg:items-center lg:gap-8">
                        <div class="flex items-center gap-4 sm:gap-5">
                            <div class="relative shrink-0">
                                @if ($isActive)
                                    <span class="dply-ping pointer-events-none absolute inset-0 rounded-full ring-1 ring-brand-sage/30" aria-hidden="true"></span>
                                    <span class="dply-ping dply-ping-2 pointer-events-none absolute inset-0 rounded-full ring-1 ring-brand-sage/20" aria-hidden="true"></span>
                                @endif
                                <svg viewBox="0 0 120 120" class="relative h-24 w-24 -rotate-90 sm:h-28 sm:w-28" aria-hidden="true">
                                    <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" class="text-brand-cream/12" />
                                    <circle
                                        cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
                                        class="dply-dial {{ $dialTone }}"
                                        stroke-dasharray="326.726"
                                        stroke-dashoffset="{{ 326.726 * (1 - $dialPercent / 100) }}"
                                        style="--dial-full: 326.726"
                                    />
                                </svg>
                                <div class="absolute inset-0 flex flex-col items-center justify-center">
                                    <span class="font-mono text-2xl font-semibold tabular-nums leading-none text-brand-cream sm:text-[28px]">{{ $live !== null ? number_format($live) : '—' }}</span>
                                    <span class="mt-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-cream/45">{{ __('live now') }}</span>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Connections') }}</p>
                                <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                    @if ($isPaused)
                                        {{ __('Paused — connections refused.') }}
                                    @elseif (! $isActive)
                                        {{ __('Not relaying.') }}
                                    @elseif ($live === null)
                                        {{ __('Waiting on the first reading.') }}
                                    @elseif ($livePercent >= 90)
                                        {{ __('At :pct% of the cap.', ['pct' => $livePercent]) }}
                                    @else
                                        {{ trans_choice(':count of :cap slots in use|:count of :cap slots in use', $live, ['count' => number_format($live), 'cap' => number_format($cap)]) }}
                                    @endif
                                </p>
                                <p class="mt-1 text-xs text-brand-cream/55">
                                    {{ __(':tier tier · :price/mo · :cap connection cap', [
                                        'tier' => $tier['label'],
                                        'price' => $money($app->priceCents()),
                                        'cap' => number_format($cap),
                                    ]) }}
                                </p>
                                @if ($isStale)
                                    <p class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-brand-gold/15 px-2 py-0.5 text-2xs font-semibold text-brand-gold ring-1 ring-inset ring-brand-gold/25">
                                        <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ __('The relay has not reported in over a day') }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4 lg:grid-cols-2">
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Peak') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($peak) }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __(':pct% of cap', ['pct' => $peakPercent]) }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Headroom') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($headroom) }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('before the cap') }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Sites') }}</dt>
                                <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($sites->count()) }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('broadcasting here') }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Last report') }}</dt>
                                <dd class="mt-1.5 text-sm font-semibold text-brand-cream">{{ $app->last_stats_at?->diffForHumans(short: true) ?? __('never') }}</dd>
                                <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('relay stats') }}</dd>
                            </div>
                        </dl>

                        {{-- Session sparkline. Explicitly "this session" — there is
                             no persisted history, and drawing one would mean
                             charting data nobody recorded. --}}
                        <div class="min-w-0">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('This session') }}</p>
                                <span class="font-mono text-2xs text-brand-cream/40">{{ trans_choice(':count sample|:count samples', count($samples), ['count' => count($samples)]) }}</span>
                            </div>
                            @if ($sparkPoints !== '')
                                <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="mt-3 h-16 w-full" aria-hidden="true">
                                    <polyline
                                        points="{{ $sparkPoints }}"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        vector-effect="non-scaling-stroke"
                                        class="text-brand-sage"
                                    />
                                </svg>
                                <p class="mt-1 flex items-baseline justify-between text-2xs text-brand-cream/40">
                                    <span>{{ $samples[0]['at'] }}</span>
                                    <span>{{ __('max :n', ['n' => number_format($sparkMax)]) }}</span>
                                    <span>{{ $samples[count($samples) - 1]['at'] }}</span>
                                </p>
                            @else
                                <p class="mt-3 text-xs text-brand-cream/45">
                                    {{ $isActive
                                        ? __('Collecting — the page samples every 30 seconds while it is open.')
                                        : __('Nothing to sample while the app is not relaying.') }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </x-slot:stats>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-livewire-validation-errors />
                </div>
            @endif

            @if ($app->status === RealtimeApp::STATUS_FAILED && $app->error_message)
                <div class="border-b border-brand-ink/10 bg-red-50 px-5 py-3 text-xs text-red-700 sm:px-6">{{ $app->error_message }}</div>
            @endif

            {{-- Inline rename, only rendered while the editor is open so the page
                 does not carry a form nobody asked for. --}}
            @if ($editingName && $canManage)
                <section class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                    <form wire:submit="saveName" class="flex flex-wrap items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <label for="edit-name" class="block text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('App name') }}</label>
                            <input id="edit-name" type="text" wire:model="editName" autofocus
                                class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                        </div>
                        <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="cancelRename">{{ __('Cancel') }}</x-secondary-button>
                    </form>
                    <p class="mt-2 text-2xs text-brand-mist">{{ __('A label in dply only — the relay keys off the app ID and public key, so nothing reconnects.') }}</p>
                </section>
            @endif

            {{-- Peak utilisation + the controls that act on it. --}}
            <section class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Capacity') }}</h3>
                    <div class="flex items-center gap-2">
                        @if ($canManage)
                            {{-- A one-off spike (a load test, a deploy loop) skews
                                 every headroom reading until someone clears it. --}}
                            <button type="button" wire:click="resetPeak" wire:loading.attr="disabled" wire:target="resetPeak"
                                class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" /> {{ __('Reset peak') }}
                            </button>
                        @endif
                        <button type="button" wire:click="refreshStats" wire:loading.attr="disabled" wire:target="refreshStats"
                            class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5 text-brand-forest" wire:loading.class="animate-spin" wire:target="refreshStats" /> {{ __('Refresh') }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <div>
                        <div class="flex items-baseline justify-between text-xs">
                            <span class="text-brand-moss">{{ __('Live now') }}</span>
                            <span class="font-mono tabular-nums text-brand-ink">{{ $live !== null ? number_format($live) : '—' }} / {{ number_format($cap) }}</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-brand-sand/60">
                            <div class="dply-meter h-full rounded-full {{ $livePercent >= 90 ? 'bg-brand-rust' : ($livePercent >= 70 ? 'bg-brand-gold' : 'bg-brand-forest') }}"
                                style="--meter-w: {{ $live !== null && $live > 0 ? max(2, $livePercent) : 0 }}%"></div>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-baseline justify-between text-xs">
                            <span class="text-brand-moss">{{ __('Peak since last reset') }}</span>
                            <span class="font-mono tabular-nums text-brand-ink">{{ number_format($peak) }} / {{ number_format($cap) }} <span class="text-brand-mist">({{ $peakPercent }}%)</span></span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-brand-sand/60">
                            <div class="dply-meter h-full rounded-full {{ $peakPercent >= 90 ? 'bg-brand-rust' : ($peakPercent >= 70 ? 'bg-brand-gold' : 'bg-brand-sage') }}"
                                style="--meter-w: {{ $peak > 0 ? max(2, $peakPercent) : 0 }}%"></div>
                        </div>
                    </div>
                </div>

                @if ($peakPercent >= 90)
                    <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 ring-1 ring-inset ring-red-100">
                        {{ __('Near the tier cap — connections beyond :cap are rejected outright. Move up a tier before the next spike.', ['cap' => number_format($cap)]) }}
                    </p>
                @endif

                <p class="mt-3 text-xs text-brand-moss">
                    @if ($app->last_stats_at)
                        {{ __('Updated :when', ['when' => $app->last_stats_at->diffForHumans()]) }}
                    @else
                        {{ __('Never measured — hit Refresh to pull current usage.') }}
                    @endif
                </p>
            </section>

            {{-- App metadata + billing contribution. --}}
            <section class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Details') }}</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Status') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">{{ ucfirst($app->status) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Monthly charge') }}</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-brand-forest">
                            {{ $app->isBillable() ? $money($app->priceCents()).'/'.__('mo') : __('Not billed while :status', ['status' => $app->status]) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Tier') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">{{ $tier['label'] }} <span class="text-brand-mist">({{ number_format($cap) }} {{ __('connections') }})</span></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Backend') }}</dt>
                        <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $app->backend }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Created') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">{{ $app->created_at?->diffForHumans() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Attached sites') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">{{ number_format($sites->count()) }}</dd>
                    </div>
                </dl>
            </section>

            {{-- Credentials --}}
            @if ($canManage)
                <section class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Credentials') }}</h3>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Injected into a site at deploy as PUSHER_* / VITE_PUSHER_* when this app is attached.') }}</p>
                        </div>
                        <x-secondary-button type="button" wire:click="confirmRotate" class="text-xs">
                            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Rotate') }}
                        </x-secondary-button>
                    </div>
                    <dl class="mt-4 space-y-2.5">
                        @foreach ($credentials as $cred)
                            <div class="flex items-center justify-between gap-3"
                                x-data="{ show: {{ $cred['secret'] ? 'false' : 'true' }}, copied: false,
                                    async copyVal() { try { await navigator.clipboard.writeText(@js($cred['value'])); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }">
                                <dt class="shrink-0 text-xs font-medium text-brand-moss">{{ $cred['label'] }}</dt>
                                <dd class="flex min-w-0 items-center gap-2">
                                    <span class="truncate font-mono text-xs text-brand-ink">
                                        <span x-show="show" x-cloak>{{ $cred['value'] === '' ? '—' : $cred['value'] }}</span>
                                        <span x-show="! show">••••••••••••</span>
                                    </span>
                                    @if ($cred['secret'])
                                        <button type="button" @click="show = ! show" class="shrink-0 text-xs font-semibold text-brand-sage hover:underline">
                                            <span x-show="! show">{{ __('Show') }}</span><span x-show="show" x-cloak>{{ __('Hide') }}</span>
                                        </button>
                                    @endif
                                    <button type="button" @click="copyVal()" class="shrink-0 text-xs font-semibold text-brand-sage hover:underline">
                                        <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
                                    </button>
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            {{-- Live round-trip proof and the integration guide, shared with the
                 org console so the two surfaces cannot drift. --}}
            @if ($isActive)
                <x-realtime-playground :app="$app" :can-manage="$canManage" :channel="$demoChannel" />
                @if ($canManage)
                    <x-realtime-integration-guide :app="$app" />
                @endif
            @endif

            {{-- Connected sites --}}
            <section class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Connected sites') }}</h3>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @forelse ($sites as $binding)
                        @if ($binding->site && $binding->site->server)
                            <a href="{{ route('sites.show', ['server' => $binding->site->server, 'site' => $binding->site, 'section' => 'resources']) }}" wire:navigate
                                class="inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-2.5 py-1 text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10 hover:bg-brand-sand/70">
                                <x-heroicon-o-globe-alt class="h-3.5 w-3.5 text-brand-moss" /> {{ $binding->site->name }}
                            </a>
                        @elseif ($binding->site)
                            <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-2.5 py-1 text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $binding->site->name }}</span>
                        @endif
                    @empty
                        <span class="inline-flex items-center gap-1.5 text-xs text-brand-mist">
                            <x-heroicon-o-link-slash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Not attached to any site — still billed while active.') }}
                        </span>
                    @endforelse
                </div>
            </section>
        </x-profile-shell>
    </div>

    {{-- Tier-change modal --}}
    <x-modal name="realtime-tier-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        @php
            $currentCents = $app->priceCents();
            $selectedCents = (int) ($tiers[$selectedTier]['price_cents'] ?? $currentCents);
            $isUpgrade = $selectedCents > $currentCents;
        @endphp
        <form wire:submit="changeTier">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Change connection tier') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $app->name }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Currently :tier · :price/mo. Changing tiers adjusts the hard connection cap and this workspace’s bill.', ['tier' => $app->tierConfig()['label'], 'price' => $money($currentCents)]) }}</p>
                </div>
            </div>

            <div class="space-y-4 px-6 py-6">
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($tiers as $slug => $tierOption)
                        @php $optionCap = (int) $tierOption['max_connections']; @endphp
                        <button type="button" wire:click="$set('selectedTier', '{{ $slug }}')" class="rounded-lg border p-3 text-left transition-colors {{ $selectedTier === $slug ? 'border-brand-forest bg-brand-forest/5 ring-1 ring-brand-forest/40' : 'border-brand-ink/10 hover:bg-brand-sand/30' }}">
                            <div class="text-sm font-semibold text-brand-ink">{{ $tierOption['label'] }}</div>
                            <div class="mt-0.5 text-xs text-brand-moss">{{ number_format($optionCap) }} {{ __('connections') }}</div>
                            <div class="mt-1 text-xs font-semibold text-brand-forest">{{ $money((int) $tierOption['price_cents']) }}/{{ __('mo') }}</div>
                            {{-- A tier below the observed peak is a downgrade into
                                 refused connections; flag it at the point of choice. --}}
                            @if ($optionCap < $peak)
                                <div class="mt-1.5 inline-flex items-center gap-1 text-2xs font-semibold text-brand-rust">
                                    <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    {{ __('below your peak of :peak', ['peak' => number_format($peak)]) }}
                                </div>
                            @endif
                        </button>
                    @endforeach
                </div>

                @if ($isUpgrade)
                    <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">
                        <input type="checkbox" wire:model="confirmTierCharge" class="mt-0.5 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                        <span>{{ __('I understand this raises this app’s charge from :from to :to per month on this workspace’s bill.', ['from' => $money($currentCents), 'to' => $money($selectedCents)]) }}</span>
                    </label>
                @elseif ($selectedCents < $currentCents)
                    <p class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">{{ __('Downgrading lowers this app’s charge to :to/mo. The lower connection cap takes effect immediately.', ['to' => $money($selectedCents)]) }}</p>
                @endif
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelTierChange">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="changeTier">
                    <span wire:loading.remove wire:target="changeTier">{{ __('Save tier') }}</span>
                    <span wire:loading wire:target="changeTier" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- Rotate-credentials modal. Not destructive to data, but it disconnects
         every client until the new key ships, which is worth stopping for. --}}
    <x-modal name="realtime-rotate-modal" :show="false" maxWidth="md" overlayClass="bg-brand-ink/30" focusable>
        <div>
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">{{ __('Rotate credentials') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $app->name }}</h2>
                </div>
            </div>
            <div class="space-y-3 px-6 py-6 text-sm leading-6 text-brand-moss">
                <p>{{ __('Issues a new app key and signing secret, and revokes the current pair on the relay immediately. Every connected client is dropped and cannot reconnect until it has the new key.') }}</p>
                @if ($sites->isNotEmpty())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                        {{ __('Redeploy these sites afterwards so they pick up the new credentials:') }}
                        <span class="font-medium">{{ $sites->map(fn ($b) => $b->site?->name)->filter()->join(', ') }}</span>
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelRotate">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="rotateCredentials" wire:loading.attr="disabled" wire:target="rotateCredentials">
                    <span wire:loading.remove wire:target="rotateCredentials">{{ __('Rotate now') }}</span>
                    <span wire:loading wire:target="rotateCredentials" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Rotating…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </x-modal>

    {{-- Delete confirmation modal --}}
    <x-modal name="realtime-delete-modal" :show="false" maxWidth="md" overlayClass="bg-brand-ink/30" focusable>
        <div>
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 ring-1 ring-red-200">
                    <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600">{{ __('Delete broadcasting app') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $app->name }}</h2>
                </div>
            </div>
            <div class="space-y-3 px-6 py-6 text-sm leading-6 text-brand-moss">
                <p>{{ __('This tears the app down on the relay and stops its :price/mo charge on this workspace’s bill. Connections are revoked immediately.', ['price' => $money($app->priceCents())]) }}</p>
                <p class="text-xs text-brand-mist">{{ __('If you only want to stop the charge for a while, Pause does that and keeps the credentials.') }}</p>
                @if ($sites->isNotEmpty())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                        {{ __('Warning: :count site(s) still broadcast through this app and will lose realtime until you point them elsewhere:', ['count' => $sites->count()]) }}
                        <span class="font-medium">{{ $sites->map(fn ($b) => $b->site?->name)->filter()->join(', ') }}</span>
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelDelete">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteApp" wire:loading.attr="disabled" wire:target="deleteApp">
                    <span wire:loading.remove wire:target="deleteApp">{{ __('Delete app') }}</span>
                    <span wire:loading wire:target="deleteApp" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Deleting…') }}</span>
                </x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
