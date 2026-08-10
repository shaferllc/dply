<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Serverless'), 'href' => route('serverless.index'), 'icon' => 'bolt'],
        ['label' => __('Create'), 'icon' => 'plus'],
    ]" />

    <x-livewire-validation-errors class="mt-4" />

    {{-- Creating a function provisions a billable namespace and deploys
         immediately, so a pause-blocked org is stopped here rather than being
         allowed to stand up infrastructure it can't use. --}}
    @if ($deploysPaused)
        <x-trial-pause-banner :organization="$organization" class="mt-4" />
        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50/80 px-5 py-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('You can\'t create a serverless app right now') }}</p>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                {{ __('Creating one provisions a DigitalOcean Functions namespace and starts a deploy — both are billed work, which is paused for this organization. Add a payment method and this form unlocks.') }}
            </p>
        </div>
    @endif

    <form wire:submit="create" class="mt-4">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start">
            <div class="min-w-0">
                <x-profile-shell
                    :title="__('Create a serverless app')"
                    :description="__('Deploy a full web app from Git — Laravel first. No machine to provision; dply handles build, runtime, and the public URL.')"
                    icon="heroicon-o-bolt"
                >
                    <x-slot:actions>
                        <x-outline-link href="{{ route('serverless.index') }}" wire:navigate>
                            <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Back to Serverless') }}
                        </x-outline-link>
                    </x-slot:actions>

                    <x-slot:stats>
                        <div class="flex flex-wrap gap-1.5 text-xs text-brand-moss">
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2 py-0.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-cube class="h-3.5 w-3.5 text-brand-gold" aria-hidden="true" />
                                {{ __('Laravel first') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2 py-0.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-cpu-chip class="h-3.5 w-3.5 text-brand-sage" aria-hidden="true" />
                                {{ __('Auto runtime') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2 py-0.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                                {{ __('No servers') }}
                            </span>
                        </div>
                    </x-slot:stats>

                    @if ($credentials->isEmpty() && ! $managedAvailable)
                        <div class="border-b border-brand-ink/10 bg-amber-50/60 px-5 py-3.5 sm:px-6 dark:bg-amber-950/20">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <x-icon-badge tone="amber">
                                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                                    </x-icon-badge>
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Connect a DigitalOcean credential first') }}</h3>
                                        <p class="mt-1 max-w-2xl text-sm text-brand-moss">{{ __('Serverless apps deploy through your DigitalOcean account.') }}</p>
                                    </div>
                                </div>
                                <x-add-provider-credential-link
                                    provider="digitalocean"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-brand-cream no-underline hover:bg-brand-ink/90"
                                >
                                    {{ __('Add credentials') }}
                                </x-add-provider-credential-link>
                            </div>
                        </div>
                    @else
                        {{-- Demo quick-start --}}
                        <section class="border-b border-brand-ink/10">
                            <div class="flex flex-col gap-2.5 bg-brand-gold/10 px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __('New to serverless? Start from Laravel.') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Prefills a real Laravel app — pick a region and credential, then Create. Or load a minimal PHP demo.') }}</p>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <button type="button" wire:click="loadLaravelDemo"
                                            class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest whitespace-nowrap">
                                        {{ __('Load Laravel demo') }}
                                    </button>
                                    <button type="button" wire:click="loadPhpDemo"
                                            class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 whitespace-nowrap">
                                        {{ __('Load PHP demo') }}
                                    </button>
                                </div>
                            </div>
                        </section>

                        @if ($managedAvailable)
                            {{-- 01 Hosting --}}
                            <section class="border-b border-brand-ink/10">
                                <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-2.5 sm:px-6">
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-xs font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">01</span>
                                    <div class="min-w-0">
                                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Where should it run?') }}</h2>
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Dply-hosted or your own DigitalOcean account.') }}</p>
                                    </div>
                                </div>
                                <div class="min-w-0 space-y-3 px-5 py-3.5 sm:px-6">
                                    <div class="grid gap-2.5 sm:grid-cols-2">
                                        <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border-2 px-3.5 py-2.5 transition {{ $delivery_mode === 'managed' ? 'border-brand-gold bg-brand-gold/10' : 'border-brand-ink/15 bg-white hover:border-brand-sage/40 dark:border-brand-mist/25 dark:bg-zinc-800/40' }}">
                                            <input type="radio" wire:model.live="delivery_mode" value="managed" class="mt-0.5 text-brand-gold focus:ring-brand-gold/40">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-semibold text-brand-ink">{{ __('Dply-hosted (managed)') }}</span>
                                                <span class="mt-0.5 block text-xs leading-snug text-brand-moss">{{ __('No provider account — flat fee plus metered usage.') }}</span>
                                            </span>
                                        </label>
                                        <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border-2 px-3.5 py-2.5 transition {{ $delivery_mode === 'byo' ? 'border-brand-gold bg-brand-gold/10' : 'border-brand-ink/15 bg-white hover:border-brand-sage/40 dark:border-brand-mist/25 dark:bg-zinc-800/40' }}">
                                            <input type="radio" wire:model.live="delivery_mode" value="byo" class="mt-0.5 text-brand-gold focus:ring-brand-gold/40">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-semibold text-brand-ink">{{ __('Your own account') }}</span>
                                                <span class="mt-0.5 block text-xs leading-snug text-brand-moss">{{ __('Your DO account; dply charges the flat fee.') }}</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </section>
                        @endif

                        {{-- Source --}}
                        @php $sourceStep = $managedAvailable ? '02' : '01'; @endphp
                        <section class="border-b border-brand-ink/10">
                            <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-2.5 sm:px-6">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-xs font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">{{ $sourceStep }}</span>
                                <div class="min-w-0">
                                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Source') }}</h2>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('App name and Git repository.') }}</p>
                                </div>
                            </div>
                            <div class="min-w-0 space-y-3 px-5 py-3.5 sm:px-6">
                                <div>
                                    <x-input-label for="name" :value="__('App name')" />
                                    <x-text-input id="name" wire:model.live="name" type="text" class="mt-1 block w-full" required placeholder="my-laravel-app" />
                                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                                </div>

                                <div class="space-y-3">
                                    @include('livewire.sites.partials._git-repository-configurator', ['idPrefix' => 'serverless'])

                                    {{-- Public URL scan already offers a branch select; otherwise use the full ref picker. --}}
                                    @if ($repo_source === 'manual' && $repoScanState === 'found' && count($scannedBranches) > 0)
                                        {{-- branch select lives inside the scan card --}}
                                    @else
                                        @include('livewire.serverless.partials.create-ref-picker')
                                    @endif
                                </div>
                            </div>
                        </section>

                        {{-- Detect --}}
                        @php $detectStep = $managedAvailable ? '03' : '02'; @endphp
                        <section class="border-b border-brand-ink/10">
                            <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-2.5 sm:px-6">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-xs font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">{{ $detectStep }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-2.5">
                                        <div>
                                            <h2 class="text-sm font-semibold text-brand-ink">{{ __('Detect runtime') }}</h2>
                                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Preview the runtime dply detects in this repo before you deploy.') }}</p>
                                        </div>
                                        @php
                                            $detectTargets = 'detectFromRepository,git_repository_url,git_branch,repository_selection,source_control_account_id,repo_source,selectRefPickerValue';
                                        @endphp
                                        <button
                                            type="button"
                                            wire:click="detectFromRepository"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $detectTargets }}"
                                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-3 py-1.5 text-xs font-semibold text-brand-moss transition-colors hover:border-brand-sage/40 hover:text-brand-forest disabled:cursor-wait disabled:opacity-60 dark:border-brand-mist/25 dark:bg-zinc-800 dark:hover:text-brand-sage"
                                        >
                                            <x-heroicon-o-sparkles wire:loading.remove wire:target="{{ $detectTargets }}" class="h-3.5 w-3.5" aria-hidden="true" />
                                            <x-spinner wire:loading wire:target="{{ $detectTargets }}" size="sm" variant="muted" />
                                            <span wire:loading.remove wire:target="{{ $detectTargets }}">{{ __('Detect runtime') }}</span>
                                            <span wire:loading wire:target="{{ $detectTargets }}">{{ __('Detecting…') }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="min-w-0 px-5 py-3.5 sm:px-6">
                                <div class="rounded-xl border border-brand-ink/8 bg-brand-cream/40 p-3 dark:border-brand-mist/15 dark:bg-zinc-800/50">
                                    @include('livewire.partials._runtime-detection-panel')
                                </div>
                            </div>
                        </section>

                        {{-- Runtime & region --}}
                        @php $runtimeStep = $managedAvailable ? '04' : '03'; @endphp
                        <section class="border-b border-brand-ink/10">
                            <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-2.5 sm:px-6">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-gold/15 text-xs font-bold text-brand-olive ring-1 ring-brand-gold/25 dark:bg-brand-gold/10 dark:text-brand-gold dark:ring-brand-gold/20">{{ $runtimeStep }}</span>
                                <div class="min-w-0">
                                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Runtime') }}</h2>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Runtime, region, and credential.') }}</p>
                                </div>
                            </div>
                            <div class="min-w-0 space-y-3 px-5 py-3.5 sm:px-6">
                                <div class="grid gap-3 {{ $delivery_mode === 'byo' ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
                                    <div>
                                        <x-input-label for="runtime" :value="__('Runtime')" />
                                        <select id="runtime" wire:model.live="runtime" class="dply-input mt-1 block w-full" required>
                                            @foreach ($runtimes as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <p class="mt-1 text-xs text-brand-mist">{{ __('Auto-detect picks the runtime from your repository at deploy time.') }}</p>
                                        <x-input-error :messages="$errors->get('runtime')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-input-label for="region" :value="__('Region')" />
                                        <select id="region" wire:model.live="region" class="dply-input mt-1 block w-full" required>
                                            @foreach ($regions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('region')" class="mt-2" />
                                    </div>
                                    @if ($delivery_mode === 'byo')
                                        <div>
                                            <div class="flex items-center justify-between gap-2">
                                                <x-input-label for="provider_credential_id" :value="__('DO credential')" />
                                                <x-add-provider-credential-link provider="digitalocean">
                                                    {{ $credentials->isEmpty() ? __('Add credential') : __('Add another') }}
                                                </x-add-provider-credential-link>
                                            </div>
                                            @if ($credentials->isEmpty())
                                                <p class="mt-1 text-xs text-amber-700">
                                                    {{ __('No DigitalOcean credential yet — connect one to deploy on your own account.') }}
                                                </p>
                                            @else
                                                <select id="provider_credential_id" wire:model.live="provider_credential_id" class="dply-input mt-1 block w-full" required>
                                                    <option value="" disabled>{{ __('Select a credential') }}</option>
                                                    @foreach ($credentials as $credential)
                                                        <option value="{{ $credential->id }}">{{ $credential->name }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-2" />
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </section>
                    @endif

                    <x-slot:footer>
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <a
                                href="{{ route('serverless.index') }}"
                                wire:navigate
                                class="inline-flex items-center justify-center gap-1.5 text-sm font-medium text-brand-moss transition-colors hover:text-brand-ink"
                            >
                                <x-heroicon-m-arrow-left class="h-4 w-4" aria-hidden="true" />
                                {{ __('Back to Serverless') }}
                            </a>
                            @if (! ($credentials->isEmpty() && ! $managedAvailable))
                                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="create"
                                                      :disabled="$deploysPaused"
                                                      :title="$deploysPaused ? __('Deploys are paused — add a payment method to continue.') : null">
                                        <span wire:loading.remove wire:target="create" class="inline-flex items-center gap-2 whitespace-nowrap">
                                            <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Create & deploy') }}
                                        </span>
                                        <span wire:loading wire:target="create" class="inline-flex items-center justify-center gap-2 whitespace-nowrap">
                                            <x-spinner variant="cream" />
                                            {{ __('Creating…') }}
                                        </span>
                                    </x-primary-button>
                                </div>
                            @endif
                        </div>
                    </x-slot:footer>
                </x-profile-shell>
            </div>

            @include('livewire.serverless.partials.create-sidebar', [
                'functionFee' => $functionFee,
                'regions' => $regions,
                'runtimes' => $runtimes,
            ])
        </div>
    </form>

    <livewire:credentials.add-provider-credential-modal
        default-provider="digitalocean"
        capability="compute"
    />
</div>
