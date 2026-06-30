@php
    $statusTone = [
        \App\Modules\Realtime\Models\RealtimeApp::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        \App\Modules\Realtime\Models\RealtimeApp::STATUS_PROVISIONING => 'bg-amber-100 text-amber-700 ring-amber-200',
        \App\Modules\Realtime\Models\RealtimeApp::STATUS_PAUSED => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        \App\Modules\Realtime\Models\RealtimeApp::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);

    // The credential rows shown in the page (secret masked + copyable). Keyed so
    // the Alpine reveal/copy chip below stays generic.
    $credentials = [
        ['label' => __('Host'), 'value' => $app->host(), 'secret' => false],
        ['label' => __('App ID'), 'value' => (string) $app->id, 'secret' => false],
        ['label' => __('App key'), 'value' => (string) $app->app_key, 'secret' => false],
        ['label' => __('App secret'), 'value' => (string) $app->app_secret, 'secret' => true],
        ['label' => __('WebSocket URL'), 'value' => $app->websocketUrl(), 'secret' => false],
        ['label' => __('Publish endpoint'), 'value' => $app->publishEndpoint(), 'secret' => false],
    ];
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="realtime" :breadcrumb="$breadcrumbs">
            <x-livewire-validation-errors />

            {{-- Header: identity, status, tier/price, peak usage.
                 Poll while provisioning — wrapped because @if can't live inside an <x-…> tag. --}}
            <div @if ($app->status === \App\Modules\Realtime\Models\RealtimeApp::STATUS_PROVISIONING) wire:poll.5s @endif>
            <x-hero-card
                icon="signal"
                iconSize="md"
                :title="$app->name"
                :description="$app->host()"
            >
                <x-slot:topAction>
                    <div class="text-right">
                        <p class="text-sm font-semibold text-brand-forest">{{ $tier['label'] }} · {{ $money($app->priceCents()) }}/{{ __('mo') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ number_format((int) ($app->peak_connections ?? 0)) }} / {{ number_format($tier['max_connections']) }} {{ __('peak conns') }}
                        </p>
                        @if ($canManage)
                            <div class="mt-3 flex items-center justify-end gap-2">
                                <x-secondary-button type="button" wire:click="startTierChange" class="text-xs">
                                    <x-heroicon-o-arrows-up-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Change tier') }}
                                </x-secondary-button>
                                <button type="button" wire:click="confirmDelete" class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 hover:text-red-700">
                                    <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        @endif
                    </div>
                </x-slot:topAction>

                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $statusTone[$app->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                    {{ ucfirst($app->status) }}
                </span>
                @if ($app->status === \App\Modules\Realtime\Models\RealtimeApp::STATUS_FAILED && $app->error_message)
                    <p class="rounded-md bg-red-50 px-2 py-1 text-xs text-red-700">{{ $app->error_message }}</p>
                @endif
            </x-hero-card>
            </div>

            {{-- Live stats: polls the relay while active so the current connection
                 count stays fresh; the peak high-water mark is persisted. --}}
            <section class="dply-card mt-6 p-5 sm:p-6"
                @if (in_array($app->status, [\App\Modules\Realtime\Models\RealtimeApp::STATUS_ACTIVE, \App\Modules\Realtime\Models\RealtimeApp::STATUS_PROVISIONING], true)) wire:poll.30s="pollStats" @endif>
                @php
                    $cap = max(1, (int) $tier['max_connections']);
                    $peak = (int) ($app->peak_connections ?? 0);
                    $util = min(100, (int) round($peak / $cap * 100));
                    $headroom = max(0, $cap - $peak);
                @endphp
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Live stats') }}</h3>
                    <button type="button" wire:click="refreshStats" wire:loading.attr="disabled" wire:target="refreshStats"
                        class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5 text-brand-forest" wire:loading.class="animate-spin" wire:target="refreshStats" /> {{ __('Refresh') }}
                    </button>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-4">
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Live now') }}</p>
                        <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $liveConnections !== null ? number_format($liveConnections) : '—' }}</p>
                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('current connections') }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Peak') }}</p>
                        <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ number_format($peak) }}</p>
                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('since last reset') }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Tier cap') }}</p>
                        <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ number_format($cap) }}</p>
                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ $tier['label'] }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Headroom') }}</p>
                        <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ number_format($headroom) }}</p>
                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('before the cap') }}</p>
                    </div>
                </div>

                {{-- Peak vs. tier-cap utilization. --}}
                <div class="mt-4">
                    <div class="flex items-center justify-between text-[11px] text-brand-moss">
                        <span>{{ __('Peak utilization') }}</span>
                        <span class="font-semibold {{ $util >= 90 ? 'text-red-600' : ($util >= 75 ? 'text-amber-600' : 'text-brand-forest') }}">{{ $util }}%</span>
                    </div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-brand-sand/60">
                        <div class="h-full rounded-full {{ $util >= 90 ? 'bg-red-500' : ($util >= 75 ? 'bg-amber-500' : 'bg-brand-forest') }}" style="width: {{ max(2, $util) }}%"></div>
                    </div>
                    @if ($util >= 90)
                        <p class="mt-1.5 text-[11px] text-red-600">{{ __('Near the tier cap — connections beyond it are rejected. Consider a higher tier.') }}</p>
                    @endif
                </div>

                <p class="mt-3 text-xs text-brand-moss">
                    @if ($app->last_stats_at)
                        {{ __('Updated :when', ['when' => $app->last_stats_at->diffForHumans()]) }}
                    @else
                        {{ __('Never measured — hit Refresh to pull current usage.') }}
                    @endif
                </p>
            </section>

            {{-- App metadata + billing contribution. --}}
            <section class="dply-card mt-6 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Details') }}</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Status') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">{{ ucfirst($app->status) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Monthly charge') }}</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-brand-forest">{{ $money($app->priceCents()) }}/{{ __('mo') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Tier') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">{{ $tier['label'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Backend') }}</dt>
                        <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $app->backend }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Created') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">{{ $app->created_at?->diffForHumans() }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Attached sites') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">{{ number_format($sites->count()) }}</dd>
                    </div>
                </dl>
            </section>

            <div class="mt-6 grid gap-6 lg:grid-cols-3">
                {{-- Credentials --}}
                @if ($canManage)
                    <section class="dply-card p-5 sm:p-6 lg:col-span-3">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Credentials') }}</h3>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Injected into a site at deploy as PUSHER_* / VITE_PUSHER_* when this app is attached.') }}</p>
                        <dl class="mt-4 space-y-2.5">
                            @foreach ($credentials as $cred)
                                <div class="flex items-center justify-between gap-3"
                                    x-data="{ show: {{ $cred['secret'] ? 'false' : 'true' }}, copied: false,
                                        async copyVal() { try { await navigator.clipboard.writeText(@js($cred['value'])); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }">
                                    <dt class="shrink-0 text-xs font-medium text-brand-moss">{{ $cred['label'] }}</dt>
                                    <dd class="flex min-w-0 items-center gap-2">
                                        <span class="truncate font-mono text-[11px] text-brand-ink">
                                            <span x-show="show" x-cloak>{{ $cred['value'] === '' ? '—' : $cred['value'] }}</span>
                                            <span x-show="! show">••••••••••••</span>
                                        </span>
                                        @if ($cred['secret'])
                                            <button type="button" @click="show = ! show" class="shrink-0 text-[11px] font-semibold text-brand-sage hover:underline">
                                                <span x-show="! show">{{ __('Show') }}</span><span x-show="show" x-cloak>{{ __('Hide') }}</span>
                                            </button>
                                        @endif
                                        <button type="button" @click="copyVal()" class="shrink-0 text-[11px] font-semibold text-brand-sage hover:underline">
                                            <span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span>
                                        </button>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif
            </div>

            {{-- Connected sites --}}
            <section class="dply-card mt-6 p-5 sm:p-6">
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
                        <span class="text-xs text-brand-moss">{{ __('Not attached to any site.') }}</span>
                    @endforelse
                </div>
            </section>
        </x-organization-shell>
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
                        <button type="button" wire:click="$set('selectedTier', '{{ $slug }}')" class="rounded-lg border p-3 text-left transition-colors {{ $selectedTier === $slug ? 'border-brand-forest bg-brand-forest/5 ring-1 ring-brand-forest/40' : 'border-brand-ink/10 hover:bg-brand-sand/30' }}">
                            <div class="text-sm font-semibold text-brand-ink">{{ $tierOption['label'] }}</div>
                            <div class="mt-0.5 text-[11px] text-brand-moss">{{ number_format($tierOption['max_connections']) }} {{ __('connections') }}</div>
                            <div class="mt-1 text-xs font-semibold text-brand-forest">{{ $money((int) $tierOption['price_cents']) }}/{{ __('mo') }}</div>
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
