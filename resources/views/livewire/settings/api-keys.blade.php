@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $totalTokens = $tokens->count();
    $activeTokens = $tokens->filter(fn ($t) => $t->expires_at === null || ! $t->expires_at->isPast())->count();
    $expiringSoon = $tokens->filter(fn ($t) => $t->expires_at !== null && $t->expires_at->isFuture() && $t->expires_at->diffInDays(now()) <= 14)->count();
    $hasApiTokenSearch = trim($token_list_search ?? '') !== '';
    $orgCount = $adminOrganizations->count();
@endphp

<div>
    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.api" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('API keys'), 'icon' => 'bolt'],
        ]" />
    @endpush

    {{-- Hero: positioning + at-a-glance token counts. --}}
    <x-hero-card
        :eyebrow="__('Automation')"
        :title="__('API keys')"
        :description="__('Personal access tokens for the dply HTTP API. Each token is scoped to an organization with explicit permissions and an optional IP allow-list.')"
        icon="bolt"
    >
        <x-outline-link href="{{ route('settings.profile') }}" wire:navigate>
            <x-heroicon-o-user-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            {{ __('Back to profile') }}
        </x-outline-link>
        @if ($adminOrganizations->isNotEmpty())
            <button
                type="button"
                wire:click="openCreateApiTokenModal"
                @disabled($requiresPaidPlan && $organization && ! $orgHasProPlan)
                class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
            >
                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Add API token') }}
            </button>
        @endif

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-2">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tokens') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $totalTokens }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $totalTokens) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">
                        @if ($totalTokens !== $activeTokens)
                            {{ trans_choice(':n active|:n active', $activeTokens, ['n' => $activeTokens]) }}
                        @else
                            {{ __('All active') }}
                        @endif
                    </p>
                </div>
                <div @class([
                    'rounded-2xl border px-4 py-3 shadow-sm',
                    'border-amber-200 bg-amber-50' => $expiringSoon > 0,
                    'border-brand-ink/10 bg-white' => $expiringSoon === 0,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Expiring') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $expiringSoon }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('soon|soon', $expiringSoon) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Within 14 days') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Scope') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $orgCount }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('org|orgs', $orgCount) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('You can issue against') }}</p>
                </div>
            </dl>
        </x-slot:stats>
    </x-hero-card>

    @if ($adminOrganizations->isEmpty())
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
            <span class="inline-flex items-center gap-1.5 font-semibold">
                <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Admin access required') }}
            </span>
            <p class="mt-1 leading-relaxed">{{ __('You need to be an organization admin to create API tokens. Ask an owner to promote you or create an organization first.') }}</p>
        </div>
    @else
        @if ($requiresPaidPlan && $organization && ! $orgHasProPlan)
            <div class="mt-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950" role="status">
                <span class="inline-flex items-center gap-1.5 font-semibold">
                    <x-heroicon-m-information-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Pro plan required to create tokens') }}
                </span>
                <p class="mt-1 leading-relaxed">{{ __('Token creation needs an active Pro subscription on the selected organization. Existing tokens can still be revoked.') }}</p>
            </div>
        @endif

        @if ($new_token_plaintext)
            {{-- "Copy this token now" panel. Green-tinted because it's a
                 success state, but the warning copy keeps it serious. --}}
            <section class="mt-6 overflow-hidden rounded-2xl border border-emerald-200 bg-emerald-50/70 shadow-sm" role="status">
                <div class="flex items-start gap-3 border-b border-emerald-200/60 bg-emerald-100/50 px-5 py-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200">
                        <x-heroicon-o-check-badge class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-700/80">{{ __('Created') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-emerald-950">{{ __('Copy this token now — you won\'t see it again') }}</h3>
                        <p class="mt-1 text-sm text-emerald-900/80">{{ __('Token name:') }} <span class="font-semibold">{{ $new_token_name }}</span></p>
                    </div>
                </div>
                <div class="space-y-3 p-5">
                    <div class="flex flex-wrap items-stretch gap-2">
                        <code class="min-w-0 flex-1 break-all rounded-lg border border-emerald-200 bg-white px-3 py-2.5 font-mono text-xs text-brand-ink">{{ $new_token_plaintext }}</code>
                        <button
                            type="button"
                            x-data="{ copied: false }"
                            x-on:click="navigator.clipboard.writeText(@js($new_token_plaintext)); copied = true; setTimeout(() => copied = false, 2000)"
                            class="shrink-0 inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <span x-show="!copied" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-clipboard-document class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Copy') }}
                            </span>
                            <span x-show="copied" x-cloak class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Copied') }}
                            </span>
                        </button>
                    </div>
                    <button type="button" wire:click="clearNewToken" class="text-xs font-semibold text-emerald-900 underline underline-offset-2 hover:no-underline">
                        {{ __('Dismiss') }}
                    </button>
                </div>
            </section>
        @endif

        <div class="mt-6 space-y-6">
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Directory') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your tokens') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Personal access tokens for the HTTP API. Revoke any you no longer use.') }}</p>
                    </div>
                    @if ($totalTokens > 0)
                        <button
                            type="button"
                            wire:click="openCreateApiTokenModal"
                            @disabled($requiresPaidPlan && $organization && ! $orgHasProPlan)
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Add token') }}
                        </button>
                    @endif
                </div>

                @if ($tokens->isNotEmpty() || $hasApiTokenSearch)
                    {{-- Toolbar: search. --}}
                    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-7">
                        <div class="w-full sm:max-w-sm">
                            <label for="api_token_search" class="sr-only">{{ __('Search') }}</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                                    <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <input
                                    id="api_token_search"
                                    type="search"
                                    wire:model.live.debounce.300ms="token_list_search"
                                    placeholder="{{ __('Search tokens by name…') }}"
                                    autocomplete="off"
                                    class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                />
                            </div>
                        </div>
                    </div>
                @endif

                @if (! $hasApiTokenSearch && $tokens->isEmpty())
                    <div class="px-6 py-12 text-center sm:px-7">
                        <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-bolt class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No API tokens yet') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                            {{ __('Issue your first token to call the HTTP API from CI, scripts, or other automation.') }}
                        </p>
                        <button
                            type="button"
                            wire:click="openCreateApiTokenModal"
                            @disabled($requiresPaidPlan && $organization && ! $orgHasProPlan)
                            class="mt-5 inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Add API token') }}
                        </button>
                    </div>
                @elseif ($hasApiTokenSearch && $tokens->isEmpty())
                    <div class="px-6 py-12 text-center sm:px-7">
                        <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No tokens match this search.') }}</p>
                        <button type="button" wire:click="$set('token_list_search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($tokens as $t)
                            @php
                                $expired = $t->expires_at !== null && $t->expires_at->isPast();
                                $expiringSoonRow = $t->expires_at !== null && $t->expires_at->isFuture() && $t->expires_at->diffInDays(now()) <= 14;
                            @endphp
                            <li wire:key="api-token-{{ $t->id }}" class="flex flex-col gap-3 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-7">
                                <div class="min-w-0 flex-1 space-y-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $t->name }}</span>
                                        @if ($expired)
                                            <span class="inline-flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700">
                                                <x-heroicon-m-no-symbol class="h-3 w-3" aria-hidden="true" />
                                                {{ __('Expired') }}
                                            </span>
                                        @elseif ($expiringSoonRow)
                                            <span class="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">
                                                <x-heroicon-m-clock class="h-3 w-3" aria-hidden="true" />
                                                {{ __('Expiring') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="font-mono text-[11px] text-brand-mist">{{ $t->masked_display }}</p>
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-moss">
                                        @if ($t->last_used_at)
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-bolt class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                                {{ __('Last used :time', ['time' => $t->last_used_at->diffForHumans()]) }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 text-brand-mist">
                                                <x-heroicon-m-minus-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Never used') }}
                                            </span>
                                        @endif
                                        @if ($t->expires_at)
                                            <span class="text-brand-mist">·</span>
                                            <span>{{ __('Expires :date', ['date' => $t->expires_at->format('M j, Y')]) }}</span>
                                        @endif
                                        @if ($t->allowed_ips)
                                            <span class="text-brand-mist">·</span>
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-globe-alt class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                <span class="font-mono">{{ implode(', ', $t->allowed_ips) }}</span>
                                            </span>
                                        @endif
                                    </p>
                                    @if ($t->abilities)
                                        <p class="flex flex-wrap gap-1 pt-1">
                                            @foreach (array_slice($t->abilities, 0, 6) as $ability)
                                                <code class="inline-flex items-center rounded-md bg-brand-sand/55 px-1.5 py-0.5 font-mono text-[10px] text-brand-moss">{{ $ability }}</code>
                                            @endforeach
                                            @if (count($t->abilities) > 6)
                                                <span class="inline-flex items-center rounded-md bg-brand-sand/55 px-1.5 py-0.5 font-mono text-[10px] text-brand-moss">+{{ count($t->abilities) - 6 }}</span>
                                            @endif
                                        </p>
                                    @endif
                                </div>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('revokeToken', [{{ $t->id }}], @js(__('Revoke token')), @js(__('Revoke this token? It will stop working immediately.')), @js(__('Revoke')), true)"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                                >
                                    <x-heroicon-o-no-symbol class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Revoke') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <x-modal
            name="create-api-token-modal"
            :show="false"
            maxWidth="2xl"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
            focusable
        >
            <form wire:submit="createToken" class="flex min-h-0 flex-1 flex-col">
                <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Personal access token') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Create token') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('Use tokens to authenticate to the dply HTTP API from CI/CD or scripts.') }}
                        </p>
                    </div>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                    @if ($isDeployerRole)
                        <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                            <span class="inline-flex items-center gap-1.5 font-semibold">
                                <x-heroicon-m-information-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Deploy-only role') }}
                            </span>
                            <p class="mt-1 text-xs leading-relaxed">{{ __('Tokens can only include server and site read + deploy permissions, matching organization policy.') }}</p>
                        </div>
                    @endif

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="api_org_modal" :value="__('Organization')" />
                            <select
                                id="api_org_modal"
                                wire:model.live="organization_id"
                                class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach ($adminOrganizations as $o)
                                    <option value="{{ $o->id }}">{{ $o->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="api_token_name_modal" :value="__('Name')" />
                            <x-text-input id="api_token_name_modal" wire:model="token_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. CI deploy') }}" autocomplete="off" />
                            <x-input-error :messages="$errors->get('token_name')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="api_token_exp_modal" :value="__('Expires (optional)')" />
                        <x-text-input id="api_token_exp_modal" wire:model="token_expires_at" type="date" class="mt-1 block w-full max-w-xs" min="{{ date('Y-m-d', strtotime('+1 day')) }}" />
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Leave blank for no expiry. Short-lived tokens are safer for CI runners.') }}</p>
                        <x-input-error :messages="$errors->get('token_expires_at')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="api_token_ips_modal" :value="__('Whitelist IP addresses')" />
                        <textarea
                            id="api_token_ips_modal"
                            wire:model="token_allowed_ips_text"
                            rows="3"
                            class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            placeholder="{{ __('Comma-separated or one per line. Leave empty to allow any IP.') }}"
                        ></textarea>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('IPv4, IPv6, or IPv4 CIDR ranges.') }}</p>
                        <x-input-error :messages="$errors->get('token_allowed_ips_text')" class="mt-2" />
                    </div>

                    <div>
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Permissions') }}</p>
                            <button
                                type="button"
                                wire:click="toggleAllPermissions"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-arrows-right-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Toggle all') }}
                            </button>
                        </div>
                        @if (count($selected_abilities) === 0)
                            <div class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
                                <span class="inline-flex items-center gap-1.5 font-semibold">
                                    <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('No permissions selected') }}
                                </span>
                                <p class="mt-1 leading-relaxed">{{ __('Pick at least one permission so the token can do something with the API.') }}</p>
                            </div>
                        @endif
                        <x-input-error :messages="$errors->get('selected_abilities')" class="mt-2" />

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach ($permissionCategories as $cat)
                                @php
                                    $catId = $cat['id'];
                                    $perms = $cat['permissions'] ?? [];
                                    $abilityList = collect($perms)->pluck('ability')->all();
                                    $selectedInCat = count(array_intersect($selected_abilities, $abilityList));
                                    $totalInCat = count($abilityList);
                                    $expanded = in_array($catId, $expanded_categories, true);
                                    $allSelected = $totalInCat > 0 && $selectedInCat === $totalInCat;
                                @endphp
                                <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                                    <button
                                        type="button"
                                        wire:click="toggleCategoryExpand('{{ $catId }}')"
                                        @class([
                                            'flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left text-sm font-semibold transition',
                                            'bg-brand-sage/8 text-brand-forest' => $allSelected,
                                            'bg-brand-cream/40 text-brand-ink hover:bg-brand-sand/40' => ! $allSelected,
                                        ])
                                        aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                                    >
                                        <span>{{ $cat['label'] }}</span>
                                        <span class="inline-flex items-center gap-1.5 text-[11px] font-medium text-brand-moss">
                                            <span @class([
                                                'rounded-full px-1.5 py-0.5 tabular-nums',
                                                'bg-brand-sage/20 text-brand-forest' => $selectedInCat > 0,
                                                'bg-brand-sand/60 text-brand-mist' => $selectedInCat === 0,
                                            ])>{{ $selectedInCat }}/{{ $totalInCat }}</span>
                                            <x-heroicon-m-chevron-down class="h-4 w-4 transition-transform {{ $expanded ? 'rotate-180' : '' }}" aria-hidden="true" />
                                        </span>
                                    </button>
                                    @if ($expanded)
                                        <div class="space-y-1.5 border-t border-brand-ink/10 bg-white px-3 py-3">
                                            @foreach ($perms as $p)
                                                @php $ab = $p['ability']; @endphp
                                                <label class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1 text-sm text-brand-moss hover:bg-brand-sand/30">
                                                    <input
                                                        type="checkbox"
                                                        wire:click.prevent="toggleAbility('{{ $ab }}')"
                                                        @checked(in_array($ab, $selected_abilities, true))
                                                        class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest"
                                                    />
                                                    <span class="text-brand-ink">{{ $p['label'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeCreateApiTokenModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="createToken"
                        @disabled($requiresPaidPlan && $organization && ! $orgHasProPlan)
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="createToken" class="inline-flex items-center gap-2">
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create token') }}
                        </span>
                        <span wire:loading wire:target="createToken" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Creating…') }}
                        </span>
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
