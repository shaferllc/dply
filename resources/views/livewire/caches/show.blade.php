@php
    $bytes = function (int $value): string {
        if ($value < 1024) {
            return $value.' B';
        }
        foreach (['KiB', 'MiB', 'GiB'] as $index => $unit) {
            $scaled = $value / (1024 ** ($index + 1));
            if ($scaled < 1024 || $unit === 'GiB') {
                return number_format($scaled, $scaled < 10 ? 1 : 0).' '.$unit;
            }
        }
        return $value.' B';
    };

    $meterTone = match (true) {
        $quotaFraction >= 0.9 => 'bg-brand-rust',
        $quotaFraction >= 0.7 => 'bg-brand-gold',
        default => 'bg-brand-sage',
    };
@endphp

<div class="contents">
    <x-workspace-nav />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="$breadcrumbs" />

        <x-profile-shell
            dense
            :title="$cache->name"
            :description="$cache->isShared()
                ? __('Shared tier — free, bounded by storage, TTL-only.')
                : __('Dedicated Redis.')"
            icon="heroicon-o-bolt"
        >
            <x-slot:actions>
                @if ($canFlush)
                    <button type="button" wire:click="confirmFlush" class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40">
                        <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                        {{ __('Flush') }}
                    </button>
                @endif
            </x-slot:actions>

            @if ($revealedEnvBlock !== null)
                <div class="mb-6 rounded-2xl border border-brand-gold/40 bg-brand-gold/10 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Paste this into your app') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('The secret is stored as a hash and will not be shown again.') }}
                            </p>
                            <pre class="mt-3 overflow-x-auto rounded-xl bg-brand-ink px-3 py-2 text-xs leading-relaxed text-brand-cream"><code>{{ $revealedEnvBlock }}</code></pre>
                        </div>
                        <button type="button" wire:click="dismissSecret" class="shrink-0 rounded-lg p-1 text-brand-moss hover:text-brand-ink">
                            <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                            <span class="sr-only">{{ __('Dismiss') }}</span>
                        </button>
                    </div>
                </div>
            @endif

            {{-- Usage --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5">
                <div class="flex items-baseline justify-between">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Storage') }}</h3>
                    <p class="text-sm tabular-nums text-brand-moss">
                        {{ $bytes($usage->residentBytes) }} / {{ $bytes($quotaBytes) }} · {{ number_format($usage->itemCount) }} {{ __('keys') }}
                    </p>
                </div>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-brand-ink/10">
                    <div class="h-full rounded-full {{ $meterTone }}" style="width: {{ max(2, (int) round($quotaFraction * 100)) }}%"></div>
                </div>
                <p class="mt-3 text-xs text-brand-moss">
                    {{ __('At quota, writes are refused rather than evicted — expired keys are reclaimed on a schedule, and nothing is evicted early to make room. If you are storing page output rather than locks and counters, a dedicated cache is both larger and far faster.') }}
                </p>
            </section>

            {{-- Capability surface. Stated, not discovered in production. --}}
            <section class="mt-6 rounded-2xl border border-brand-ink/10 bg-white p-5">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('What works on this tier') }}</h3>
                <dl class="mt-3 grid gap-x-8 gap-y-2 sm:grid-cols-2">
                    @foreach ([
                        ['Cache::get() / put() / increment()', true, __('Atomic.')],
                        ['Cache::lock()', true, __('Real mutexes across containers.')],
                        ['Cache::many() / putMany()', true, __('One round trip for N keys.')],
                        ['Cache::tags()', false, __('Throws — the driver is not taggable.')],
                        ['Cache::flush()', false, __('Throws — use the Flush button above.')],
                    ] as [$label, $supported, $note])
                        <div class="flex items-start gap-2 py-1">
                            @if ($supported)
                                <x-heroicon-o-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            @else
                                <x-heroicon-o-x-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-rust" aria-hidden="true" />
                            @endif
                            <div class="min-w-0">
                                <dt class="font-mono text-xs text-brand-ink">{{ $label }}</dt>
                                <dd class="text-xs text-brand-moss">{{ $note }}</dd>
                            </div>
                        </div>
                    @endforeach
                </dl>
                <p class="mt-3 text-xs text-brand-moss">
                    {{ __('Each operation is one HTTPS round trip (roughly 10–40ms), against about 0.5ms for a local Redis. That is the trade: this tier exists to make coordination work at all, not to make pages faster. Use Cache::many() for bulk reads, and avoid Cache::get() inside a loop.') }}
                </p>
            </section>

            {{-- Sites --}}
            <section class="mt-6 rounded-2xl border border-brand-ink/10 bg-white p-5">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Attached sites') }}</h3>

                @if ($attachedSites->isEmpty())
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Not attached to anything yet.') }}</p>
                @else
                    <ul class="mt-3 divide-y divide-brand-ink/5">
                        @foreach ($attachedSites as $site)
                            <li class="flex items-center justify-between py-2">
                                <span class="text-sm text-brand-ink">{{ $site->name ?: $site->id }}</span>
                                @if ($canManage)
                                    <button type="button" wire:click="detach('{{ $site->id }}')" class="text-sm font-medium text-brand-moss hover:text-brand-rust">
                                        {{ __('Detach') }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canManage)
                    <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-brand-ink/5 pt-4">
                        <div class="min-w-48 flex-1">
                            <label for="attachSiteId" class="block text-xs font-medium text-brand-ink">{{ __('Attach a site') }}</label>
                            <select id="attachSiteId" wire:model="attachSiteId" class="mt-1 w-full rounded-xl border-brand-ink/15 text-sm focus:border-brand-sage focus:ring-brand-sage">
                                <option value="">{{ __('Choose a site…') }}</option>
                                @foreach ($attachableSites as $site)
                                    <option value="{{ $site->id }}">{{ $site->name ?: $site->id }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-40">
                            <label for="attachPrefix" class="block text-xs font-medium text-brand-ink">{{ __('Key prefix') }}</label>
                            <input id="attachPrefix" type="text" wire:model="attachPrefix" placeholder="{{ __('optional') }}" class="mt-1 w-full rounded-xl border-brand-ink/15 text-sm focus:border-brand-sage focus:ring-brand-sage" />
                        </div>
                        <button type="button" wire:click="attach" class="rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                            {{ __('Attach') }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-brand-moss">
                        {{ __('Attaching writes CACHE_STORE and the endpoint into the site environment and mints it its own credential. The site needs a redeploy to pick it up.') }}
                    </p>
                @endif
            </section>

            {{-- Credentials --}}
            <section class="mt-6 rounded-2xl border border-brand-ink/10 bg-white p-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Credentials') }}</h3>
                    @if ($canManageCredentials)
                        <button type="button" wire:click="mintCredential" class="text-sm font-medium text-brand-ink hover:text-brand-forest">
                            {{ __('New credential') }}
                        </button>
                    @endif
                </div>

                <ul class="mt-3 divide-y divide-brand-ink/5">
                    @foreach ($credentials as $credential)
                        <li class="flex items-center justify-between gap-4 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-brand-ink">{{ $credential->name }}</p>
                                <p class="font-mono text-xs text-brand-moss">{{ $credential->maskedToken() }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                @if ($credential->isRevoked())
                                    <span class="text-xs text-brand-rust">{{ __('Revoked') }}</span>
                                @elseif ($credential->last_used_at)
                                    <span class="text-xs text-brand-moss">{{ __('Used :when', ['when' => $credential->last_used_at->diffForHumans()]) }}</span>
                                @else
                                    <span class="text-xs text-brand-moss">{{ __('Never used') }}</span>
                                @endif

                                @if ($canManageCredentials && ! $credential->isRevoked())
                                    <button type="button" wire:click="confirmRevoke('{{ $credential->id }}')" class="text-sm font-medium text-brand-moss hover:text-brand-rust">
                                        {{ __('Revoke') }}
                                    </button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Manual wiring, for an app that is not a dply site. --}}
            <section class="mt-6 rounded-2xl border border-brand-ink/10 bg-white p-5">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Wiring an external app') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('No package to install — this is Laravel\'s built-in dynamodb cache store.') }}
                </p>
                <pre class="mt-3 overflow-x-auto rounded-xl bg-brand-ink px-3 py-2 text-xs leading-relaxed text-brand-cream"><code>CACHE_STORE=dynamodb
