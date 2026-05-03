<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="3" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" />

    <form wire:submit.prevent="next" class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div class="space-y-8 min-w-0">
        <header class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/20 px-6 py-8 shadow-sm sm:px-10 sm:py-10">
            <div class="absolute -right-12 -top-12 h-44 w-44 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>
            <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>
            <div class="relative">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Step :n of :total', ['n' => 3, 'total' => $totalSteps]) }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ __('What it runs') }}</h1>
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss sm:text-base">{{ __('Pick a stack preset, then tweak any defaults you want. Each preset pre-fills runtimes, role, database, cache, and web server.') }}</p>
            </div>
        </header>

        {{-- Preset tiles. Featured tiles surface first; the polyglot
             host is the marketing-pixel-level differentiator and stays
             in the featured row alongside Laravel / Rails / Next.js /
             Django. Static / Database / Custom appear as a secondary row. --}}
        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm space-y-5 sm:p-7">
            <div>
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Pick a preset') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Click a tile, then override anything below.') }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (collect($serverPresets)->where('featured', true) as $preset)
                    <button
                        type="button"
                        wire:click="applyPreset('{{ $preset['id'] }}')"
                        @class([
                            'group relative flex flex-col items-start rounded-2xl border-2 p-5 text-left shadow-sm transition-all',
                            'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream' => $selectedPreset === $preset['id'],
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
                                {{ __('Selected') }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>

            <details class="text-sm" @if ($selectedPreset !== '' && ! collect($serverPresets)->where('featured', true)->pluck('id')->contains($selectedPreset)) open @endif>
                <summary class="cursor-pointer font-medium text-brand-moss transition-colors hover:text-brand-ink">{{ __('Other presets (Static / Database node / Custom)') }}</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    @foreach (collect($serverPresets)->where('featured', false) as $preset)
                        <button
                            type="button"
                            wire:click="applyPreset('{{ $preset['id'] }}')"
                            @class([
                                'flex flex-col items-start rounded-2xl border-2 p-4 text-left shadow-sm transition-all',
                                'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream' => $selectedPreset === $preset['id'],
                                'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $selectedPreset !== $preset['id'],
                            ])
                        >
                            <span class="text-sm font-semibold text-brand-ink">{{ $preset['name'] }}</span>
                            <span class="mt-1 text-xs leading-5 text-brand-moss">{{ $preset['description'] }}</span>
                        </button>
                    @endforeach
                </div>
            </details>
        </section>

        @php
            $selectedInstallProfile = collect($installProfiles)->firstWhere('id', $form->install_profile);
            $selectedServerRole = collect($provisionOptions['server_roles'] ?? [])->firstWhere('id', $form->server_role);
        @endphp

        {{-- Install profile + Server role: rich-select dropdowns (compact, slick) --}}
        <section class="grid gap-4 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:grid-cols-2 sm:p-7">
            @include('livewire.servers.create._rich-select', [
                'id' => 'install_profile',
                'label' => __('Install profile'),
                'field' => 'form.install_profile',
                'value' => $form->install_profile,
                'options' => collect($installProfiles)->map(fn ($p) => [
                    'id' => (string) ($p['id'] ?? ''),
                    'label' => (string) ($p['label'] ?? ''),
                    'summary' => (string) ($p['summary'] ?? ''),
                ])->all(),
                'errorKey' => 'form.install_profile',
                'eyebrow' => __('Profile'),
                'placeholder' => __('Choose a preset'),
            ])
            @include('livewire.servers.create._rich-select', [
                'id' => 'server_role',
                'label' => __('Server role'),
                'field' => 'form.server_role',
                'value' => $form->server_role,
                'options' => collect($provisionOptions['server_roles'] ?? [])->map(fn ($r) => [
                    'id' => (string) ($r['id'] ?? ''),
                    'label' => (string) ($r['label'] ?? ''),
                    'summary' => (string) ($r['summary'] ?? ''),
                ])->all(),
                'errorKey' => 'form.server_role',
                'eyebrow' => __('Role'),
                'placeholder' => __('Choose a role'),
            ])
        </section>

        {{-- Stack details --}}
        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7">
            <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Stack details') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Pre-filled from the install profile and role. Override any of these before continuing.') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @include('livewire.servers.create._rich-select', [
                    'id' => 'webserver',
                    'label' => __('Web server'),
                    'field' => 'form.webserver',
                    'value' => $form->webserver,
                    'options' => $provisionOptions['webservers'] ?? [],
                    'errorKey' => 'form.webserver',
                ])
                @include('livewire.servers.create._rich-select', [
                    'id' => 'php_version',
                    'label' => __('PHP version'),
                    'field' => 'form.php_version',
                    'value' => $form->php_version,
                    'options' => $provisionOptions['php_versions'] ?? [],
                    'errorKey' => 'form.php_version',
                ])
                @include('livewire.servers.create._rich-select', [
                    'id' => 'database',
                    'label' => __('Database'),
                    'field' => 'form.database',
                    'value' => $form->database,
                    'options' => $provisionOptions['databases'] ?? [],
                    'errorKey' => 'form.database',
                ])
                @include('livewire.servers.create._rich-select', [
                    'id' => 'cache_service',
                    'label' => __('Cache service'),
                    'field' => 'form.cache_service',
                    'value' => $form->cache_service,
                    'options' => $provisionOptions['cache_services'] ?? [],
                    'errorKey' => 'form.cache_service',
                ])
            </div>
        </section>

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

      {{-- Sidebar: selected profile + role detail, install previews --}}
      <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
        @if ($selectedInstallProfile)
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Install profile') }}</p>
                <p class="mt-2 text-sm font-semibold text-brand-ink">{{ $selectedInstallProfile['label'] }}</p>
                @if (! empty($selectedInstallProfile['summary']))
                    <p class="mt-1 text-xs leading-5 text-brand-moss">{{ $selectedInstallProfile['summary'] }}</p>
                @endif
            </div>
        @endif

        @if ($selectedServerRole)
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Server role') }}</p>
                <p class="mt-2 text-sm font-semibold text-brand-ink">{{ $selectedServerRole['label'] }}</p>
                @if (! empty($selectedServerRole['summary']))
                    <p class="mt-1 text-xs leading-5 text-brand-moss">{{ $selectedServerRole['summary'] }}</p>
                @endif
                @if (! empty($selectedServerRole['installs']) && is_array($selectedServerRole['installs']))
                    <div class="mt-3 border-t border-brand-ink/10 pt-3">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Will install') }}</p>
                        <ul class="mt-1.5 space-y-1 text-xs text-brand-moss">
                            @foreach (array_slice($selectedServerRole['installs'], 0, 6) as $item)
                                <li class="inline-flex items-start gap-1.5"><x-heroicon-m-check-circle class="mt-0.5 h-3 w-3 text-brand-sage" />{{ is_array($item) ? ($item['label'] ?? '') : $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div class="rounded-2xl border border-brand-sage/20 bg-gradient-to-br from-brand-sand/15 to-brand-cream p-5 text-sm text-brand-moss shadow-sm">
            <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                <x-heroicon-m-light-bulb class="h-3.5 w-3.5" />
                {{ __('Tips') }}
            </p>
            <ul class="mt-2 space-y-1.5">
                <li class="inline-flex items-start gap-1.5"><span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-sage"></span>{{ __('Picking a profile sets sensible defaults for the stack — you can override any.') }}</li>
                <li class="inline-flex items-start gap-1.5"><span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-sage"></span>{{ __('The role determines what gets installed: e.g. load_balancer skips PHP, database skips the web stack, redis only installs Redis.') }}</li>
                <li class="inline-flex items-start gap-1.5"><span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-brand-sage"></span>{{ __('Only the Application and Worker roles install PHP and Composer; Supervisor is included on roles that need long-running processes.') }}</li>
            </ul>
        </div>
      </aside>
    </form>

    @include('livewire.servers.create._discard-draft-modal')
</div>
