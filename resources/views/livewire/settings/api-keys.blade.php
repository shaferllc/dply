<div>
    <x-livewire-validation-errors />

    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Profile'), 'href' => route('profile.edit'), 'icon' => 'user-circle'],
        ['label' => __('API keys'), 'icon' => 'bolt'],
    ]" />

    <div class="space-y-8">
        @if ($adminOrganizations->isEmpty())
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                {{ __('You need to be an organization admin to create API tokens. Ask an owner to promote you or create an organization first.') }}
            </div>
        @else
            @if ($requiresPaidPlan && $organization && ! $orgHasProPlan)
                <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950" role="status">
                    {{ __('API token creation requires an active Pro subscription for the selected organization. You can still revoke existing tokens.') }}
                </div>
            @endif

            @if ($new_token_plaintext)
                <div class="rounded-2xl border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-950 space-y-3" role="status">
                    <p class="font-medium">{{ __('Copy this token now. You will not see it again.') }}</p>
                    <p class="text-xs text-green-900/80">{{ __('Token name:') }} <span class="font-semibold">{{ $new_token_name }}</span></p>
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="flex-1 min-w-0 break-all rounded-lg bg-white/80 px-3 py-2 font-mono text-xs text-brand-ink border border-green-200">{{ $new_token_plaintext }}</code>
                        <button
                            type="button"
                            x-data="{ copied: false }"
                            x-on:click="navigator.clipboard.writeText(@js($new_token_plaintext)); copied = true; setTimeout(() => copied = false, 2000)"
                            class="shrink-0 rounded-lg bg-brand-ink px-3 py-2 text-xs font-medium text-white hover:bg-brand-ink/90"
                        >
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </button>
                    </div>
                    <button type="button" wire:click="clearNewToken" class="text-sm font-medium text-green-900 underline hover:no-underline">
                        {{ __('Dismiss') }}
                    </button>
                </div>
            @endif

            <div class="dply-card overflow-hidden">
                <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                    <div class="lg:col-span-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('API keys') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Create personal access tokens for automation and API access. Tokens are scoped to an organization and can be restricted by IP.') }}
                        </p>
                    </div>
                    <div class="lg:col-span-8 flex flex-wrap items-start justify-end gap-3">
                        <x-outline-link href="{{ route('docs.index') }}" wire:navigate>
                            <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Documentation') }}
                        </x-outline-link>
                        <x-outline-link href="{{ route('docs.api') }}" wire:navigate>
                            <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('HTTP API guide') }}
                        </x-outline-link>
                    </div>
                </div>
            </div>

            @php
                $hasApiTokenSearch = trim($token_list_search ?? '') !== '';
            @endphp

            <x-table-card
                stack-toolbar
                :title="__('Your tokens')"
                :subtitle="__('Personal access tokens for the HTTP API. Revoke tokens you no longer need.')"
            >
                @if ($tokens->isNotEmpty())
                    <x-slot name="actions">
                        <button
                            type="button"
                            wire:click="openCreateApiTokenModal"
                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-transparent bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-5 w-5 shrink-0" aria-hidden="true" />
                            {{ __('Add API token') }}
                        </button>
                    </x-slot>
                @endif
                <x-slot name="search">
                    <label for="api_token_search" class="sr-only">{{ __('Search') }}</label>
                    <x-text-input
                        id="api_token_search"
                        type="search"
                        wire:model.live.debounce.300ms="token_list_search"
                        placeholder="{{ __('Search by name…') }}"
                        class="block w-full"
                        autocomplete="off"
                    />
                </x-slot>

                @if (! $hasApiTokenSearch && $tokens->isEmpty())
                    <x-table-card-empty>
                        <div class="flex max-w-md flex-col items-center gap-4">
                            <div class="flex h-14 w-14 items-center justify-center rounded-2xl border border-brand-ink/10 bg-brand-sand/40" aria-hidden="true">
                                <x-heroicon-o-bolt class="h-7 w-7 text-brand-moss" />
                            </div>
                            <p class="text-sm text-brand-moss">{{ __('No API tokens yet.') }}</p>
                            <button
                                type="button"
                                wire:click="openCreateApiTokenModal"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/12 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:border-brand-ink/20 hover:bg-brand-sand/30"
                            >
                                <x-heroicon-o-plus class="h-5 w-5 shrink-0" aria-hidden="true" />
                                {{ __('Add API token') }}
                            </button>
                        </div>
                    </x-table-card-empty>
                @elseif ($hasApiTokenSearch && $tokens->isEmpty())
                    <x-table-card-empty>{{ __('No tokens match your search.') }}</x-table-card-empty>
                @else
                    <ul class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                        @foreach ($tokens as $t)
                            <li class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                <div class="min-w-0">
                                    <p class="font-medium text-brand-ink">{{ $t->name }}</p>
                                    <p class="mt-0.5 font-mono text-xs text-brand-moss">{{ $t->masked_display }}</p>
                                    @if ($t->abilities)
                                        <p class="mt-2 break-words font-mono text-xs text-brand-mist">{{ implode(', ', $t->abilities) }}</p>
                                    @endif
                                    @if ($t->allowed_ips)
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('IPs:') }} {{ implode(', ', $t->allowed_ips) }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-brand-mist">
                                        @if ($t->last_used_at)
                                            {{ __('Last used :time', ['time' => $t->last_used_at->diffForHumans()]) }}
                                        @else
                                            {{ __('Never used') }}
                                        @endif
                                        @if ($t->expires_at)
                                            · {{ __('Expires :date', ['date' => $t->expires_at->format('M j, Y')]) }}
                                        @endif
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('revokeToken', [{{ $t->id }}], @js(__('Revoke token')), @js(__('Revoke this token? It will stop working immediately.')), @js(__('Revoke')), true)"
                                    class="shrink-0 text-sm font-medium text-red-700 hover:text-red-900"
                                >
                                    {{ __('Revoke') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-table-card>

            <x-modal
                name="create-api-token-modal"
                :show="false"
                maxWidth="2xl"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
                focusable
            >
                <form wire:submit="createToken" class="flex min-h-0 flex-1 flex-col">
                    <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Personal access token') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Create token') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('Use tokens to authenticate to the dply HTTP API from CI/CD or scripts.') }}
                        </p>
                    </div>

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                        <div>
                            <x-input-label for="api_org_modal" :value="__('Organization')" />
                            <select
                                id="api_org_modal"
                                wire:model.live="organization_id"
                                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach ($adminOrganizations as $o)
                                    <option value="{{ $o->id }}">{{ $o->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($isDeployerRole)
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/40 px-4 py-3 text-sm text-brand-moss">
                                {{ __('Your role is deploy-only: tokens can only include server and site read and deploy permissions, matching organization policy.') }}
                            </div>
                        @endif

                        <div>
                            <x-input-label for="api_token_name_modal" :value="__('Name')" />
                            <x-text-input id="api_token_name_modal" wire:model="token_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. CI deploy') }}" autocomplete="off" />
                            <x-input-error :messages="$errors->get('token_name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="api_token_exp_modal" :value="__('Expires (optional)')" />
                            <x-text-input id="api_token_exp_modal" wire:model="token_expires_at" type="date" class="mt-1 block w-full" min="{{ date('Y-m-d', strtotime('+1 day')) }}" />
                            <x-input-error :messages="$errors->get('token_expires_at')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="api_token_ips_modal" :value="__('Whitelist IP addresses')" />
                            <textarea
                                id="api_token_ips_modal"
                                wire:model="token_allowed_ips_text"
                                rows="3"
                                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                placeholder="{{ __('Comma-separated or one per line. Leave empty to allow any IP.') }}"
                            ></textarea>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('You may enter IPv4, IPv6, or IPv4 CIDR ranges.') }}</p>
                            <x-input-error :messages="$errors->get('token_allowed_ips_text')" class="mt-2" />
                        </div>

                        @if (count($selected_abilities) === 0)
                            <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950" role="status">
                                {{ __('You currently do not have any permissions selected. Select at least one permission to use this token with the API.') }}
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="toggleAllPermissions"
                                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50"
                            >
                                {{ __('Toggle all permissions') }}
                            </button>
                            <x-input-error :messages="$errors->get('selected_abilities')" class="!mt-0" />
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($permissionCategories as $cat)
                                @php
                                    $catId = $cat['id'];
                                    $perms = $cat['permissions'] ?? [];
                                    $abilityList = collect($perms)->pluck('ability')->all();
                                    $selectedInCat = count(array_intersect($selected_abilities, $abilityList));
                                    $totalInCat = count($abilityList);
                                    $expanded = in_array($catId, $expanded_categories, true);
                                @endphp
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 overflow-hidden">
                                    <button
                                        type="button"
                                        wire:click="toggleCategoryExpand('{{ $catId }}')"
                                        class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                                        aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                                    >
                                        <span>{{ $cat['label'] }}</span>
                                        <span class="flex items-center gap-2 text-xs text-brand-moss font-normal">
                                            ({{ $selectedInCat }}/{{ $totalInCat }})
                                            <x-heroicon-m-chevron-down class="h-4 w-4 transition-transform {{ $expanded ? 'rotate-180' : '' }}" aria-hidden="true" />
                                        </span>
                                    </button>
                                    @if ($expanded)
                                        <div class="border-t border-brand-ink/10 px-3 py-3 space-y-2">
                                            @foreach ($perms as $p)
                                                @php $ab = $p['ability']; @endphp
                                                <label class="flex items-center gap-2 cursor-pointer text-sm text-brand-moss">
                                                    <input
                                                        type="checkbox"
                                                        wire:click.prevent="toggleAbility('{{ $ab }}')"
                                                        @checked(in_array($ab, $selected_abilities, true))
                                                        class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage"
                                                    />
                                                    <span>{{ $p['label'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeCreateApiTokenModal">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="createToken"
                            @disabled($requiresPaidPlan && $organization && ! $orgHasProPlan)
                            class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="createToken">{{ __('Create token') }}</span>
                            <span wire:loading wire:target="createToken" class="inline-flex items-center justify-center gap-2">
                                <x-spinner variant="cream" />
                                {{ __('Creating…') }}
                            </span>
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
