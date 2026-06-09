<div class="mx-auto max-w-3xl px-6 py-10">
    <header class="mb-8">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('Create a managed database') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('dply provisions a hosted Postgres, MySQL, or Redis instance on DigitalOcean Managed Databases. Once it\'s online you can attach it to any Cloud app — we inject the connection env vars and redeploy for you.') }}</p>
    </header>

    @if (! $hasDoCredential)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('No DigitalOcean credential connected') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Managed databases run on DigitalOcean. Connect a DigitalOcean credential first — that\'s the cloud account dply uses to provision the database cluster.') }}</p>
                        <p class="mt-3">
                            <a href="{{ route('credentials.index', ['provider' => 'digitalocean']) }}" wire:navigate class="font-medium text-amber-900 underline">{{ __('Connect DigitalOcean') }}</a>
                        </p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <form wire:submit="create" class="mt-8 space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="space-y-4">
                <div>
                    <x-input-label for="name" :value="__('Database name')" />
                    <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required placeholder="acme-primary-db" />
                    <p class="mt-1 text-xs text-slate-500">{{ __('A label for this database in dply. The cluster on the backend is named to match.') }}</p>
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="engine" :value="__('Engine')" />
                        <select id="engine" wire:model.live="engine" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
                            <option value="postgres">{{ __('PostgreSQL') }}</option>
                            <option value="mysql">{{ __('MySQL') }}</option>
                            <option value="redis">{{ __('Redis') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('engine')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="version" :value="__('Engine version')" />
                        <select id="version" wire:model="version" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
                            @foreach (($engineVersions[$engine] ?? []) as $v)
                                <option value="{{ $v }}">{{ $v }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('version')" class="mt-2" />
                    </div>
                </div>

                <div>
                    <x-input-label for="size" :value="__('Size')" />
                    <select id="size" wire:model="size" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
                        @foreach ($sizeTiers as $tier => $slug)
                            <option value="{{ $tier }}">{{ ucfirst($tier) }} ({{ $slug }})</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Small is a single-node 1 vCPU / 1 GB cluster. Resize later via dply:cloud:db.') }}</p>
                    <x-input-error :messages="$errors->get('size')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="region" :value="__('Region')" />
                    <select id="region" wire:model="region" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
                        @foreach ($regions as $r)
                            <option value="{{ $r['slug'] }}">{{ $r['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Pick the datacenter closest to the apps that will use this database.') }}</p>
                    <x-input-error :messages="$errors->get('region')" class="mt-2" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('cloud.databases.index') }}" wire:navigate class="text-sm font-medium text-slate-700 hover:text-slate-900">{{ __('Cancel') }}</a>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="create">
                <span wire:loading.remove wire:target="create">{{ __('Create database') }}</span>
                <span wire:loading wire:target="create" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Provisioning…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
