<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            dense
            :organization="$organization"
            section="api-tokens"
            :title="__('API tokens')"
            :description="__('Every token issued for this organization, across all members. Issue new ones from your API keys settings.')"
            icon="heroicon-o-key"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('API tokens'), 'icon' => 'key'],
            ]"
        >
            <x-slot:actions>
                <a
                    href="{{ route('profile.api-keys') }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Create token') }}
                </a>
            </x-slot:actions>

            @php
                $expiredCount = $organization->apiTokens->filter(fn ($t) => $t->expires_at !== null && $t->expires_at->isPast())->count();
                $totalCount = $organization->apiTokens->count();
            @endphp

            {{-- Filter strip. A flat list of 16 identical "dply CLI" rows answered
                 no question; state and a filter answer the only one worth asking:
                 what is live right now. --}}
            @if ($totalCount > 0)
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:px-4">
                    <div class="flex items-center gap-1">
                        @foreach ([
                            'all' => ['label' => __('All :n', ['n' => $totalCount]), 'icon' => 'heroicon-o-key'],
                            'active' => ['label' => __('Active :n', ['n' => $totalCount - $expiredCount]), 'icon' => 'heroicon-o-check-circle'],
                            'expired' => ['label' => __('Expired :n', ['n' => $expiredCount]), 'icon' => 'heroicon-o-clock'],
                        ] as $key => $chip)
                            <button
                                type="button"
                                wire:click="$set('filter', '{{ $key }}')"
                                @class([
                                    'inline-flex h-6 items-center gap-1 rounded-md px-2 text-xs font-semibold transition-colors',
                                    'bg-brand-ink text-brand-cream' => $filter === $key,
                                    'border border-brand-ink/15 bg-white text-brand-moss hover:text-brand-ink' => $filter !== $key,
                                ])
                            >
                                <x-dynamic-component :component="$chip['icon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ $chip['label'] }}
                            </button>
                        @endforeach
                    </div>

                    <div class="ms-auto w-full sm:w-56">
                        <label for="token-search" class="sr-only">{{ __('Search tokens') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                                <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <input
                                id="token-search"
                                type="search"
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('Search token or person…') }}"
                                autocomplete="off"
                                class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 ps-8 pe-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            />
                        </div>
                    </div>
                </div>
            @endif

            @if ($tokens->isEmpty())
                <div class="px-3 py-8 text-center sm:px-4">
                    <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm text-brand-moss">
                        {{ $totalCount === 0 ? __('No API tokens yet.') : __('No tokens match this view.') }}
                    </p>
                    @if ($totalCount === 0)
                        <a href="{{ route('profile.api-keys') }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-brand-forest hover:underline">
                            {{ __('Create one in API keys settings') }}
                        </a>
                    @endif
                </div>
            @else
                <ul class="divide-y divide-brand-ink/5">
                    @foreach ($tokens as $apiToken)
                        @php
                            $expired = $apiToken->expires_at !== null && $apiToken->expires_at->isPast();
                            $expiringSoon = ! $expired && $apiToken->expires_at !== null && $apiToken->expires_at->lt(now()->addDays(7));
                        @endphp
                        <li wire:key="org-api-token-{{ $apiToken->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4">
            @php($owner = $apiToken->user)
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-moss/15 text-2xs font-semibold text-brand-moss" title="{{ $owner?->email ?? __('Owner removed') }}">
                                {{ $owner ? \Illuminate\Support\Str::of($owner->name)->explode(' ')->filter()->take(2)->map(fn ($p) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($p, 0, 1)))->implode('') : '?' }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold {{ $expired ? 'text-brand-mist' : 'text-brand-ink' }}">{{ $apiToken->name }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-moss">
                                    @if ($owner)
                                        <a href="{{ route('organizations.member', [$organization, $owner]) }}" wire:navigate class="truncate font-medium text-brand-sage hover:text-brand-ink">{{ $owner->name }}</a>
                                    @else
                                        <span class="truncate font-medium text-brand-mist">{{ __('Owner removed') }}</span>
                                    @endif
                                    <span class="text-brand-mist">·</span>
                                    <span class="font-mono text-brand-mist">{{ $apiToken->token_prefix }}…</span>
                                    @if ($apiToken->last_used_at)
                                        <span class="text-brand-mist">·</span>
                                        <span>{{ __('Last used :time', ['time' => $apiToken->last_used_at->diffForHumans()]) }}</span>
                                    @else
                                        <span class="text-brand-mist">·</span>
                                        <span>{{ __('Never used') }}</span>
                                    @endif
                                    @if ($apiToken->expires_at)
                                        <span class="text-brand-mist">·</span>
                                        <span>{{ $expired ? __('Expired :date', ['date' => $apiToken->expires_at->format('M j, Y')]) : __('Expires :date', ['date' => $apiToken->expires_at->format('M j, Y')]) }}</span>
                                    @endif
                                </p>
                            </div>

                            <span @class([
                                'inline-flex shrink-0 items-center rounded border px-1.5 py-px text-2xs font-semibold uppercase tracking-wide',
                                'border-brand-ink/10 bg-brand-sand/40 text-brand-mist' => $expired,
                                'border-amber-200 bg-amber-50 text-amber-800' => $expiringSoon,
                                'border-emerald-200 bg-emerald-50 text-emerald-700' => ! $expired && ! $expiringSoon,
                            ])>
                                {{ $expired ? __('Expired') : ($expiringSoon ? __('Expiring') : __('Active')) }}
                            </span>

                            <button
                                type="button"
                                wire:click='promptRevokeApiToken({{ json_encode((string) $apiToken->id) }})'
                                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-2xs font-semibold text-rose-700 shadow-sm transition-colors hover:bg-rose-50"
                            >
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Revoke') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-organization-shell>
    </div>

    {{-- Included directly, not via a "modals" layout slot: on a plain-div page
         root that slot is dropped on every Livewire re-render. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
