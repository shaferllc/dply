<div>
    <div class="dply-page-shell max-w-5xl py-8 sm:py-10">
        <x-livewire-validation-errors />

        <x-breadcrumb-trail
            :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Authorize device'), 'icon' => 'command-line'],
            ]"
            wrapperClass="mb-5"
        />

        <div class="space-y-6">
        @if ($completedState === 'approved')
            <section class="dply-card overflow-hidden">
                <div class="grid gap-0 lg:grid-cols-2 lg:divide-x lg:divide-brand-ink/10">
                    <div class="space-y-4 px-6 py-10 text-center sm:px-10 lg:py-12">
                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                            <x-heroicon-o-check-circle class="h-8 w-8" aria-hidden="true" />
                        </div>
                        <h1 class="text-xl font-semibold text-brand-ink">{{ __('You\'re connected') }}</h1>
                        <p class="mx-auto max-w-sm text-sm leading-relaxed text-brand-moss">
                            {{ __('Switch back to your terminal — the login finished and you should now be in the dply shell.') }}
                        </p>
                        <p class="text-xs text-brand-mist">{{ __('You can close this tab.') }}</p>
                    </div>
                    <div class="border-t border-brand-ink/10 bg-brand-ink px-5 py-6 font-mono text-sm text-brand-cream lg:border-t-0 lg:py-10">
                        <p class="text-emerald-400">✓ {{ __('Logged in to') }} {{ rtrim(config('app.url'), '/') }}</p>
                        <p class="mt-3 text-brand-cream/90">{{ __('dply shell — press Enter for the menu, or type help · exit to leave') }}</p>
                        <p class="mt-4"><span class="text-brand-cream/60">dply</span><span class="text-brand-sage">›</span> <span class="animate-pulse text-brand-cream/50">_</span></p>
                        <p class="mt-4 text-xs text-brand-cream/50">{{ __('Try: server list · server system-users list --server <id> · whoami') }}</p>
                    </div>
                </div>
            </section>
        @elseif ($completedState === 'denied')
            <section class="dply-card overflow-hidden">
                <div class="space-y-4 px-6 py-12 text-center sm:px-10 sm:py-14">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                        <x-heroicon-o-x-circle class="h-8 w-8" aria-hidden="true" />
                    </div>
                    <h1 class="text-xl font-semibold text-brand-ink">{{ __('Request denied') }}</h1>
                    <p class="mx-auto max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('No token was issued. Re-run `dply login` if you change your mind.') }}
                    </p>
                </div>
            </section>
        @elseif ($resolvedUserCode === null)
            <section class="dply-card overflow-hidden">
                <form wire:submit.prevent="lookup">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Device') }}</p>
                            <h1 class="mt-0.5 text-lg font-semibold text-brand-ink">{{ __('Authorize device') }}</h1>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('The dply CLI is asking to connect to your account. Enter the code printed in your terminal to continue.') }}
                            </p>
                        </div>
                    </div>
                    <div class="grid gap-8 px-6 py-8 sm:px-8 lg:grid-cols-2 lg:items-start">
                        <div class="space-y-2">
                            <x-input-label for="device-user-code" :value="__('Code from terminal')" />
                            <x-text-input
                                id="device-user-code"
                                wire:model="userCode"
                                type="text"
                                autocomplete="off"
                                spellcheck="false"
                                autocapitalize="characters"
                                placeholder="WXYZ-ABCD"
                                class="mt-1 block w-full font-mono text-xl tracking-[0.2em] uppercase"
                            />
                            <x-input-error :messages="$errors->get('userCode')" class="mt-2" />
                            <p class="text-xs text-brand-moss">
                                {{ __('Codes are 8 characters and expire after 15 minutes.') }}
                            </p>
                        </div>
                        <div class="flex flex-col justify-end gap-4 lg:pt-7">
                            <p class="text-sm text-brand-moss">
                                {{ __('This is the same device-flow pattern used by GitHub CLI and Stripe CLI — approve once, then the terminal receives a token.') }}
                            </p>
                            <button
                                type="submit"
                                class="inline-flex w-full items-center justify-center px-5 py-2.5 rounded-xl bg-brand-ink text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest sm:w-auto"
                            >
                                {{ __('Continue') }}
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        @else
            @php
                $formattedCode = str_pad(substr($resolvedUserCode, 0, 4), 4).'-'.substr($resolvedUserCode, 4, 4);
                $scopeGroups = [
                    __('Billing') => array_values(array_filter($availableScopes, fn (array $s): bool => str_starts_with($s['ability'], 'billing.'))),
                    __('Account & CLI') => array_values(array_filter($availableScopes, fn (array $s): bool => str_starts_with($s['ability'], 'account.'))),
                    __('Edge') => array_values(array_filter($availableScopes, fn (array $s): bool => str_starts_with($s['ability'], 'edge.'))),
                    __('Servers & sites') => array_values(array_filter($availableScopes, fn (array $s): bool => str_starts_with($s['ability'], 'servers.') || str_starts_with($s['ability'], 'sites.'))),
                    __('System users') => array_values(array_filter($availableScopes, fn (array $s): bool => str_starts_with($s['ability'], 'system_users.'))),
                ];
                $scopeGroups = array_filter($scopeGroups, fn (array $group): bool => $group !== []);
            @endphp

            <section class="dply-card overflow-hidden">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-shield-check class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Authorize') }}</p>
                            <h1 class="mt-0.5 text-lg font-semibold text-brand-ink">{{ __('Approve dply CLI?') }}</h1>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Approving creates an API token for the CLI. Revoke it anytime from Profile → CLI.') }}
                            </p>
                        </div>
                    </div>
                    <div class="shrink-0 rounded-xl border border-brand-ink/15 bg-brand-ink px-4 py-3 text-center sm:min-w-[10rem]">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-cream/70">{{ __('Terminal code') }}</p>
                        <p class="mt-1 font-mono text-xl font-semibold tracking-[0.18em] text-brand-cream">{{ $formattedCode }}</p>
                    </div>
                </div>

                <div class="grid lg:grid-cols-12 lg:divide-x lg:divide-brand-ink/10">
                    {{-- Left: org + actions --}}
                    <div class="space-y-6 border-b border-brand-ink/10 px-6 py-6 sm:px-8 lg:col-span-4 lg:border-b-0 lg:py-8">
                        <div>
                            <x-input-label for="device-organization" :value="__('Organization')" />
                            @if ($organizations->isEmpty())
                                <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                    {{ __('You are not a member of any organization yet — create one before approving the CLI.') }}
                                </p>
                            @else
                                <select
                                    id="device-organization"
                                    wire:model.live="organizationId"
                                    class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                >
                                    @foreach ($organizations as $org)
                                        <option value="{{ $org->id }}">{{ $org->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1.5 text-xs leading-relaxed text-brand-moss">
                                    {{ __('The CLI will only act inside this organization.') }}
                                </p>
                            @endif
                        </div>

                        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
                            <p class="text-xs leading-relaxed text-brand-moss">
                                {{ __('After approval, return to your terminal — you\'ll land in `dply shell` with server and system-user commands ready.') }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row lg:flex-col">
                            <button
                                type="button"
                                wire:click="approve"
                                @disabled($organizations->isEmpty())
                                class="inline-flex w-full items-center justify-center rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {{ __('Approve CLI access') }}
                            </button>
                            <button
                                type="button"
                                wire:click="deny"
                                class="inline-flex w-full items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-5 py-2.5 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/40"
                            >
                                {{ __('Deny') }}
                            </button>
                        </div>
                    </div>

                    {{-- Right: permissions --}}
                    <div class="px-6 py-6 sm:px-8 lg:col-span-8 lg:py-8">
                        <fieldset class="min-w-0">
                            <legend class="text-sm font-semibold text-brand-ink">{{ __('Permissions') }}</legend>
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Uncheck anything you do not want this CLI session to use. Your org role caps what appears here.') }}
                            </p>

                            <div class="mt-4 max-h-[min(28rem,55vh)] space-y-5 overflow-y-auto pr-1">
                                @foreach ($scopeGroups as $groupLabel => $scopes)
                                    <div>
                                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $groupLabel }}</p>
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            @foreach ($scopes as $scope)
                                                <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 text-sm transition hover:border-brand-sage/40 hover:bg-brand-sand/20">
                                                    <input
                                                        type="checkbox"
                                                        wire:click="toggleAbility(@js($scope['ability']))"
                                                        @checked(in_array($scope['ability'], $selectedAbilities, true))
                                                        class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest"
                                                    />
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-mono text-[11px] text-brand-moss">{{ $scope['ability'] }}</span>
                                                        <span class="mt-0.5 block text-xs leading-snug text-brand-ink">{{ $scope['label'] }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('selectedAbilities')" class="mt-3" />
                        </fieldset>
                    </div>
                </div>
            </section>
        @endif
        </div>
    </div>
</div>
