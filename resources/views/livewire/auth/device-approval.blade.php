<div>
    <x-livewire-validation-errors />

    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Authorize device'), 'icon' => 'computer-desktop'],
    ]" />

    <div class="space-y-8 max-w-3xl mx-auto">
        @if ($completedState === 'approved')
            <div class="dply-card overflow-hidden">
                <div class="p-6 sm:p-10 text-center space-y-4">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-50 text-green-700">
                        <x-heroicon-o-check-circle class="h-7 w-7" aria-hidden="true" />
                    </div>
                    <h1 class="text-xl font-semibold text-brand-ink">{{ __('Device authorized') }}</h1>
                    <p class="text-sm text-brand-moss leading-relaxed">
                        {{ __('Return to your terminal — `dply login` will pick up the new token automatically. You can close this tab.') }}
                    </p>
                </div>
            </div>
        @elseif ($completedState === 'denied')
            <div class="dply-card overflow-hidden">
                <div class="p-6 sm:p-10 text-center space-y-4">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-700">
                        <x-heroicon-o-x-circle class="h-7 w-7" aria-hidden="true" />
                    </div>
                    <h1 class="text-xl font-semibold text-brand-ink">{{ __('Request denied') }}</h1>
                    <p class="text-sm text-brand-moss leading-relaxed">
                        {{ __('No token was issued. Re-run `dply login` if you change your mind.') }}
                    </p>
                </div>
            </div>
        @elseif ($resolvedUserCode === null)
            <div class="dply-card overflow-hidden">
                <form wire:submit.prevent="lookup">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h1 class="text-lg font-semibold text-brand-ink">{{ __('Authorize device') }}</h1>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('The dply CLI is asking to connect to your dply account. Enter the code printed in your terminal to continue.') }}
                            </p>
                        </div>
                        <div class="lg:col-span-8 space-y-5">
                            <div>
                                <x-input-label for="device-user-code" :value="__('Code from terminal')" />
                                <x-text-input
                                    id="device-user-code"
                                    wire:model="userCode"
                                    type="text"
                                    autocomplete="off"
                                    spellcheck="false"
                                    autocapitalize="characters"
                                    placeholder="WXYZ-ABCD"
                                    class="mt-1 block w-full max-w-xs font-mono text-lg tracking-widest uppercase"
                                />
                                <x-input-error :messages="$errors->get('userCode')" class="mt-2" />
                                <p class="mt-2 text-xs text-brand-moss">
                                    {{ __('Codes are 8 characters and expire after 15 minutes.') }}
                                </p>
                            </div>

                            <button
                                type="submit"
                                class="inline-flex items-center px-5 py-2.5 bg-brand-ink border border-transparent rounded-xl font-semibold text-sm text-brand-cream shadow-md hover:bg-brand-forest transition-colors"
                            >
                                {{ __('Continue') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        @else
            <div class="dply-card overflow-hidden">
                <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                    <div class="lg:col-span-4">
                        <h1 class="text-lg font-semibold text-brand-ink">{{ __('Approve dply CLI?') }}</h1>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Approving creates an API token for the CLI to use against your organization. You can revoke it any time from Profile → API keys.') }}
                        </p>
                        <div class="mt-4 rounded-xl border border-brand-mist bg-brand-sand/30 px-3 py-2 text-xs text-brand-moss">
                            <span class="block uppercase tracking-wider text-[10px] text-brand-moss/80">{{ __('Code') }}</span>
                            <span class="font-mono text-base tracking-widest text-brand-ink">{{ str_pad(substr($resolvedUserCode, 0, 4), 4) }}-{{ substr($resolvedUserCode, 4, 4) }}</span>
                        </div>
                    </div>
                    <div class="lg:col-span-8 space-y-5">
                        <div>
                            <x-input-label for="device-organization" :value="__('Organization')" />
                            @if ($organizations->isEmpty())
                                <p class="mt-1 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                    {{ __('You are not a member of any organization yet — create one before approving the CLI.') }}
                                </p>
                            @else
                                <select
                                    id="device-organization"
                                    wire:model.live="organizationId"
                                    class="mt-1 block w-full rounded-lg border-brand-mist shadow-sm focus:border-brand-forest focus:ring-brand-forest text-sm text-brand-ink"
                                >
                                    @foreach ($organizations as $org)
                                        <option value="{{ $org->id }}">{{ $org->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-brand-moss">
                                    {{ __('The CLI will only be able to act inside this organization.') }}
                                </p>
                            @endif
                        </div>

                        <fieldset>
                            <legend class="text-sm font-semibold text-brand-ink">{{ __('Permissions the CLI will have') }}</legend>
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ __('All three scopes are recommended for the default CLI workflow (deploy, manage domains, tail logs).') }}
                            </p>
                            <div class="mt-3 space-y-2">
                                @foreach ($availableScopes as $scope)
                                    <label class="flex items-start gap-3 rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm">
                                        <input
                                            type="checkbox"
                                            wire:click="toggleAbility(@js($scope['ability']))"
                                            @checked(in_array($scope['ability'], $selectedAbilities, true))
                                            class="mt-1 rounded border-brand-mist text-brand-forest focus:ring-brand-forest"
                                        />
                                        <span>
                                            <span class="block font-mono text-xs text-brand-moss">{{ $scope['ability'] }}</span>
                                            <span class="block text-brand-ink">{{ $scope['label'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('selectedAbilities')" class="mt-2" />
                        </fieldset>

                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="approve"
                                @disabled($organizations->isEmpty())
                                class="inline-flex items-center px-5 py-2.5 bg-brand-ink border border-transparent rounded-xl font-semibold text-sm text-brand-cream shadow-md hover:bg-brand-forest transition-colors disabled:opacity-60"
                            >
                                {{ __('Approve') }}
                            </button>
                            <button
                                type="button"
                                wire:click="deny"
                                class="inline-flex items-center px-5 py-2.5 border border-brand-mist rounded-xl font-semibold text-sm text-brand-ink hover:bg-brand-sand/40 transition-colors"
                            >
                                {{ __('Deny') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
