<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Serverless'), 'href' => route('serverless.index'), 'icon' => 'bolt'],
        ['label' => __('Create'), 'icon' => 'plus'],
    ]" />

    <x-livewire-validation-errors class="mt-4" />

    <form wire:submit="create" class="mt-4">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start">
            <div class="min-w-0">
                <x-profile-shell
                    :title="__('Create a serverless app')"
                    :description="__('Deploy an HTTP-triggered function — no machine to provision. Point us at a repo and we handle the rest.')"
                    icon="heroicon-o-bolt"
                >
                    <x-slot:actions>
                        <x-outline-link href="{{ route('serverless.index') }}" wire:navigate>
                            <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Back to Serverless') }}
                        </x-outline-link>
                    </x-slot:actions>

                    <x-slot:stats>
                        <div class="flex flex-wrap gap-2 text-xs text-brand-moss">
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2.5 py-1 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-bolt class="h-3.5 w-3.5 text-brand-gold" aria-hidden="true" />
                                {{ __('HTTP triggers') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2.5 py-1 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-cpu-chip class="h-3.5 w-3.5 text-brand-sage" aria-hidden="true" />
                                {{ __('Auto runtime') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2.5 py-1 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                                {{ __('No servers') }}
                            </span>
                        </div>
                    </x-slot:stats>

                    @if ($credentials->isEmpty() && ! $managedAvailable)
                        <div class="border-b border-brand-ink/10 bg-amber-50/60 px-5 py-4 sm:px-6 dark:bg-amber-950/20">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <x-icon-badge tone="amber">
                                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                                    </x-icon-badge>
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Connect a DigitalOcean credential first') }}</h3>
                                        <p class="mt-1 max-w-2xl text-sm text-brand-moss">{{ __('Serverless functions deploy through your DO account.') }}</p>
                                    </div>
                                </div>
                                <a href="{{ route('credentials.index') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-brand-cream hover:bg-brand-ink/90">
                                    {{ __('Add credentials') }}
                                </a>
                            </div>
                        </div>
                    @else
                        {{-- Demo quick-start --}}
                        <section class="border-b border-brand-ink/10">
                            <div class="flex flex-col gap-3 bg-brand-gold/10 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __('New to serverless? Start from a demo.') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Prefills the form — just pick a region and credential, then Create.') }}</p>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <button type="button" wire:click="loadPhpDemo"
                                            class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest whitespace-nowrap">
                                        {{ __('Load PHP demo') }}
                                    </button>
                                    <button type="button" wire:click="loadLaravelDemo"
                                            class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest whitespace-nowrap">
                                        {{ __('Load Laravel demo') }}
                                    </button>
                                </div>
                            </div>
                        </section>

                        @if ($managedAvailable)
                            {{-- 01 Hosting --}}
                            <section class="border-b border-brand-ink/10">
                                <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-3 sm:px-6">
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">01</span>
                                    <div class="min-w-0">
                                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Where should it run?') }}</h2>
                                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Dply-hosted or your own DigitalOcean account.') }}</p>
                                    </div>
                                </div>
                                <div class="min-w-0 space-y-4 px-5 py-4 sm:px-6">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 transition {{ $delivery_mode === 'managed' ? 'border-brand-gold bg-brand-gold/10' : 'border-brand-ink/15 bg-white hover:border-brand-sage/40 dark:border-brand-mist/25 dark:bg-zinc-800/40' }}">
                                            <input type="radio" wire:model.live="delivery_mode" value="managed" class="mt-0.5 text-brand-gold focus:ring-brand-gold/40">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-semibold text-brand-ink">{{ __('Dply-hosted (managed)') }}</span>
                                                <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Runs on dply\'s infrastructure. No provider account to connect — pay a flat fee plus metered usage.') }}</span>
                                            </span>
                                        </label>
                                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 transition {{ $delivery_mode === 'byo' ? 'border-brand-gold bg-brand-gold/10' : 'border-brand-ink/15 bg-white hover:border-brand-sage/40 dark:border-brand-mist/25 dark:bg-zinc-800/40' }}">
                                            <input type="radio" wire:model.live="delivery_mode" value="byo" class="mt-0.5 text-brand-gold focus:ring-brand-gold/40">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-semibold text-brand-ink">{{ __('Your own account') }}</span>
                                                <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Deploys to your connected DigitalOcean account. DigitalOcean bills you for usage; dply charges the flat fee.') }}</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </section>
                        @endif

                        {{-- Source --}}
                        @php $sourceStep = $managedAvailable ? '02' : '01'; @endphp
                        <section class="border-b border-brand-ink/10">
                            <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">{{ $sourceStep }}</span>
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Source') }}</h2>
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Function name and Git repository.') }}</p>
                                </div>
                            </div>
                            <div class="min-w-0 space-y-4 px-5 py-4 sm:px-6">
                                <div>
                                    <x-input-label for="name" :value="__('Function name')" />
                                    <x-text-input id="name" wire:model.live="name" type="text" class="mt-1 block w-full" required placeholder="my-api" />
                                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="repo" :value="__('Git repository')" />
                                        <div class="relative mt-1">
                                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                                <x-heroicon-o-code-bracket class="h-4 w-4" />
                                            </span>
                                            <x-text-input id="repo" wire:model.live="repo" type="text" class="block w-full ps-10 font-mono text-sm" required placeholder="owner/repo" />
                                        </div>
                                        <p class="mt-1 text-xs text-brand-mist">{{ __('owner/repo or a full GitHub URL') }}</p>
                                        <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-input-label for="branch" :value="__('Branch')" />
                                        <div class="relative mt-1">
                                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                                <x-heroicon-o-arrow-trending-up class="h-4 w-4" />
                                            </span>
                                            <x-text-input id="branch" wire:model.live="branch" type="text" class="block w-full ps-10 font-mono text-sm" required placeholder="main" />
                                        </div>
                                        <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                                    </div>
                                </div>
                            </div>
                        </section>

                        {{-- Detect --}}
                        @php $detectStep = $managedAvailable ? '03' : '02'; @endphp
                        <section class="border-b border-brand-ink/10">
                            <div class="flex items-start gap-4 px-5 py-5 sm:px-6">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">{{ $detectStep }}</span>
                                <div class="min-w-0 flex-1 space-y-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Detect runtime') }}</h2>
                                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Preview the runtime dply detects in this repo before you deploy.') }}</p>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="detectFromRepository"
                                            wire:loading.attr="disabled"
                                            wire:target="detectFromRepository"
                                            class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-60 dark:shadow-none"
                                        >
                                            <x-heroicon-o-sparkles wire:loading.remove wire:target="detectFromRepository" class="h-4 w-4" aria-hidden="true" />
                                            <x-spinner wire:loading wire:target="detectFromRepository" size="sm" variant="cream" />
                                            <span wire:loading.remove wire:target="detectFromRepository">{{ __('Detect runtime') }}</span>
                                            <span wire:loading wire:target="detectFromRepository">{{ __('Detecting…') }}</span>
                                        </button>
                                    </div>
                                    <div class="rounded-xl border border-brand-ink/8 bg-brand-cream/40 p-4 dark:border-brand-mist/15 dark:bg-zinc-800/50">
                                        @include('livewire.partials._runtime-detection-panel')
                                    </div>
                                </div>
                            </div>
                        </section>

                        {{-- Runtime & region --}}
                        @php $runtimeStep = $managedAvailable ? '04' : '03'; @endphp
                        <section class="border-b border-brand-ink/10">
                            <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-3 sm:px-6">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-gold/15 text-sm font-bold text-brand-olive ring-1 ring-brand-gold/25 dark:bg-brand-gold/10 dark:text-brand-gold dark:ring-brand-gold/20">{{ $runtimeStep }}</span>
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Runtime') }}</h2>
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Runtime, region, and credential.') }}</p>
                                </div>
                            </div>
                            <div class="min-w-0 space-y-4 px-5 py-4 sm:px-6">
                                <div class="grid gap-4 {{ $delivery_mode === 'byo' ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
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
                                            <x-input-label for="provider_credential_id" :value="__('DO credential')" />
                                            @if ($credentials->isEmpty())
                                                <p class="mt-1 text-xs text-amber-700">
                                                    {{ __('No DigitalOcean credential yet.') }}
                                                    <a href="{{ route('credentials.index') }}" wire:navigate class="font-semibold underline underline-offset-2">{{ __('Add one') }}</a>
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
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="create">
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
</div>
