<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="3" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" :providerHostKind="$form->provider_host_kind" />

    <form wire:submit.prevent="next" class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div class="space-y-8 min-w-0">
        <header class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/20 px-6 py-8 shadow-sm sm:px-10 sm:py-10">
            <div class="absolute -right-12 -top-12 h-44 w-44 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>
            <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>
            <div class="relative">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Step :n of :total', ['n' => 3, 'total' => $totalSteps]) }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ __('What it runs') }}</h1>
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss sm:text-base">{{ __('Pick a stack template and dply fills in everything else. The underlying knobs are tucked below in case you want to override.') }}</p>
            </div>
        </header>

        @php
            $selectedInstallProfile = collect($installProfiles)->firstWhere('id', $form->install_profile);
            $selectedServerRole = collect($provisionOptions['server_roles'] ?? [])->firstWhere('id', $form->server_role);
            $featuredPresets = collect($serverPresets)->where('featured', true);
            $otherPresets = collect($serverPresets)->where('featured', false);
            $hasOverrides = $selectedInstallProfile || $selectedServerRole;
        @endphp

        {{-- 1. THE CHOICE: stack template (was "preset"). --}}
        <section class="relative rounded-2xl border-2 border-brand-sage/20 bg-white p-6 shadow-sm space-y-5 sm:p-7">
            <div class="flex items-start gap-4">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest">
                    <x-heroicon-o-rectangle-stack class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Pick a stack template') }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Each template pre-fills the package bundle, machine job, and stack details. Click to choose.') }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($featuredPresets as $preset)
                    <button
                        type="button"
                        wire:click="applyPreset('{{ $preset['id'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="applyPreset"
                        @class([
                            'group relative flex flex-col items-start rounded-2xl border-2 p-5 text-left shadow-sm transition-all disabled:cursor-wait',
                            'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $selectedPreset === $preset['id'],
                            'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $selectedPreset !== $preset['id'],
                        ])
                    >
                        @if ($preset['id'] === 'polyglot')
                            <span class="mb-2 inline-flex items-center gap-1 rounded-full bg-brand-gold/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-gold ring-1 ring-brand-gold/30">{{ __('Differentiator') }}</span>
                        @endif
                        <span class="text-sm font-semibold text-brand-ink">{{ $preset['name'] }}</span>
                        <span class="mt-1 text-xs leading-5 text-brand-moss">{{ $preset['description'] }}</span>
                        @if ($selectedPreset === $preset['id'])
                            <span class="absolute right-3 top-3 inline-flex items-center gap-0.5 rounded-full bg-brand-sage px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                                <x-heroicon-m-check class="h-3 w-3" />
                                {{ __('Picked') }}
                            </span>
                        @endif
                        {{-- Per-tile spinner when this specific tile was clicked.
                             wire:target accepts the method+arg signature so only
                             the active tile lights up, not all of them. --}}
                        <span
                            wire:loading
                            wire:target="applyPreset('{{ $preset['id'] }}')"
                            class="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full bg-brand-ink/85 px-2 py-0.5 text-[10px] font-semibold text-brand-cream shadow-sm"
                        >
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Applying…') }}
                        </span>
                    </button>
                @endforeach
            </div>

            <details class="text-sm" @if ($selectedPreset !== '' && ! $featuredPresets->pluck('id')->contains($selectedPreset)) open @endif>
                <summary class="cursor-pointer font-medium text-brand-moss transition-colors hover:text-brand-ink">{{ __('Other templates (Static / Database node / Custom)') }}</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    @foreach ($otherPresets as $preset)
                        <button
                            type="button"
                            wire:click="applyPreset('{{ $preset['id'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="applyPreset"
                            @class([
                                'relative flex flex-col items-start rounded-2xl border-2 p-4 text-left shadow-sm transition-all disabled:cursor-wait',
                                'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $selectedPreset === $preset['id'],
                                'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $selectedPreset !== $preset['id'],
                            ])
                        >
                            <span class="text-sm font-semibold text-brand-ink">{{ $preset['name'] }}</span>
                            <span class="mt-1 text-xs leading-5 text-brand-moss">{{ $preset['description'] }}</span>
                            <span
                                wire:loading
                                wire:target="applyPreset('{{ $preset['id'] }}')"
                                class="absolute right-2 top-2 inline-flex items-center gap-1 rounded-full bg-brand-ink/85 px-2 py-0.5 text-[10px] font-semibold text-brand-cream shadow-sm"
                            >
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Applying…') }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </details>

            {{-- Section-wide loading veil. Sits above the tile grid so the
                 form looks "frozen" while Livewire round-trips applyPreset.
                 Pointer-events on so accidental double-taps are absorbed. --}}
            <div
                wire:loading
                wire:target="applyPreset"
                class="absolute inset-0 z-10 flex items-center justify-center rounded-2xl bg-white/55 backdrop-blur-[1px]"
            >
                <span class="inline-flex items-center gap-2 rounded-full bg-brand-ink px-4 py-2 text-xs font-semibold text-brand-cream shadow-md shadow-brand-ink/15">
                    <x-spinner variant="cream" size="sm" />
                    {{ __('Filling in template…') }}
                </span>
            </div>
        </section>

        {{-- 2. WHAT THE TEMPLATE FILLED IN: read-at-a-glance summary chips.
             Only renders once the operator picks a template — otherwise the
             form's baked-in defaults (Laravel app / nginx / 8.3 / …) would
             masquerade as a chosen template before any click. --}}
        @if ($selectedPreset !== '')
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/40 p-5">
                <div class="flex items-baseline justify-between gap-2">
                    <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                        <x-heroicon-m-sparkles class="h-3.5 w-3.5" />
                        {{ __('Template filled in') }}
                    </p>
                    <p class="text-[11px] text-brand-mist">{{ __('Override below if needed') }}</p>
                </div>
                <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
                    @if ($selectedInstallProfile)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Bundle') }}</span>
                            <span class="font-medium text-brand-ink">{{ $selectedInstallProfile['label'] }}</span>
                        </span>
                    @endif
                    @if ($selectedServerRole)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Job') }}</span>
                            <span class="font-medium text-brand-ink">{{ $selectedServerRole['label'] }}</span>
                        </span>
                    @endif
                    @if ($form->webserver)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Web') }}</span>
                            <span class="font-medium text-brand-ink">{{ $form->webserver }}</span>
                        </span>
                    @endif
                    @if ($form->php_version)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('PHP') }}</span>
                            <span class="font-medium text-brand-ink">{{ $form->php_version }}</span>
                        </span>
                    @endif
                    @if ($form->database)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('DB') }}</span>
                            <span class="font-medium text-brand-ink">{{ $form->database }}</span>
                        </span>
                    @endif
                    @if ($form->cache_service)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cache') }}</span>
                            <span class="font-medium text-brand-ink">{{ $form->cache_service }}</span>
                        </span>
                    @endif
                    @if ($form->ruby_version !== '')
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Ruby') }}</span>
                            <span class="font-medium text-brand-ink">{{ $form->ruby_version }}</span>
                        </span>
                    @endif
                    @if ($form->node_version !== '')
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Node') }}</span>
                            <span class="font-medium text-brand-ink">{{ $form->node_version }}</span>
                        </span>
                    @endif
                    @if ($form->python_version !== '')
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Python') }}</span>
                            <span class="font-medium text-brand-ink">{{ $form->python_version }}</span>
                        </span>
                    @endif
                    @if ($form->go_version !== '')
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Go') }}</span>
                            <span class="font-medium text-brand-ink">{{ $form->go_version }}</span>
                        </span>
                    @endif
                </div>
            </div>
        @endif

        {{-- 3. POWER-USER OVERRIDES: collapsed by default. --}}
        <details class="group rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-start gap-4 px-6 py-5 sm:px-7">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest transition-colors group-hover:bg-brand-sage/15">
                    <x-heroicon-o-adjustments-horizontal class="h-5 w-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-baseline justify-between gap-3">
                        <span class="text-base font-semibold text-brand-ink">{{ __('Override the template') }}</span>
                        <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform group-open:rotate-180" />
                    </span>
                    <span class="mt-1 block text-sm text-brand-moss">{{ __('Pick a different package bundle, change the machine\'s job, or swap individual stack components. Most setups don\'t need this.') }}</span>
                </span>
            </summary>

            <div class="border-t border-brand-ink/10 px-6 py-6 space-y-6 sm:px-7">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('High-level controls') }}</p>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Profile bundles a default package set; role narrows what actually installs (web vs db node vs LB).') }}</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        @include('livewire.servers.create._rich-select', [
                            'id' => 'install_profile',
                            'label' => __('Package bundle (profile)'),
                            'field' => 'form.install_profile',
                            'value' => $form->install_profile,
                            'options' => collect($installProfiles)->map(fn ($p) => [
                                'id' => (string) ($p['id'] ?? ''),
                                'label' => (string) ($p['label'] ?? ''),
                                'summary' => (string) ($p['summary'] ?? ''),
                            ])->all(),
                            'errorKey' => 'form.install_profile',
                            'eyebrow' => __('Bundle'),
                            'placeholder' => __('Choose a bundle'),
                        ])
                        @include('livewire.servers.create._rich-select', [
                            'id' => 'server_role',
                            'label' => __('Machine\'s job (role)'),
                            'field' => 'form.server_role',
                            'value' => $form->server_role,
                            'options' => collect($provisionOptions['server_roles'] ?? [])->map(fn ($r) => [
                                'id' => (string) ($r['id'] ?? ''),
                                'label' => (string) ($r['label'] ?? ''),
                                'summary' => (string) ($r['summary'] ?? ''),
                            ])->all(),
                            'errorKey' => 'form.server_role',
                            'eyebrow' => __('Job'),
                            'placeholder' => __('Choose a job'),
                        ])
                    </div>
                </div>

                @php
                    // A field is "in scope" when the current template/role
                    // says it applies. We hide controls for things that
                    // won't be installed (e.g. PHP selector for a Rails
                    // stack) so the override panel only shows knobs for
                    // pieces of the actual stack. "none"/"" means the
                    // template explicitly opted out.
                    $showWebserver = $form->webserver !== '' && $form->webserver !== 'none';
                    $showPhp = $form->php_version !== '' && $form->php_version !== 'none';
                    $showDatabase = $form->database !== '' && $form->database !== 'none';
                    $showCache = $form->cache_service !== '' && $form->cache_service !== 'none';
                    $showRuby = $form->ruby_version !== '';
                    $showNode = $form->node_version !== '';
                    $showPython = $form->python_version !== '';
                    $showGo = $form->go_version !== '';
                    $hasComponents = $showWebserver || $showPhp || $showDatabase || $showCache;
                    $hasRuntimes = $showRuby || $showNode || $showPython || $showGo;
                @endphp

                @if ($hasComponents)
                    <div class="border-t border-brand-ink/10 pt-6">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Individual stack components') }}</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Swap a single piece (e.g. switch from Nginx to Caddy) without leaving the bundle.') }}</p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            @if ($showWebserver)
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'webserver',
                                    'label' => __('Web server'),
                                    'field' => 'form.webserver',
                                    'value' => $form->webserver,
                                    'options' => $provisionOptions['webservers'] ?? [],
                                    'errorKey' => 'form.webserver',
                                ])
                            @endif
                            @if ($showPhp)
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'php_version',
                                    'label' => __('PHP version'),
                                    'field' => 'form.php_version',
                                    'value' => $form->php_version,
                                    'options' => $provisionOptions['php_versions'] ?? [],
                                    'errorKey' => 'form.php_version',
                                ])
                            @endif
                            @if ($showDatabase)
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'database',
                                    'label' => __('Database'),
                                    'field' => 'form.database',
                                    'value' => $form->database,
                                    'options' => $provisionOptions['databases'] ?? [],
                                    'errorKey' => 'form.database',
                                ])
                            @endif
                            @if ($showCache)
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'cache_service',
                                    'label' => __('Cache service'),
                                    'field' => 'form.cache_service',
                                    'value' => $form->cache_service,
                                    'options' => $provisionOptions['cache_services'] ?? [],
                                    'errorKey' => 'form.cache_service',
                                ])
                            @endif
                        </div>
                    </div>
                @endif

                @if ($hasRuntimes)
                    <div class="border-t border-brand-ink/10 pt-6">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Language runtimes') }}</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Templates pre-fill these (Rails → Ruby, Next.js → Node, etc.); pick "Not installed" to drop one.') }}</p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            @if ($showRuby)
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'ruby_version',
                                    'label' => __('Ruby'),
                                    'field' => 'form.ruby_version',
                                    'value' => $form->ruby_version,
                                    'options' => $provisionOptions['ruby_versions'] ?? [],
                                    'errorKey' => 'form.ruby_version',
                                ])
                            @endif
                            @if ($showNode)
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'node_version',
                                    'label' => __('Node.js'),
                                    'field' => 'form.node_version',
                                    'value' => $form->node_version,
                                    'options' => $provisionOptions['node_versions'] ?? [],
                                    'errorKey' => 'form.node_version',
                                ])
                            @endif
                            @if ($showPython)
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'python_version',
                                    'label' => __('Python'),
                                    'field' => 'form.python_version',
                                    'value' => $form->python_version,
                                    'options' => $provisionOptions['python_versions'] ?? [],
                                    'errorKey' => 'form.python_version',
                                ])
                            @endif
                            @if ($showGo)
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'go_version',
                                    'label' => __('Go'),
                                    'field' => 'form.go_version',
                                    'value' => $form->go_version,
                                    'options' => $provisionOptions['go_versions'] ?? [],
                                    'errorKey' => 'form.go_version',
                                ])
                            @endif
                        </div>
                    </div>
                @endif

                @if (! $hasComponents && ! $hasRuntimes)
                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-5 text-center text-xs text-brand-moss">
                        {{ __('No stack components selected. Pick a template above (or change the role) to see the swappable pieces here.') }}
                    </div>
                @endif
            </div>
        </details>

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-6">
            <button
                type="button"
                wire:click="openDiscardDraftModal"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-white px-5 text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50"
            >
                <x-heroicon-o-trash class="h-4 w-4" />
                {{ __('Discard draft') }}
            </button>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    wire:click="previous"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-5 text-sm font-semibold text-brand-ink transition-colors hover:border-brand-sage hover:text-brand-sage"
                >
                    <x-heroicon-o-arrow-left class="h-4 w-4" />
                    {{ __('Back') }}
                </button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="next"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand-ink px-6 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="next">{{ __('Continue to review') }}</span>
                    <span wire:loading wire:target="next" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Saving…') }}
                    </span>
                    <x-heroicon-o-arrow-right wire:loading.remove wire:target="next" class="h-4 w-4" />
                </button>
            </div>
        </footer>
      </div>

      {{-- Sidebar: explain the new vocabulary so the user gets it. --}}
      <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
            <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                <x-heroicon-m-academic-cap class="h-3.5 w-3.5" />
                {{ __('How these fit together') }}
            </p>
            <dl class="mt-3 space-y-3 text-sm">
                <div>
                    <dt class="font-semibold text-brand-ink">{{ __('Template') }}</dt>
                    <dd class="mt-0.5 text-xs leading-5 text-brand-moss">{{ __('"I want to deploy a Laravel app." Sets the bundle + job + stack to a known-good combo for that use case.') }}</dd>
                </div>
                <div class="border-t border-brand-ink/10 pt-3">
                    <dt class="font-semibold text-brand-ink">{{ __('Package bundle (profile)') }}</dt>
                    <dd class="mt-0.5 text-xs leading-5 text-brand-moss">{{ __('Which set of system packages to install. Templates pick this for you; override only if you know your bundle differs from the defaults.') }}</dd>
                </div>
                <div class="border-t border-brand-ink/10 pt-3">
                    <dt class="font-semibold text-brand-ink">{{ __('Machine\'s job (role)') }}</dt>
                    <dd class="mt-0.5 text-xs leading-5 text-brand-moss">{{ __('What this server actually does in your fleet — application, worker, database node, cache, load balancer. Drives which packages from the bundle actually get installed.') }}</dd>
                </div>
            </dl>
        </div>

        @if ($selectedServerRole && ! empty($selectedServerRole['installs']) && is_array($selectedServerRole['installs']))
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Will install') }}</p>
                <p class="mt-1 text-xs text-brand-mist">{{ __('From your current job choice') }}</p>
                <ul class="mt-2 space-y-1 text-xs text-brand-moss">
                    @foreach (array_slice($selectedServerRole['installs'], 0, 6) as $item)
                        <li class="inline-flex items-start gap-1.5"><x-heroicon-m-check-circle class="mt-0.5 h-3 w-3 text-brand-sage" />{{ is_array($item) ? ($item['label'] ?? '') : $item }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
      </aside>
    </form>

    @include('livewire.servers.create._discard-draft-modal')
</div>
