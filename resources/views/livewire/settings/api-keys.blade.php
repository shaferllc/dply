<div>
    <x-livewire-validation-errors />

    <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('profile.edit') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Profile') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('API keys') }}</li>
        </ol>
    </nav>

    <header class="mb-8">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('API keys') }}</h1>
        <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
            {{ __('Create personal access tokens for automation and API access. Tokens are scoped to an organization and can be restricted by IP.') }}
        </p>
    </header>

    @if ($adminOrganizations->isEmpty())
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            {{ __('You need to be an organization admin to create API tokens. Ask an owner to promote you or create an organization first.') }}
        </div>
    @else
        @if ($requiresPaidPlan && $organization && ! $orgHasProPlan)
            <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950" role="status">
                {{ __('API token creation requires an active Pro subscription for the selected organization. You can still revoke existing tokens.') }}
            </div>
        @endif

        @if ($new_token_plaintext)
            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-950 space-y-3" role="status">
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

        <div class="space-y-10">
            <section class="dply-card overflow-hidden">
                <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                    <div class="lg:col-span-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Personal access token') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Use tokens to authenticate to the dply HTTP API from CI/CD or scripts.') }}
                        </p>
                        <p class="mt-3 text-sm text-brand-mist leading-relaxed">
                            <a href="{{ route('docs.index') }}" wire:navigate class="text-brand-sage font-medium hover:underline">{{ __('Documentation') }}</a>
                            <span> — {{ __('HTTP API reference: docs/API.md in this repository.') }}</span>
                        </p>
                    </div>
                    <div class="lg:col-span-8 space-y-5">
                        <div>
                            <x-input-label for="api_org" :value="__('Organization')" />
                            <select
                                id="api_org"
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
                            <x-input-label for="api_token_name" :value="__('Name')" />
                            <x-text-input id="api_token_name" wire:model="token_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. CI deploy') }}" autocomplete="off" />
                            <x-input-error :messages="$errors->get('token_name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="api_token_exp" :value="__('Expires (optional)')" />
                            <x-text-input id="api_token_exp" wire:model="token_expires_at" type="date" class="mt-1 block w-full" min="{{ date('Y-m-d', strtotime('+1 day')) }}" />
                            <x-input-error :messages="$errors->get('token_expires_at')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="api_token_ips" :value="__('Whitelist IP addresses')" />
                            <textarea
                                id="api_token_ips"
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

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
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
                                            <svg class="h-4 w-4 transition-transform {{ $expanded ? 'rotate-180' : '' }}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
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

                        <div class="flex justify-end pt-2">
                            <button
                                type="button"
                                wire:click="createToken"
                                wire:loading.attr="disabled"
                                @disabled($requiresPaidPlan && $organization && ! $orgHasProPlan)
                                class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="createToken">{{ __('Create') }}</span>
                                <span wire:loading wire:target="createToken" class="inline-flex items-center justify-center gap-2">
                                    <x-spinner variant="cream" />
                                    {{ __('Creating…') }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="px-6 py-4 border-b border-brand-ink/10 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your tokens') }}</h2>
                    <div class="w-full sm:w-auto sm:max-w-xs">
                        <label for="api_token_search" class="sr-only">{{ __('Search') }}</label>
                        <input
                            id="api_token_search"
                            type="search"
                            wire:model.live.debounce.300ms="token_list_search"
                            placeholder="{{ __('Search…') }}"
                            class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>
                </div>
                @if ($tokens->isEmpty())
                    <div class="px-6 py-10 text-center text-sm text-brand-moss">{{ __('No results found.') }}</div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($tokens as $t)
                            <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-6 py-4">
                                <div class="min-w-0">
                                    <p class="font-medium text-brand-ink">{{ $t->name }}</p>
                                    <p class="text-xs font-mono text-brand-moss mt-0.5">{{ $t->masked_display }}</p>
                                    @if ($t->abilities)
                                        <p class="text-xs text-brand-mist mt-2 font-mono break-words">{{ implode(', ', $t->abilities) }}</p>
                                    @endif
                                    @if ($t->allowed_ips)
                                        <p class="text-xs text-brand-moss mt-1">{{ __('IPs:') }} {{ implode(', ', $t->allowed_ips) }}</p>
                                    @endif
                                    <p class="text-xs text-brand-mist mt-1">
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
            </section>
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
