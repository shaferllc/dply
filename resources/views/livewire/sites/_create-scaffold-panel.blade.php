<form wire:submit="storeScaffold" class="space-y-8">
    <section class="rounded-3xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7">
        <header class="flex items-start gap-4">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest">
                <x-heroicon-o-rectangle-stack class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Pick a starter') }}</h2>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('We install the framework, create the database, generate the admin password, and hand you a working /admin login.') }}</p>
            </div>
        </header>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            @foreach ([
                'laravel' => [
                    'name' => __('Laravel app'),
                    'description' => __('Latest stable Laravel + Breeze (Blade) auth. Database, queue worker, and scheduler cron all wired.'),
                    'icon' => 'heroicon-o-bolt',
                ],
                'wordpress' => [
                    'name' => __('WordPress'),
                    'description' => __('Latest WordPress + opinionated hardening (system cron, SSL admin, Redis-cache plugin staged). MariaDB or MySQL on the host.'),
                    'icon' => 'heroicon-o-globe-alt',
                ],
            ] as $framework => $tile)
                <button
                    type="button"
                    wire:click="chooseScaffoldFramework('{{ $framework }}')"
                    @class([
                        'group relative flex flex-col items-start rounded-2xl border-2 p-5 text-left shadow-sm transition-all',
                        'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $form->scaffold_framework === $framework,
                        'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->scaffold_framework !== $framework,
                    ])
                >
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15">
                        <x-dynamic-component :component="$tile['icon']" class="h-5 w-5" />
                    </span>
                    <span class="mt-3 block text-base font-semibold text-brand-ink">{{ $tile['name'] }}</span>
                    <span class="mt-1 block text-sm leading-relaxed text-brand-moss">{{ $tile['description'] }}</span>
                    @if ($form->scaffold_framework === $framework)
                        <span class="absolute right-3 top-3 inline-flex items-center gap-0.5 rounded-full bg-brand-sage px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                            <x-heroicon-m-check class="h-3 w-3" />
                            {{ __('Picked') }}
                        </span>
                    @endif
                </button>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('form.scaffold_framework')" class="mt-3" />
    </section>

    <section class="rounded-3xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7">
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Site details') }}</h2>
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Three fields. We handle the rest.') }}</p>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="scaffold_name" :value="__('Site name')" />
                <x-text-input
                    id="scaffold_name"
                    type="text"
                    wire:model="form.name"
                    class="mt-1 block w-full font-mono text-base"
                    placeholder="my-blog"
                    required
                    autocomplete="off"
                />
                <p class="mt-1 text-xs text-brand-mist">{{ __('Used for the slug, deploy path, and placeholder hostname.') }}</p>
                <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="scaffold_admin_email" :value="__('Admin email')" />
                <x-text-input
                    id="scaffold_admin_email"
                    type="email"
                    wire:model="form.scaffold_admin_email"
                    class="mt-1 block w-full"
                    placeholder="you@example.com"
                    required
                    autocomplete="email"
                />
                <p class="mt-1 text-xs text-brand-mist">{{ __('Becomes the first admin user. Password is generated and shown once on the success screen.') }}</p>
                <x-input-error :messages="$errors->get('form.scaffold_admin_email')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="scaffold_hostname" :value="__('Custom domain (optional)')" />
                <x-text-input
                    id="scaffold_hostname"
                    type="text"
                    wire:model="form.primary_hostname"
                    class="mt-1 block w-full font-mono text-sm"
                    placeholder="leave blank for placeholder"
                    autocomplete="off"
                />
                <p class="mt-1 text-xs text-brand-mist">{{ __('Leave empty and we’ll generate a placeholder URL on ondply.io with HTTPS.') }}</p>
                <x-input-error :messages="$errors->get('form.primary_hostname')" class="mt-1" />
            </div>
        </div>
    </section>

    <footer class="flex items-center justify-between border-t border-brand-ink/10 pt-6">
        <a href="{{ route('servers.sites', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-moss hover:text-brand-ink">
            <x-heroicon-o-arrow-left class="h-4 w-4" />
            {{ __('Back to sites') }}
        </a>
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="storeScaffold"
            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand-ink px-6 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-60"
        >
            <span wire:loading.remove wire:target="storeScaffold">{{ __('Scaffold the app') }}</span>
            <span wire:loading wire:target="storeScaffold" class="inline-flex items-center gap-2">
                <x-spinner variant="cream" size="sm" />
                {{ __('Queueing…') }}
            </span>
            <x-heroicon-o-arrow-right wire:loading.remove wire:target="storeScaffold" class="h-4 w-4" />
        </button>
    </footer>
</form>
