@php
    $totalTokens = $tokens->count();
    $activeTokens = $tokens->filter(fn ($t) => $t->expires_at === null || ! $t->expires_at->isPast())->count();
    $expiringSoon = $tokens->filter(fn ($t) => $t->expires_at !== null && $t->expires_at->isFuture() && $t->expires_at->diffInDays(now()) <= 14)->count();
    $hasApiTokenSearch = trim($token_list_search ?? '') !== '';
    $orgCount = $adminOrganizations->count();
    $canCreate = $adminOrganizations->isNotEmpty();
    $createDisabled = $requiresPaidPlan && $organization && ! $orgHasProPlan;
    // Header Add only when the list already has items — empty state owns the CTA.
    $showShellAdd = $canCreate && $totalTokens > 0;
    $summaryLine = collect([
        trans_choice(':count token|:count tokens', $totalTokens, ['count' => $totalTokens]),
        $totalTokens !== $activeTokens ? __(':count active', ['count' => $activeTokens]) : null,
        $expiringSoon > 0 ? __(':count expiring soon', ['count' => $expiringSoon]) : null,
    ])->filter()->implode(' · ');
@endphp

<div>
    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.api" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('API keys'), 'icon' => 'bolt'],
        ]" />
    @endpush

    <x-profile-shell
        dense
        :title="__('API keys')"
        :description="$totalTokens > 0 ? $summaryLine : __('Personal access tokens for the dply HTTP API.')"
        icon="heroicon-o-bolt"
    >
        {{-- No "Back to profile": the breadcrumb already covers it. --}}
        @if ($showShellAdd)
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="openCreateApiTokenModal"
                    @disabled($createDisabled)
                    class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add API token') }}
                </button>
            </x-slot:actions>
        @endif

        @if ($errors->isNotEmpty())
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <x-livewire-validation-errors />
            </div>
        @endif

        @if (! $canCreate)
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4" role="status">
                <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-950">
                    <span class="inline-flex items-center gap-1.5 font-semibold">
                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Admin access required') }}
                    </span>
                    <p class="mt-1 leading-relaxed">{{ __('You need to be an organization admin to create API tokens. Ask an owner to promote you or create an organization first.') }}</p>
                </div>
            </div>
        @else
            @if ($createDisabled)
                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4" role="status">
                    <div class="rounded-md border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs text-sky-950">
                        <span class="inline-flex items-center gap-1.5 font-semibold">
                            <x-heroicon-m-information-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Pro plan required to create tokens') }}
                        </span>
                        <p class="mt-1 leading-relaxed">{{ __('Token creation needs an active Pro subscription on the selected organization. Existing tokens can still be revoked.') }}</p>
                    </div>
                </div>
            @endif

            @if ($new_token_plaintext)
                {{-- One-shot token reveal: header line, the value, copy + dismiss.
                     The icon tile and the three stacked prose lines were chrome
                     around a string you're meant to grab and leave. --}}
                <div class="border-b border-brand-ink/10 bg-emerald-50/70 px-3 py-2 sm:px-4" role="status">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <p class="inline-flex items-center gap-1 text-sm font-semibold text-emerald-950">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0 text-emerald-700" aria-hidden="true" />
                            {{ __('Copy this token now — you won\'t see it again') }}
                        </p>
                        <p class="min-w-0 truncate text-xs text-emerald-900/80">{{ $new_token_name }}</p>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <code class="min-w-0 flex-1 break-all rounded-md border border-emerald-200 bg-white px-2.5 py-1.5 font-mono text-xs text-brand-ink">{{ $new_token_plaintext }}</code>
                        <button
                            type="button"
                            x-data="{ copied: false }"
                            x-on:click="navigator.clipboard.writeText(@js($new_token_plaintext)); copied = true; setTimeout(() => copied = false, 2000)"
                            class="inline-flex h-7 shrink-0 items-center justify-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <span x-show="!copied" class="inline-flex items-center gap-1">
                                <x-heroicon-o-clipboard-document class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Copy') }}
                            </span>
                            <span x-show="copied" x-cloak class="inline-flex items-center gap-1">
                                <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Copied') }}
                            </span>
                        </button>
                        <button type="button" wire:click="clearNewToken" class="shrink-0 text-xs font-semibold text-emerald-900 underline underline-offset-2 hover:no-underline">
                            {{ __('Dismiss') }}
                        </button>
                    </div>
                </div>
            @endif

            @if ($tokens->isNotEmpty() || $hasApiTokenSearch)
                <div class="flex flex-col gap-2 border-b border-brand-ink/10 px-3 py-2 sm:flex-row sm:items-center sm:justify-end sm:px-4">
                    <div class="w-full sm:max-w-xs">
                        <label for="api_token_search" class="sr-only">{{ __('Search') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                                <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <input
                                id="api_token_search"
                                type="search"
                                wire:model.live.debounce.300ms="token_list_search"
                                placeholder="{{ __('Search tokens by name…') }}"
                                autocomplete="off"
                                class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 ps-8 pe-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            />
                        </div>
                    </div>
                </div>
            @endif

            @if (! $hasApiTokenSearch && $tokens->isEmpty())
                <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <p class="mt-2.5 text-sm font-semibold text-brand-ink">{{ __('No API tokens yet') }}</p>
                    <p class="mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Issue your first token to call the HTTP API from CI, scripts, or other automation.') }}
                    </p>
                    <button
                        type="button"
                        wire:click="openCreateApiTokenModal"
                        @disabled($createDisabled)
                        class="mt-3 inline-flex h-7 items-center gap-1.5 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Add API token') }}
                    </button>
                </div>
            @elseif ($hasApiTokenSearch && $tokens->isEmpty())
                <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <p class="mt-2 text-sm font-medium text-brand-ink">{{ __('No tokens match this search.') }}</p>
                    <button type="button" wire:click="$set('token_list_search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                </div>
            @else
                @php $th = 'px-3 py-2 text-start text-2xs font-semibold uppercase tracking-wide text-brand-mist sm:px-4'; @endphp
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10">
                                <th scope="col" class="{{ $th }}">{{ __('Name') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Token') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Permissions') }}</th>
                                <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Last used') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Expires') }}</th>
                                <th scope="col" class="{{ $th }}"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pagedTokens as $t)
                                @php
                                    $expired = $t->expires_at !== null && $t->expires_at->isPast();
                                    $expiringSoonRow = $t->expires_at !== null && $t->expires_at->isFuture() && $t->expires_at->diffInDays(now()) <= 14;
                                    $abilityCount = count($t->abilities ?? []);
                                @endphp
                                {{-- One row per token. The full scope list and the IP
                                     allow-list ride the row's title attributes — six
                                     ability chips per row is what made this page loud. --}}
                                <tr wire:key="api-token-{{ $t->id }}" class="group border-b border-brand-ink/10 transition-colors last:border-b-0 hover:bg-brand-sand/15">
                                    <td class="max-w-[14rem] px-3 py-2 sm:px-4">
                                        <span class="flex min-w-0 items-center gap-1.5">
                                            <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-red-600' => $expired, 'bg-brand-gold' => ! $expired && $expiringSoonRow, 'bg-brand-sage' => ! $expired && ! $expiringSoonRow]) aria-hidden="true"></span>
                                            <span @class(['min-w-0 truncate font-semibold', 'text-brand-mist' => $expired, 'text-brand-ink' => ! $expired]) title="{{ $t->name }}">{{ $t->name }}</span>
                                            @if ($t->allowed_ips)
                                                <x-heroicon-m-globe-alt
                                                    class="h-3.5 w-3.5 shrink-0 text-brand-mist"
                                                    title="{{ __('Allowed IPs: :ips', ['ips' => implode(', ', $t->allowed_ips)]) }}"
                                                />
                                            @endif
                                        </span>
                                    </td>

                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-brand-moss sm:px-4">{{ $t->masked_display }}</td>

                                    <td class="whitespace-nowrap px-3 py-2 text-xs sm:px-4">
                                        <button
                                            type="button"
                                            wire:click="openEditTokenAbilitiesModal(@js((string) $t->id))"
                                            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-brand-moss underline decoration-dotted underline-offset-4 transition-colors hover:bg-brand-sand/40 hover:text-brand-ink"
                                            title="{{ $abilityCount > 0 ? implode(', ', $t->abilities) : __('No scopes') }}"
                                        >
                                            {{ trans_choice(':count scope|:count scopes', $abilityCount, ['count' => $abilityCount]) }}
                                            <x-heroicon-m-pencil-square class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
                                        </button>
                                    </td>

                                    <td class="hidden whitespace-nowrap px-3 py-2 text-xs text-brand-mist sm:table-cell sm:px-4">
                                        @if ($t->last_used_at)
                                            <span title="{{ $t->last_used_at }}">{{ $t->last_used_at->diffForHumans(short: true) }}</span>
                                        @else
                                            {{ __('never') }}
                                        @endif
                                    </td>

                                    <td class="whitespace-nowrap px-3 py-2 text-xs sm:px-4">
                                        @if ($expired)
                                            <span class="inline-flex items-center gap-0.5 rounded border border-red-200 bg-red-50 px-1 py-px text-2xs font-semibold uppercase tracking-wide text-red-700">
                                                <x-heroicon-m-no-symbol class="h-3 w-3" aria-hidden="true" />
                                                {{ __('Expired') }}
                                            </span>
                                        @elseif ($expiringSoonRow)
                                            <span class="inline-flex items-center gap-0.5 rounded border border-amber-200 bg-amber-50 px-1 py-px text-2xs font-semibold uppercase tracking-wide text-amber-900">
                                                <x-heroicon-m-clock class="h-3 w-3" aria-hidden="true" />
                                                {{ __('in :time', ['time' => $t->expires_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true)]) }}
                                            </span>
                                        @elseif ($t->expires_at)
                                            <span class="text-brand-moss">{{ $t->expires_at->format('M j, Y') }}</span>
                                        @else
                                            <span class="text-brand-mist">{{ __('never') }}</span>
                                        @endif
                                    </td>

                                    <td class="px-3 py-2 sm:px-4">
                                        <div class="flex items-center justify-end transition-opacity focus-within:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('revokeToken', [@js((string) $t->id)], @js(__('Revoke token')), @js(__('Revoke this token? It will stop working immediately.')), @js(__('Revoke')), true)"
                                                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                            >
                                                <x-heroicon-o-no-symbol class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Revoke') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            <x-list-pager
                :page="$tokenPageState['page']"
                :pages="$tokenPageState['pages']"
                :total="$tokenPageState['total']"
                property="token_page"
                :label="__('tokens')"
            />
            @endif

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
                                    <x-heroicon-m-information-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
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
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank for no expiry. Short-lived tokens are safer for CI runners.') }}</p>
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
                            <p class="mt-1 text-xs text-brand-mist">{{ __('IPv4, IPv6, or IPv4 CIDR ranges.') }}</p>
                            <x-input-error :messages="$errors->get('token_allowed_ips_text')" class="mt-2" />
                        </div>

                        @include('livewire.settings.partials.api-token-permission-picker')
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeCreateApiTokenModal">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="createToken"
                            @disabled($createDisabled)
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

            {{-- Scope editor: the same picker against an existing token. Name,
                 expiry, and IPs are deliberately not editable here — widening
                 or narrowing what a live token can do is the one change worth
                 making without reissuing it. --}}
            <x-modal
                name="edit-api-token-abilities-modal"
                :show="false"
                maxWidth="2xl"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
                focusable
            >
                <form wire:submit="updateTokenAbilities" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                        <x-icon-badge>
                            <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Permissions') }}</p>
                            <h2 class="mt-1 truncate text-lg font-semibold text-brand-ink">{{ $editing_token_name ?? __('Edit scopes') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">
                                {{ __('Changes apply to the existing token immediately — the token value does not change.') }}
                            </p>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                        @if ($isDeployerRole)
                            <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                                <span class="inline-flex items-center gap-1.5 font-semibold">
                                    <x-heroicon-m-information-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Deploy-only role') }}
                                </span>
                                <p class="mt-1 text-xs leading-relaxed">{{ __('Tokens can only include server and site read + deploy permissions, matching organization policy.') }}</p>
                            </div>
                        @endif

                        @include('livewire.settings.partials.api-token-permission-picker')
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeEditTokenAbilitiesModal">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="updateTokenAbilities"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="updateTokenAbilities" class="inline-flex items-center gap-2">
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Save scopes') }}
                            </span>
                            <span wire:loading wire:target="updateTokenAbilities" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif
    </x-profile-shell>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