DYNAMODB_ENDPOINT={{ $endpoint ?: 'https://…' }}
DYNAMODB_CACHE_TABLE={{ $cache->id }}
AWS_DEFAULT_REGION={{ config('cache_service.region') }}
AWS_ACCESS_KEY_ID=&lt;{{ __('from a credential above') }}&gt;
AWS_SECRET_ACCESS_KEY=&lt;{{ __('shown once, when minted') }}&gt;</code></pre>
                @if ($endpoint === '')
                    <p class="mt-2 text-xs text-brand-rust">
                        {{ __('No public endpoint is configured — set DPLY_CACHE_PUBLIC_URL or DPLY_PUBLIC_APP_URL, or nothing outside this machine can reach the cache.') }}
                    </p>
                @endif
            </section>
        </x-profile-shell>
    </div>

    @if ($confirmingFlush)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/40 p-4" wire:key="flush-modal">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Flush this cache?') }}</h3>
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('Drops every key immediately, including locks currently held. Any job relying on WithoutOverlapping or ShouldBeUnique right now will lose its mutex and may run twice.') }}
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelFlush" class="rounded-xl px-4 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="flush" class="rounded-xl bg-brand-rust px-4 py-2 text-sm font-semibold text-white hover:opacity-90">{{ __('Flush') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if ($revokingId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/40 p-4" wire:key="revoke-modal">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Revoke this credential?') }}</h3>
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('Effective immediately. Any app still presenting it starts failing every cache call, including its locks.') }}
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelRevoke" class="rounded-xl px-4 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="revokeCredential" class="rounded-xl bg-brand-rust px-4 py-2 text-sm font-semibold text-white hover:opacity-90">{{ __('Revoke') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
