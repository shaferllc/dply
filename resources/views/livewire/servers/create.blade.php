<div>
    <div class="border-b border-slate-200 bg-white">
        <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('servers.index') }}" wire:navigate class="text-sm font-medium text-sky-700 hover:text-sky-900">{{ __('← Cancel') }}</a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Create server') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">
                {{ __('Choose a cloud provider and API credentials from Server providers, or connect your own VPS. Region and plan options are loaded from the provider for the account you select.') }}
            </p>
        </div>
    </div>

    <div class="py-10">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            @if (session('error'))
                <div class="mb-4 p-4 rounded-lg bg-red-50 text-red-800">{{ session('error') }}</div>
            @endif
            @error('org')
                <div class="mb-4 p-4 rounded-lg bg-red-50 text-red-800">{{ $message }}</div>
            @enderror

            @if (!$canCreateServer && $billingUrl)
                <div class="mb-8 bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-900">
                    <p class="font-medium">{{ __('Server limit reached for your plan.') }}</p>
                    <p class="mt-1 text-sm">{{ __('Upgrade to add more servers.') }}</p>
                    <a href="{{ $billingUrl }}" class="mt-4 inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg font-medium text-sm hover:bg-amber-700">{{ __('Go to billing') }}</a>
                </div>
            @endif

            @unless ($hasAnyProviderCredentials)
                <section class="mb-8 rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-cream/50 px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Set up a provider') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a provider before you create a cloud server.') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-moss">
                            {{ __('Cloud providers stay unavailable until your organization has at least one provider credential. Add one here, or continue with Custom if you are connecting an existing VPS.') }}
                        </p>
                    </div>

                    <div class="p-6 space-y-6">
                        <div class="lg:hidden">
                            <x-input-label for="create_credentials_provider_picker" :value="__('Provider')" />
                            <select
                                id="create_credentials_provider_picker"
                                wire:model.live="active_provider"
                                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach ($providerNav as $group)
                                    <optgroup label="{{ $group['label'] }}">
                                        @foreach ($group['items'] as $item)
                                            <option value="{{ $item['id'] }}">{{ $item['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>

                        <div class="lg:grid lg:grid-cols-12 lg:gap-8 items-start">
                            <aside class="hidden lg:block lg:col-span-4 xl:col-span-3">
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/35 p-2 space-y-4">
                                    @foreach ($providerNav as $group)
                                        <div>
                                            <p class="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-brand-mist">{{ $group['label'] }}</p>
                                            <ul class="space-y-0.5 mt-1">
                                                @foreach ($group['items'] as $item)
                                                    <li>
                                                        <button
                                                            type="button"
                                                            wire:click="$set('active_provider', '{{ $item['id'] }}')"
                                                            @class([
                                                                'w-full text-left rounded-lg px-3 py-2 transition-colors flex items-center justify-between gap-2',
                                                                'bg-brand-sand/70 text-brand-ink font-medium' => $active_provider === $item['id'],
                                                                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active_provider !== $item['id'],
                                                            ])
                                                        >
                                                            <span class="truncate">{{ $item['label'] }}</span>
                                                        </button>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endforeach
                                </div>
                            </aside>

                            <div class="lg:col-span-8 xl:col-span-9 min-w-0 space-y-6">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <h3 class="text-lg font-semibold text-brand-ink">{{ $activeProviderLabel }}</h3>
                                    <a href="{{ route('docs.connect-provider') }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink hover:underline">{{ __('Setup guide') }}</a>
                                </div>

                                @include('livewire.credentials.panel', [
                                    'credentials' => $credentials,
                                    'digitalOceanOAuthConfigured' => $digitalOceanOAuthConfigured,
                                ])
                            </div>
                        </div>
                    </div>
                </section>

                <div class="mb-8 flex items-center gap-4">
                    <div class="h-px flex-1 bg-brand-ink/10"></div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-mist">{{ __('Or continue with a custom server') }}</p>
                    <div class="h-px flex-1 bg-brand-ink/10"></div>
                </div>
            @endunless

            <div class="@if(!$canCreateServer) opacity-60 pointer-events-none @endif space-y-10">
                @if ($hasAnyProviderCredentials)
                    <section aria-labelledby="path-heading">
                        <h2 id="path-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('1. Choose server type') }}</h2>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <button
                                type="button"
                                wire:click="$set('form.type', 'digitalocean')"
                                class="rounded-2xl border-2 p-5 text-left transition {{ $form->type !== 'custom' ? 'border-sky-600 bg-sky-50/80 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                            >
                                <span class="block text-lg font-semibold text-slate-900">{{ __('Cloud server') }}</span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600">
                                    {{ __('Provision a new server with a connected provider, then choose the account, region, size, and core stack.') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="$set('form.type', 'custom')"
                                class="rounded-2xl border-2 p-5 text-left transition {{ $form->type === 'custom' ? 'border-sky-600 bg-sky-50/80 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                            >
                                <span class="block text-lg font-semibold text-slate-900">{{ __('Custom server') }}</span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600">
                                    {{ __('Connect an existing VPS with SSH details only. No cloud provider account or catalog is required.') }}
                                </span>
                            </button>
                        </div>
                    </section>
                @endif

                @if ($hasAnyProviderCredentials && $form->type !== 'custom')
                    <section aria-labelledby="details-heading">
                        <h2 id="details-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('2. Cloud server setup') }}</h2>

                        <form wire:submit="store" class="mt-4 space-y-6">
                            @php
                                $digitalOceanEnvCatalog = $form->type === 'digitalocean' && filled(config('services.digitalocean.token'));
                                $showCloudStackFields = $form->provider_credential_id !== '' || $digitalOceanEnvCatalog;
                                if ($form->type === 'fly_io') {
                                    $regionSizePickReady = $catalog['credentials']->isNotEmpty();
                                } elseif ($form->type === 'digitalocean') {
                                    $regionSizePickReady = $digitalOceanEnvCatalog || $form->provider_credential_id !== '';
                                } else {
                                    $regionSizePickReady = $catalog['credentials']->isNotEmpty() && $form->provider_credential_id !== '';
                                }
                            @endphp
                            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                                <h3 class="text-base font-semibold text-slate-900">{{ __('Choose provider') }}</h3>
                                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    @foreach ($providerCards as $card)
                                        @continue($card['id'] === 'custom')
                                        @continue(! $hasAnyProviderCredentials && $card['id'] !== 'custom')
                                        <button
                                            type="button"
                                            wire:click="$set('form.type', '{{ $card['id'] }}')"
                                            class="relative flex flex-col items-start rounded-xl border-2 p-4 text-left transition
                                                {{ $form->type === $card['id'] ? 'border-sky-600 bg-sky-50/80 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                                        >
                                            <span class="font-medium text-slate-900">{{ $card['label'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                                <p class="mt-4 text-sm text-slate-600">
                                    <a href="{{ route('docs.connect-provider') }}" wire:navigate class="font-medium text-sky-700 hover:text-sky-900">{{ __('Connection guide') }}</a>
                                </p>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-5">
                                <h3 class="text-base font-semibold text-slate-900">{{ __('Choose account') }}</h3>
                                @if ($catalog['credentials']->isEmpty())
                                    @if ($digitalOceanEnvCatalog)
                                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sky-950">
                                            <p class="font-medium">{{ __('No linked DigitalOcean account') }}</p>
                                            <p class="mt-1 text-sm">
                                                {{ __('Regions and sizes below are loaded using DIGITALOCEAN_TOKEN. Add a credential under Server providers to create a droplet.') }}
                                                <a href="{{ route('credentials.index') }}" wire:navigate class="underline font-medium">{{ __('Go to Server providers') }}</a>
                                            </p>
                                        </div>
                                    @else
                                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                                            <p class="font-medium">{{ __('Add a credential first') }}</p>
                                            <p class="mt-1 text-sm">
                                                {{ __('Save an API token for this provider under Server providers, then return here.') }}
                                                <a href="{{ route('credentials.index') }}" wire:navigate class="underline font-medium">{{ __('Go to Server providers') }}</a>
                                            </p>
                                        </div>
                                    @endif
                                @endif

                                <div>
                                    <x-input-label for="provider_credential_id" :value="__('Credentials')" />
                                    <select
                                        wire:model.live="form.provider_credential_id"
                                        id="provider_credential_id"
                                        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500"
                                        @if($catalog['credentials']->isEmpty()) disabled @endif
                                    >
                                        <option value="">{{ __('Select account') }}</option>
                                        @foreach ($catalog['credentials'] as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                                </div>

                                @if ($catalog['credentials']->isNotEmpty() && $form->provider_credential_id === '')
                                    <p class="text-sm text-slate-600">{{ __('Choose an account above to continue with cloud provisioning.') }}</p>
                                @endif
                            </div>

                            @if ($showCloudStackFields)
                                <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-5">
                                    <h3 class="text-base font-semibold text-slate-900">{{ __('Core server config') }}</h3>
                                    <div>
                                        <x-input-label for="server_name" :value="__('Server name')" />
                                        <div class="mt-1 flex gap-2">
                                            <x-text-input id="server_name" wire:model="form.name" type="text" class="block w-full" required autocomplete="off" />
                                            <button
                                                type="button"
                                                wire:click="regenerateServerName"
                                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
                                            >
                                                {{ __('Regenerate') }}
                                            </button>
                                        </div>
                                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                                    </div>

                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <x-input-label for="server_role" :value="__('Server type')" />
                                            <select wire:model.live="form.server_role" id="server_role" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                @foreach ($provisionOptions['server_roles'] ?? [] as $role)
                                                    <option value="{{ $role['id'] }}">{{ $role['label'] }}</option>
                                                @endforeach
                                            </select>
                                            @php
                                                $selectedServerRole = collect($provisionOptions['server_roles'] ?? [])->firstWhere('id', $form->server_role);
                                            @endphp
                                            @if ($selectedServerRole && ! empty($selectedServerRole['detail'] ?? null))
                                                <p class="mt-1 text-xs text-slate-600 font-mono leading-relaxed"><span class="text-slate-400 select-none" aria-hidden="true">└─</span> {{ $selectedServerRole['detail'] }}</p>
                                            @endif
                                            <x-input-error :messages="$errors->get('server_role')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="cache_service" :value="__('Cache service')" />
                                            <select wire:model="form.cache_service" id="cache_service" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                @foreach ($provisionOptions['cache_services'] ?? [] as $opt)
                                                    <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('cache_service')" class="mt-1" />
                                        </div>
                                    </div>

                                    <div
                                        class="grid gap-4 sm:grid-cols-2"
                                        wire:key="catalog-{{ $form->type }}-{{ $form->provider_credential_id }}-{{ $form->region }}"
                                    >
                                        <div>
                                            <x-input-label for="form_region" :value="$catalog['region_label']" />
                                            @php
                                                $regionOptions = collect($catalog['regions'] ?? [])->values();
                                                $selectedRegionOption = $regionOptions->firstWhere('value', $form->region);
                                                $showDigitalOceanRegionMap = $form->type === 'digitalocean';
                                                $digitalOceanRegionMarkers = collect([
                                                    ['value' => 'nyc1', 'label' => 'New York', 'top' => '34%', 'left' => '29%'],
                                                    ['value' => 'nyc2', 'label' => 'New York', 'top' => '34%', 'left' => '29%'],
                                                    ['value' => 'nyc3', 'label' => 'New York', 'top' => '34%', 'left' => '29%'],
                                                    ['value' => 'tor1', 'label' => 'Toronto', 'top' => '29%', 'left' => '27%'],
                                                    ['value' => 'sfo1', 'label' => 'San Francisco', 'top' => '35%', 'left' => '14%'],
                                                    ['value' => 'sfo2', 'label' => 'San Francisco', 'top' => '35%', 'left' => '14%'],
                                                    ['value' => 'sfo3', 'label' => 'San Francisco', 'top' => '35%', 'left' => '14%'],
                                                    ['value' => 'ams2', 'label' => 'Amsterdam', 'top' => '28%', 'left' => '50%'],
                                                    ['value' => 'ams3', 'label' => 'Amsterdam', 'top' => '28%', 'left' => '50%'],
                                                    ['value' => 'lon1', 'label' => 'London', 'top' => '26%', 'left' => '47%'],
                                                    ['value' => 'fra1', 'label' => 'Frankfurt', 'top' => '29%', 'left' => '53%'],
                                                    ['value' => 'blr1', 'label' => 'Bangalore', 'top' => '47%', 'left' => '67%'],
                                                    ['value' => 'sgp1', 'label' => 'Singapore', 'top' => '58%', 'left' => '76%'],
                                                    ['value' => 'syd1', 'label' => 'Sydney', 'top' => '80%', 'left' => '86%'],
                                                ])->filter(fn (array $marker) => $regionOptions->contains(fn (array $region) => ($region['value'] ?? null) === $marker['value']))->values();
                                            @endphp

                                            <div
                                                x-data="{ open: false, search: '', mapOpen: false, mapMode: 'simple' }"
                                                class="relative mt-1"
                                            >
                                                @if ($regionOptions->isEmpty())
                                                    <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                                                        {{ __('Select account details first to load regions.') }}
                                                    </div>
                                                @else
                                                    <button
                                                        id="form_region"
                                                        type="button"
                                                        @if(! $regionSizePickReady) disabled @endif
                                                        x-on:click="open = !open"
                                                        x-on:keydown.escape.window="open = false"
                                                        x-bind:aria-expanded="open.toString()"
                                                        aria-haspopup="listbox"
                                                        class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-left shadow-sm transition focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400"
                                                    >
                                                        <div class="flex items-start justify-between gap-4">
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Selected region') }}</div>
                                                                @if ($selectedRegionOption)
                                                                    <div class="mt-1 truncate text-sm font-semibold text-slate-900">{{ $selectedRegionOption['label'] }}</div>
                                                                @else
                                                                    <div class="mt-1 text-sm text-slate-500">{{ __('Select region') }}</div>
                                                                @endif
                                                            </div>
                                                            <div class="shrink-0 pt-1 text-slate-400" x-bind:class="{ 'rotate-180': open }">
                                                                <svg class="h-5 w-5 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.513a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                                                </svg>
                                                            </div>
                                                        </div>
                                                    </button>

                                                    <div
                                                        x-cloak
                                                        x-show="open"
                                                        x-transition.origin.top
                                                        x-on:click.outside="open = false"
                                                        role="listbox"
                                                        class="absolute z-20 mt-2 w-full rounded-2xl border border-slate-200 bg-white p-3 shadow-xl shadow-slate-200/80"
                                                    >
                                                        @php
                                                            $regionMapGroups = [
                                                                'Americas' => collect([
                                                                    ['value' => 'nyc1', 'label' => 'New York'],
                                                                    ['value' => 'nyc2', 'label' => 'New York'],
                                                                    ['value' => 'nyc3', 'label' => 'New York'],
                                                                    ['value' => 'tor1', 'label' => 'Toronto'],
                                                                    ['value' => 'sfo1', 'label' => 'San Francisco'],
                                                                    ['value' => 'sfo2', 'label' => 'San Francisco'],
                                                                    ['value' => 'sfo3', 'label' => 'San Francisco'],
                                                                    ['value' => 'atl1', 'label' => 'Atlanta'],
                                                                ]),
                                                                'Europe' => collect([
                                                                    ['value' => 'ams2', 'label' => 'Amsterdam'],
                                                                    ['value' => 'ams3', 'label' => 'Amsterdam'],
                                                                    ['value' => 'lon1', 'label' => 'London'],
                                                                    ['value' => 'fra1', 'label' => 'Frankfurt'],
                                                                ]),
                                                                'Asia Pacific' => collect([
                                                                    ['value' => 'blr1', 'label' => 'Bangalore'],
                                                                    ['value' => 'sgp1', 'label' => 'Singapore'],
                                                                    ['value' => 'syd1', 'label' => 'Sydney'],
                                                                ]),
                                                            ];
                                                            $hasMapableRegions = collect($regionMapGroups)->contains(
                                                                fn ($groupMarkers) => $groupMarkers->contains(
                                                                    fn (array $marker) => $regionOptions->contains(fn (array $region) => ($region['value'] ?? null) === $marker['value'])
                                                                )
                                                            );
                                                        @endphp

                                                        <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                                                            <div class="flex items-center justify-between gap-3">
                                                                <div>
                                                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Map') }}</div>
                                                                    <div class="mt-1 text-sm text-slate-600">
                                                                        {{ $hasMapableRegions ? __('Open the full map modal for easier geographic selection.') : __('Open the map modal to browse grouped regions for this provider.') }}
                                                                    </div>
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    x-on:click="open = false; mapOpen = true"
                                                                    class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
                                                                >
                                                                    {{ __('View map') }}
                                                                </button>
                                                            </div>
                                                        </div>

                                                        <div class="mt-3">
                                                            <input
                                                                x-model="search"
                                                                type="text"
                                                                class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                                                                placeholder="{{ __('Search regions…') }}"
                                                            />
                                                        </div>

                                                        <div class="mt-3 max-h-56 space-y-2 overflow-y-auto overscroll-contain pr-1">
                                                            @foreach ($regionOptions as $regionOption)
                                                                <button
                                                                    type="button"
                                                                    role="option"
                                                                    wire:click="$set('form.region', '{{ $regionOption['value'] }}')"
                                                                    x-on:click="open = false"
                                                                    x-show="'{{ Str::lower((string) $regionOption['label']) }}'.includes(search.toLowerCase()) || '{{ Str::lower((string) $regionOption['value']) }}'.includes(search.toLowerCase())"
                                                                    aria-selected="{{ $form->region === $regionOption['value'] ? 'true' : 'false' }}"
                                                                    class="w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $form->region === $regionOption['value'] ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                                                                >
                                                                    <div class="flex items-start justify-between gap-4">
                                                                        <div class="min-w-0">
                                                                            <div class="truncate text-sm font-semibold text-slate-900">{{ $regionOption['label'] }}</div>
                                                                            <div class="mt-1 text-[11px] uppercase tracking-[0.16em] text-slate-500">{{ $regionOption['value'] }}</div>
                                                                        </div>
                                                                        @if ($form->region === $regionOption['value'])
                                                                            <div class="shrink-0 text-sky-600">
                                                                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                                    <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.312a1 1 0 0 1-1.42-.001L3.29 9.254a1 1 0 1 1 1.42-1.408l4.04 4.076 6.542-6.627a1 1 0 0 1 1.412-.005Z" clip-rule="evenodd" />
                                                                                </svg>
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    </div>

                                                    <div
                                                        x-cloak
                                                        x-show="mapOpen"
                                                        x-transition.opacity
                                                        x-on:keydown.escape.window="mapOpen = false"
                                                        class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                                                        role="dialog"
                                                        aria-modal="true"
                                                        aria-labelledby="region-map-title"
                                                    >
                                                        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="mapOpen = false"></div>

                                                        <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
                                                            <div class="relative w-full max-w-5xl overflow-hidden rounded-3xl border border-brand-ink/10 bg-white shadow-2xl">
                                                                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                                                                    <div>
                                                                        <h3 id="region-map-title" class="text-lg font-semibold text-slate-900">{{ __('Region map') }}</h3>
                                                                        <p class="mt-1 text-sm text-slate-600">{{ __('Choose a region visually, or use the grouped regions below. Your selection updates the same region field.') }}</p>
                                                                    </div>
                                                                    <button
                                                                        type="button"
                                                                        x-on:click="mapOpen = false"
                                                                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-700"
                                                                        aria-label="{{ __('Close region map') }}"
                                                                    >
                                                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                            <path d="M6.28 5.22a.75.75 0 0 1 1.06 0L10 7.94l2.66-2.72a.75.75 0 1 1 1.08 1.04L11.06 9l2.68 2.74a.75.75 0 1 1-1.08 1.04L10 10.06l-2.66 2.72a.75.75 0 1 1-1.08-1.04L8.94 9 6.26 6.26a.75.75 0 0 1 .02-1.04Z"/>
                                                                        </svg>
                                                                    </button>
                                                                </div>

                                                                <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.9fr)]">
                                                                    <div class="rounded-3xl border border-slate-200 bg-[linear-gradient(180deg,#dbeafe_0%,#eff6ff_55%,#f8fafc_100%)] p-5">
                                                                        <div class="mb-4 flex items-center justify-between gap-3">
                                                                            <div>
                                                                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Visual map') }}</div>
                                                                                <div class="mt-1 text-sm text-slate-600">
                                                                                    {{ $hasMapableRegions ? __('Try both map styles and pick the one that makes region selection feel clearest.') : __('This provider does not have mapped coordinates yet, so use the grouped region list on the right.') }}
                                                                                </div>
                                                                            </div>
                                                                            @if ($selectedRegionOption)
                                                                                <div class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-slate-700 shadow-sm ring-1 ring-slate-200">
                                                                                    {{ $selectedRegionOption['value'] }}
                                                                                </div>
                                                                            @endif
                                                                        </div>

                                                                        @if ($hasMapableRegions)
                                                                            <div class="mb-4 inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
                                                                                <button
                                                                                    type="button"
                                                                                    x-on:click="mapMode = 'simple'"
                                                                                    class="rounded-lg px-3 py-2 text-sm font-medium transition"
                                                                                    x-bind:class="mapMode === 'simple' ? 'bg-sky-50 text-sky-700 ring-1 ring-sky-200' : 'text-slate-600 hover:text-slate-900'"
                                                                                >
                                                                                    {{ __('Simple') }}
                                                                                </button>
                                                                                <button
                                                                                    type="button"
                                                                                    x-on:click="mapMode = 'stylized'"
                                                                                    class="rounded-lg px-3 py-2 text-sm font-medium transition"
                                                                                    x-bind:class="mapMode === 'stylized' ? 'bg-sky-50 text-sky-700 ring-1 ring-sky-200' : 'text-slate-600 hover:text-slate-900'"
                                                                                >
                                                                                    {{ __('Stylized') }}
                                                                                </button>
                                                                            </div>

                                                                            <div x-show="mapMode === 'simple'" class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                                                                                <div class="mb-3 text-sm font-medium text-slate-700">{{ __('Simple flat world map') }}</div>
                                                                                <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-[linear-gradient(180deg,#dbeafe_0%,#eff6ff_55%,#f8fafc_100%)]">
                                                                                    <svg viewBox="0 0 900 500" class="block h-[22rem] w-full" aria-hidden="true">
                                                                                        <rect width="900" height="500" fill="transparent" />
                                                                                        <g fill="#94a3b8" fill-opacity="0.95">
                                                                                            <path d="M96 154c42-37 121-51 182-29 28 11 45 27 56 52 8 18 5 39-10 57-17 22-48 35-82 38-46 5-100-5-131-29-31-23-45-62-15-89Z"/>
                                                                                            <path d="M392 134c36-19 83-24 117-12 29 11 47 32 50 60 3 20-6 39-22 54-17 15-43 25-71 27-43 4-85-7-103-31-18-23-12-75 29-98Z"/>
                                                                                            <path d="M509 176c13-11 31-14 46-9 13 5 20 17 20 31 0 14-8 26-21 34-15 8-35 10-48 2-17-10-18-41 3-58Z"/>
                                                                                            <path d="M606 139c55-27 141-26 194 5 39 23 61 58 54 95-6 30-29 53-61 69-44 21-107 24-161 10-52-14-95-49-94-95 1-37 24-67 68-84Z"/>
                                                                                            <path d="M708 359c28-10 66-10 93 0 21 9 35 25 35 43s-14 34-36 44c-28 12-67 12-95 1-21-8-36-24-37-42-1-21 16-37 40-46Z"/>
                                                                                        </g>
                                                                                    </svg>

                                                                                    @foreach ($digitalOceanRegionMarkers as $marker)
                                                                                        <button
                                                                                            type="button"
                                                                                            data-region-marker="{{ $marker['value'] }}"
                                                                                            wire:click="$set('form.region', '{{ $marker['value'] }}')"
                                                                                            x-on:click="mapOpen = false"
                                                                                            class="absolute -translate-x-1/2 -translate-y-1/2"
                                                                                            style="top: {{ $marker['top'] }}; left: {{ $marker['left'] }};"
                                                                                        >
                                                                                            <span class="flex flex-col items-center gap-1">
                                                                                                <span class="h-4 w-4 rounded-full border-2 border-white shadow-md {{ $form->region === $marker['value'] ? 'bg-sky-600 ring-4 ring-sky-200' : 'bg-slate-700 hover:bg-sky-500' }}"></span>
                                                                                                <span class="rounded-full bg-white/95 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-700 shadow-sm ring-1 ring-slate-200">
                                                                                                    {{ $marker['value'] }}
                                                                                                </span>
                                                                                            </span>
                                                                                        </button>
                                                                                    @endforeach
                                                                                </div>
                                                                            </div>

                                                                            <div x-show="mapMode === 'stylized'" class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                                                                                <div class="mb-3 text-sm font-medium text-slate-700">{{ __('Stylized world map') }}</div>
                                                                                <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-[radial-gradient(circle_at_50%_15%,rgba(56,189,248,0.22),transparent_28%),linear-gradient(180deg,#082f49_0%,#0f172a_100%)]">
                                                                                    <svg viewBox="0 0 900 500" class="block h-[22rem] w-full" aria-hidden="true">
                                                                                        <rect width="900" height="500" fill="transparent" />
                                                                                        <g fill="#93c5fd" fill-opacity="0.36" stroke="#bfdbfe" stroke-opacity="0.55" stroke-width="4">
                                                                                            <path d="M96 154c42-37 121-51 182-29 28 11 45 27 56 52 8 18 5 39-10 57-17 22-48 35-82 38-46 5-100-5-131-29-31-23-45-62-15-89Z"/>
                                                                                            <path d="M392 134c36-19 83-24 117-12 29 11 47 32 50 60 3 20-6 39-22 54-17 15-43 25-71 27-43 4-85-7-103-31-18-23-12-75 29-98Z"/>
                                                                                            <path d="M509 176c13-11 31-14 46-9 13 5 20 17 20 31 0 14-8 26-21 34-15 8-35 10-48 2-17-10-18-41 3-58Z"/>
                                                                                            <path d="M606 139c55-27 141-26 194 5 39 23 61 58 54 95-6 30-29 53-61 69-44 21-107 24-161 10-52-14-95-49-94-95 1-37 24-67 68-84Z"/>
                                                                                            <path d="M708 359c28-10 66-10 93 0 21 9 35 25 35 43s-14 34-36 44c-28 12-67 12-95 1-21-8-36-24-37-42-1-21 16-37 40-46Z"/>
                                                                                        </g>
                                                                                    </svg>

                                                                                    <div class="absolute inset-x-0 top-3 flex justify-center">
                                                                                        <span class="rounded-full bg-white/90 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-800 shadow-sm">
                                                                                            {{ __('Click a highlighted region') }}
                                                                                        </span>
                                                                                    </div>

                                                                                    @foreach ($digitalOceanRegionMarkers as $marker)
                                                                                        <button
                                                                                            type="button"
                                                                                            data-region-marker="{{ $marker['value'] }}"
                                                                                            wire:click="$set('form.region', '{{ $marker['value'] }}')"
                                                                                            x-on:click="mapOpen = false"
                                                                                            class="absolute -translate-x-1/2 -translate-y-1/2"
                                                                                            style="top: {{ $marker['top'] }}; left: {{ $marker['left'] }};"
                                                                                        >
                                                                                            <span class="flex flex-col items-center gap-1">
                                                                                                <span class="relative flex h-5 w-5 items-center justify-center">
                                                                                                    <span class="absolute inline-flex h-5 w-5 rounded-full {{ $form->region === $marker['value'] ? 'bg-cyan-300/60' : 'bg-sky-300/35' }}"></span>
                                                                                                    <span class="relative h-3.5 w-3.5 rounded-full border-2 border-white {{ $form->region === $marker['value'] ? 'bg-cyan-400' : 'bg-sky-500' }}"></span>
                                                                                                </span>
                                                                                                <span class="rounded-full bg-slate-950/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-white shadow-sm ring-1 ring-white/15">
                                                                                                    {{ $marker['value'] }}
                                                                                                </span>
                                                                                            </span>
                                                                                        </button>
                                                                                    @endforeach
                                                                                </div>
                                                                            </div>
                                                                        @else
                                                                            <div class="rounded-3xl border border-dashed border-slate-300 bg-white/80 p-6 text-sm text-slate-500">
                                                                                {{ __('This provider does not have map coordinates yet. Use the full region list on the right.') }}
                                                                            </div>
                                                                        @endif
                                                                    </div>

                                                                    <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-5">
                                                                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('All regions') }}</div>
                                                                        <div class="mt-1 text-sm text-slate-600">{{ __('Select any available region, even if it is not mapped yet.') }}</div>
                                                                        <div class="mt-4 max-h-[32rem] space-y-2 overflow-y-auto pr-1">
                                                                            @foreach ($regionOptions as $regionOption)
                                                                                <button
                                                                                    type="button"
                                                                                    wire:click="$set('form.region', '{{ $regionOption['value'] }}')"
                                                                                    x-on:click="mapOpen = false"
                                                                                    class="w-full rounded-2xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $form->region === $regionOption['value'] ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                                                                                >
                                                                                    <div class="flex items-start justify-between gap-4">
                                                                                        <div class="min-w-0">
                                                                                            <div class="truncate text-sm font-semibold text-slate-900">{{ $regionOption['label'] }}</div>
                                                                                            <div class="mt-1 text-[11px] uppercase tracking-[0.16em] text-slate-500">{{ $regionOption['value'] }}</div>
                                                                                        </div>
                                                                                        @if ($form->region === $regionOption['value'])
                                                                                            <div class="shrink-0 text-sky-600">
                                                                                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                                                    <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.312a1 1 0 0 1-1.42-.001L3.29 9.254a1 1 0 1 1 1.42-1.408l4.04 4.076 6.542-6.627a1 1 0 0 1 1.412-.005Z" clip-rule="evenodd" />
                                                                                                </svg>
                                                                                            </div>
                                                                                        @endif
                                                                                    </div>
                                                                                </button>
                                                                            @endforeach
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                            <x-input-error :messages="$errors->get('region')" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label for="form_size" :value="$catalog['size_label']" />
                                            @php
                                                $parsePlanOption = static function (array $opt): array {
                                                    $value = (string) ($opt['value'] ?? '');
                                                    $label = (string) ($opt['label'] ?? '');
                                                    $spec = trim(Str::after($label, '—'));
                                                    $segments = array_values(array_filter(array_map('trim', preg_split('/\s*\/\s*/', $spec) ?: [])));
                                                    $ram = $segments[0] ?? '—';
                                                    $cpu = $segments[1] ?? '—';
                                                    $disk = '—';
                                                    $price = null;

                                                    foreach ($segments as $segment) {
                                                        if (str_contains(strtolower($segment), 'disk')) {
                                                            $disk = $segment;
                                                        }
                                                        if (str_contains($segment, '$')) {
                                                            $price = $segment;
                                                        }
                                                    }

                                                    return [
                                                        'value' => $value,
                                                        'name' => Str::before($label, ' — '),
                                                        'ram' => $ram,
                                                        'cpu' => $cpu,
                                                        'disk' => $disk,
                                                        'price' => $price,
                                                    ];
                                                };

                                                $sizeCards = collect($catalog['sizes'] ?? [])->map($parsePlanOption)->values();
                                                $selectedSizeCard = $sizeCards->firstWhere('value', $form->size);
                                                $recommendedSizeCard = $sizeCards->first();
                                            @endphp

                                            <div x-data="{ open: false }" class="relative mt-1">
                                                @if ($sizeCards->isEmpty())
                                                    <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                                                        {{ __('Select a region first to load available plans.') }}
                                                    </div>
                                                @else
                                                    <button
                                                        id="form_size"
                                                        type="button"
                                                        @if(! $regionSizePickReady) disabled @endif
                                                        x-on:click="open = !open"
                                                        x-on:keydown.escape.window="open = false"
                                                        x-bind:aria-expanded="open.toString()"
                                                        aria-haspopup="listbox"
                                                        class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-left shadow-sm transition focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400"
                                                    >
                                                        <div class="flex items-start justify-between gap-4">
                                                            <div class="min-w-0">
                                                                <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                                                    <span>{{ __('Selected plan') }}</span>
                                                                    @if ($selectedSizeCard && $recommendedSizeCard && $selectedSizeCard['value'] === $recommendedSizeCard['value'])
                                                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] tracking-[0.16em] text-emerald-700 ring-1 ring-emerald-200">
                                                                            {{ __('Recommended') }}
                                                                        </span>
                                                                    @endif
                                                                </div>
                                                                @if ($selectedSizeCard)
                                                                    <div class="mt-1 truncate text-sm font-semibold text-slate-900">{{ $selectedSizeCard['name'] }}</div>
                                                                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600">
                                                                        <span>{{ $selectedSizeCard['ram'] }}</span>
                                                                        <span>{{ __('·') }}</span>
                                                                        <span>{{ $selectedSizeCard['cpu'] }}</span>
                                                                        <span>{{ __('·') }}</span>
                                                                        <span>{{ $selectedSizeCard['disk'] }}</span>
                                                                        @if ($selectedSizeCard['price'])
                                                                            <span>{{ __('·') }}</span>
                                                                            <span>{{ $selectedSizeCard['price'] }}</span>
                                                                        @endif
                                                                    </div>
                                                                    @if ($recommendedSizeCard && $selectedSizeCard['value'] === $recommendedSizeCard['value'])
                                                                        <div class="mt-2 text-[11px] font-medium text-emerald-700">
                                                                            {{ __('Auto-selected as the leanest starting plan.') }}
                                                                        </div>
                                                                    @endif
                                                                @else
                                                                    <div class="mt-1 text-sm text-slate-500">{{ __('Select a plan') }}</div>
                                                                @endif
                                                            </div>
                                                            <div class="shrink-0 pt-1 text-slate-400" x-bind:class="{ 'rotate-180': open }">
                                                                <svg class="h-5 w-5 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.513a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                                                </svg>
                                                            </div>
                                                        </div>
                                                    </button>

                                                    <div
                                                        x-cloak
                                                        x-show="open"
                                                        x-transition.origin.top
                                                        x-on:click.outside="open = false"
                                                        role="listbox"
                                                        class="absolute z-20 mt-2 w-full rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-200/80"
                                                    >
                                                        <div class="hidden items-center gap-3 px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 sm:grid sm:grid-cols-[minmax(0,2.2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                                                            <div>{{ __('Plan') }}</div>
                                                            <div>{{ __('RAM') }}</div>
                                                            <div>{{ __('CPU') }}</div>
                                                            <div>{{ __('Disk') }}</div>
                                                            <div class="text-right">{{ __('Price / mo') }}</div>
                                                        </div>

                                                        <div class="max-h-96 space-y-2 overflow-y-auto overscroll-contain pr-1">
                                                            @foreach ($sizeCards as $sizeCard)
                                                                <button
                                                                    type="button"
                                                                    role="option"
                                                                    wire:click="$set('form.size', '{{ $sizeCard['value'] }}')"
                                                                    x-on:click="open = false"
                                                                    aria-selected="{{ $form->size === $sizeCard['value'] ? 'true' : 'false' }}"
                                                                    class="w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $form->size === $sizeCard['value'] ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                                                                >
                                                                    <div class="flex items-start justify-between gap-4">
                                                                        <div class="min-w-0 flex-1">
                                                                            <div class="flex flex-wrap items-center gap-2">
                                                                                <div class="truncate text-sm font-semibold text-slate-900">{{ $sizeCard['name'] }}</div>
                                                                                @if ($recommendedSizeCard && $sizeCard['value'] === $recommendedSizeCard['value'])
                                                                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-emerald-700 ring-1 ring-emerald-200">
                                                                                        {{ __('Recommended') }}
                                                                                    </span>
                                                                                @endif
                                                                            </div>
                                                                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
                                                                                <span><span class="font-medium text-slate-700">{{ __('RAM') }}:</span> {{ $sizeCard['ram'] }}</span>
                                                                                <span><span class="font-medium text-slate-700">{{ __('CPU') }}:</span> {{ $sizeCard['cpu'] }}</span>
                                                                                <span><span class="font-medium text-slate-700">{{ __('Disk') }}:</span> {{ $sizeCard['disk'] }}</span>
                                                                            </div>
                                                                            @if ($sizeCard['value'] !== $sizeCard['name'])
                                                                                <div class="mt-1 truncate text-[11px] text-slate-400">{{ $sizeCard['value'] }}</div>
                                                                            @endif
                                                                        </div>
                                                                        <div class="shrink-0 text-right">
                                                                            <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Price') }}</div>
                                                                            <div class="mt-1 text-sm font-semibold text-slate-900">{{ $sizeCard['price'] ?? __('Custom') }}</div>
                                                                        </div>
                                                                    </div>
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                            <x-input-error :messages="$errors->get('size')" class="mt-1" />
                                        </div>
                                    </div>
                                    @if ($form->type === 'scaleway')
                                        <p class="text-xs text-slate-500">{{ __('Choose a zone first; instance types load for that zone.') }}</p>
                                    @endif
                                </div>

                                <details class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                                    <summary class="cursor-pointer list-none text-base font-semibold text-slate-900">
                                        {{ __('Advanced options') }}
                                    </summary>
                                    <div class="mt-5 space-y-5">
                                        <div class="grid gap-4 sm:grid-cols-3" wire:key="provision-stack-{{ $form->server_role }}">
                                            <div>
                                                <x-input-label for="webserver" :value="__('Web server')" />
                                                <select wire:model="form.webserver" id="webserver" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                    @foreach ($provisionOptions['webservers'] ?? [] as $opt)
                                                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('webserver')" class="mt-1" />
                                            </div>
                                            <div>
                                                <x-input-label for="php_version" :value="__('PHP version')" />
                                                <select wire:model="form.php_version" id="php_version" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                    @foreach ($provisionOptions['php_versions'] ?? [] as $opt)
                                                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('php_version')" class="mt-1" />
                                            </div>
                                            <div>
                                                <x-input-label for="database" :value="__('Database')" />
                                                <select wire:model="form.database" id="database" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                    @foreach ($provisionOptions['databases'] ?? [] as $opt)
                                                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('database')" class="mt-1" />
                                            </div>
                                        </div>

                                        <div>
                                            <x-input-label for="setup_script_key" :value="__('Setup script (optional)')" />
                                            <select wire:model="form.setup_script_key" id="setup_script_key" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                <option value="">{{ __('None') }}</option>
                                                @foreach ($setupScripts as $key => $script)
                                                    <option value="{{ $key }}">{{ $script['name'] }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('setup_script_key')" class="mt-1" />
                                        </div>

                                        @if ($form->type === 'digitalocean')
                                            <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-4">
                                                <div>
                                                    <p class="text-sm font-medium text-slate-900">{{ __('Droplet options') }}</p>
                                                    <p class="mt-0.5 text-xs text-slate-600">{{ __('Only needed when you want custom DigitalOcean provisioning behavior.') }}</p>
                                                </div>
                                                <div class="space-y-3">
                                                    <label class="flex gap-3 items-start cursor-pointer">
                                                        <input type="checkbox" wire:model="form.do_ipv6" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500" />
                                                        <span>
                                                            <span class="block text-sm font-medium text-slate-800">{{ __('Enable IPv6') }}</span>
                                                            <span class="block text-xs text-slate-600">{{ __('Assign a public IPv6 address in addition to IPv4.') }}</span>
                                                        </span>
                                                    </label>
                                                    <label class="flex gap-3 items-start cursor-pointer">
                                                        <input type="checkbox" wire:model="form.do_backups" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500" />
                                                        <span>
                                                            <span class="block text-sm font-medium text-slate-800">{{ __('Enable backups') }}</span>
                                                            <span class="block text-xs text-slate-600">{{ __('Weekly droplet backups (billed by DigitalOcean).') }}</span>
                                                        </span>
                                                    </label>
                                                    <label class="flex gap-3 items-start cursor-pointer">
                                                        <input type="checkbox" wire:model="form.do_monitoring" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500" />
                                                        <span>
                                                            <span class="block text-sm font-medium text-slate-800">{{ __('Enable monitoring') }}</span>
                                                            <span class="block text-xs text-slate-600">{{ __('Install the DigitalOcean metrics agent (free graphs in the control panel).') }}</span>
                                                        </span>
                                                    </label>
                                                </div>
                                                <div>
                                                    <x-input-label for="do_vpc_uuid" :value="__('VPC UUID (optional)')" />
                                                    <x-text-input id="do_vpc_uuid" wire:model="form.do_vpc_uuid" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" />
                                                    <x-input-error :messages="$errors->get('do_vpc_uuid')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="do_tags" :value="__('Tags (optional)')" />
                                                    <x-text-input id="do_tags" wire:model="form.do_tags" type="text" class="mt-1 block w-full" placeholder="env:production, app:api" autocomplete="off" />
                                                    <x-input-error :messages="$errors->get('do_tags')" class="mt-1" />
                                                </div>
                                                <div>
                                                    <x-input-label for="do_user_data" :value="__('Cloud-init user data (optional)')" />
                                                    <textarea id="do_user_data" wire:model="form.do_user_data" rows="5" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm font-mono text-sm focus:border-sky-500 focus:ring-sky-500" placeholder="#cloud-config"></textarea>
                                                    <x-input-error :messages="$errors->get('do_user_data')" class="mt-1" />
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            @endif
                            <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                                <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-medium text-sm text-slate-700 shadow-sm hover:bg-slate-50">{{ __('Cancel') }}</a>
                                @if ($form->provider_credential_id !== '')
                                    <x-primary-button type="submit">{{ __('Create server') }}</x-primary-button>
                                @endif
                            </div>
                        </form>
                    </section>
                @else
                    <section aria-labelledby="custom-details-heading">
                        <h2 id="custom-details-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('Custom server details') }}</h2>

                        <form wire:submit="store" class="mt-4 space-y-6">
                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
                                <div>
                                    <x-input-label for="custom_name" :value="__('Server name')" />
                                    <div class="mt-1 flex gap-2">
                                        <x-text-input id="custom_name" wire:model="form.name" type="text" class="block w-full" required />
                                        <button
                                            type="button"
                                            wire:click="regenerateServerName"
                                            class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
                                        >
                                            {{ __('Regenerate') }}
                                        </button>
                                    </div>
                                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ip_address" :value="__('IP address')" />
                                    <x-text-input id="ip_address" wire:model="form.ip_address" type="text" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('ip_address')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ssh_port" :value="__('SSH port')" />
                                    <x-text-input id="ssh_port" wire:model="form.ssh_port" type="number" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('ssh_port')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ssh_user" :value="__('SSH user')" />
                                    <x-text-input id="ssh_user" wire:model="form.ssh_user" type="text" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('ssh_user')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="ssh_private_key" :value="__('SSH private key (PEM / OpenSSH)')" />
                                    <textarea id="ssh_private_key" wire:model="form.ssh_private_key" rows="6" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm font-mono text-sm focus:border-sky-500 focus:ring-sky-500"></textarea>
                                    <x-input-error :messages="$errors->get('ssh_private_key')" class="mt-1" />
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                                <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-medium text-sm text-slate-700 shadow-sm hover:bg-slate-50">{{ __('Cancel') }}</a>
                                <x-primary-button type="submit">{{ __('Create server') }}</x-primary-button>
                            </div>
                        </form>
                    </section>
                @endif
            </div>
        </div>
    </div>
</div>
