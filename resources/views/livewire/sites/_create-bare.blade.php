{{-- Services-first bare-create. Collects only name + primary hostname; --}}
{{-- storeBare() creates the site and provisions its foundation (system user, --}}
{{-- web server vhost + splash page, testing hostname), then lands on the site --}}
{{-- page where services and a repository are configured when ready. --}}
@php
    $provisionSteps = [
        ['icon' => 'heroicon-o-globe-alt', 'label' => __('Web server + vhost')],
        ['icon' => 'heroicon-o-user', 'label' => __('System user')],
        ['icon' => 'heroicon-o-folder', 'label' => __('Deploy directory')],
        ['icon' => 'heroicon-o-link', 'label' => __('Testing URL + SSL')],
        ['icon' => 'heroicon-o-squares-2x2', 'label' => __('Configure services')],
        ['icon' => 'heroicon-o-code-bracket', 'label' => __('Connect a repo')],
    ];
@endphp

<div class="relative">
    {{-- Decorative brand mesh wash behind the hero. --}}
    <div class="pointer-events-none absolute inset-x-0 -top-16 -z-10 h-80 bg-mesh-brand opacity-90"></div>

    {{-- Hero --}}
    <div class="mx-auto max-w-2xl text-center">
        <span class="inline-flex items-center gap-2 rounded-full border border-brand-sage/25 bg-white/70 px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-brand-forest shadow-sm backdrop-blur">
            <span class="inline-flex h-1.5 w-1.5 rounded-full bg-brand-gold"></span>
            {{ __('Step 1 of 2 · New site') }}
        </span>
        <h1 class="mt-4 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">
            {{ __('Launch a new site on :server', ['server' => $server->name]) }}
        </h1>
        <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-brand-moss sm:text-base">
            {{ __('Name it and point a domain at it. Dply provisions the foundation — a system user, web server, and a testing URL — then you configure services and connect a repository whenever you’re ready.') }}
        </p>
    </div>

    <div class="mx-auto mt-10 grid max-w-5xl gap-6 lg:grid-cols-12 lg:items-start">
        {{-- Form card --}}
        <form wire:submit="storeBare" class="lg:col-span-7">
            <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-md shadow-brand-ink/5">
                <div class="flex items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-4">
                    <x-icon-badge>
                        <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Site details') }}</h2>
                        <p class="text-xs text-brand-moss">{{ __('The essentials — everything else comes next.') }}</p>
                    </div>
                </div>

                <div class="space-y-6 p-6 sm:p-7">
                    <div>
                        <x-input-label for="bare-name" :value="__('Site name')" required />
                        <x-text-input
                            id="bare-name"
                            type="text"
                            wire:model="form.name"
                            autocomplete="off"
                            class="mt-1.5"
                            :placeholder="__('My application')"
                        />
                        <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="bare-hostname" :value="__('Primary domain (optional)')" />
                        <x-text-input
                            id="bare-hostname"
                            type="text"
                            wire:model="form.primary_hostname"
                            autocomplete="off"
                            class="mt-1.5 font-mono"
                            placeholder="app.example.com"
                        />
                        <p class="mt-1.5 flex items-start gap-1.5 text-xs text-brand-moss">
                            <x-heroicon-o-information-circle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                            <span>{{ __('Optional. Dply wires a temporary testing hostname so you can deploy and verify without a customer domain. Add your real domain whenever you’re ready.') }}</span>
                        </p>
                        <x-input-error :messages="$errors->get('form.primary_hostname')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-cream/70 px-4 py-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-brand-forest shadow-sm ring-1 ring-brand-ink/5">
                            <x-heroicon-o-server class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 text-xs">
                            <p class="font-semibold text-brand-ink">{{ $server->name }}</p>
                            <p class="text-brand-moss">{{ __('This site will be created on the selected server.') }}</p>
                        </div>
                        <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2.5 py-1 text-[11px] font-semibold text-brand-forest ring-1 ring-brand-sage/20">
                            <span class="inline-flex h-1.5 w-1.5 rounded-full bg-brand-sage"></span>
                            {{ __('Ready') }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-brand-ink/10 bg-brand-cream/60 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
                    <a href="{{ route('servers.sites', $server) }}" wire:navigate
                        class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        {{ __('Cancel') }}
                    </a>
                    <x-primary-button>
                        {{ __('Create & provision site') }}
                        <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                    </x-primary-button>
                </div>
            </div>
        </form>

        {{-- What's next preview --}}
        <aside class="lg:col-span-5">
            <div class="rounded-2xl border border-brand-ink/10 bg-gradient-to-b from-brand-sand/40 to-white/80 p-6 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-rust">{{ __('Step 2 · Automatic') }}</p>
                <h3 class="mt-1.5 text-lg font-semibold text-brand-ink">{{ __('We provision the foundation') }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">
                    {{ __('The moment you create it, Dply sets up the web server, a system user, and a temporary testing URL, and serves a splash page. You then configure services and connect a repository from the site page.') }}
                </p>

                <div class="mt-5 grid grid-cols-2 gap-2.5">
                    @foreach ($provisionSteps as $app)
                        <div class="flex items-center gap-2.5 rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2.5 shadow-sm">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sage/12 text-brand-forest">
                                <x-dynamic-component :component="$app['icon']" class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <span class="truncate text-xs font-semibold text-brand-ink">{{ $app['label'] }}</span>
                        </div>
                    @endforeach
                </div>

                <p class="mt-5 flex items-start gap-2 rounded-xl bg-white/60 px-3 py-2.5 text-xs leading-relaxed text-brand-moss ring-1 ring-brand-ink/5">
                    <x-heroicon-o-sparkles class="mt-0.5 h-4 w-4 shrink-0 text-brand-gold" aria-hidden="true" />
                    <span>{{ __('WordPress, Laravel, Statamic, a Git repo, or a blank start — connect any of them from the site once it’s live. The repo is optional and can come later.') }}</span>
                </p>
            </div>
        </aside>
    </div>
</div>
