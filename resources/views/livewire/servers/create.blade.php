@php
    $preflightBadgeClasses = match ($preflight['status']) {
        'ready' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-rose-50 text-rose-700 ring-rose-200',
    };
    $preflightItemClasses = static function (string $severity): string {
        return match ($severity) {
            'info' => 'border-emerald-200 bg-emerald-50/70 text-emerald-900',
            'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
            default => 'border-rose-200 bg-rose-50 text-rose-900',
        };
    };
@endphp

<div>
    <div class="border-b border-slate-200 bg-white">
        <div class="dply-page-shell py-8">
            <x-page-header
                :title="__('Create BYO server')"
                :description="__('Bring your own server into Dply by connecting an existing machine over SSH or provisioning a new machine inside your own provider account.')"
                doc-route="docs.create-first-server"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Back to launchpad') }}</a>
                </x-slot>
            </x-page-header>
        </div>
    </div>

    <div class="py-10">
        <div class="dply-page-shell">
            @if (session('error'))
                <div class="mb-4 rounded-lg bg-red-50 p-4 text-red-800">{{ session('error') }}</div>
            @endif

            @error('org')
                <div class="mb-4 rounded-lg bg-red-50 p-4 text-red-800">{{ $message }}</div>
            @enderror

            @if (! $canCreateServer && $billingUrl)
                <div class="mb-8 rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-900">
                    <p class="font-medium">{{ __('Server limit reached for your plan.') }}</p>
                    <p class="mt-1 text-sm">{{ __('Upgrade to add more servers.') }}</p>
                    <a href="{{ $billingUrl }}" class="mt-4 inline-flex items-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">{{ __('Go to billing') }}</a>
                </div>
            @endif

            <div class="@if (! $canCreateServer) pointer-events-none opacity-60 @endif space-y-8">
                @if ($launchSource === 'launches.containers' && $form->custom_host_kind === 'docker')
                    <section class="rounded-2xl border border-sky-200 bg-sky-50/70 p-6">
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">{{ __('Remote Docker path') }}</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-900">{{ __('Create the remote Docker host first') }}</h2>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-700">
                            {{ __('This form is preconfigured for the remote Docker lane. Save the Docker host here, then create a site on it so Dply can assign the Docker runtime profile and prepare Docker deploy artifacts for your project.') }}
                        </p>
                    </section>
                @endif

                <section class="rounded-2xl border border-slate-200 bg-sky-50/70 px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-600">{{ __('Switch launch paths or update SSH keys before you continue.') }}</p>
                        <div class="flex flex-wrap gap-3 text-sm">
                            <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-semibold text-slate-700 hover:bg-slate-50">{{ __('Choose another launch path') }}</a>
                            <a href="{{ route('profile.ssh-keys', ['source' => 'servers.create', 'return_to' => 'servers.create']) }}" wire:navigate class="inline-flex items-center rounded-xl text-sm font-semibold text-sky-700 hover:text-sky-900">{{ __('Manage profile SSH keys') }}</a>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('Choose how to add this BYO server') }}</h2>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <button
                            type="button"
                            wire:click="useExistingServerPath"
                            @class([
                                'rounded-2xl border p-5 text-left transition',
                                'border-sky-300 bg-sky-50/70 shadow-sm' => $createMode === 'existing',
                                'border-slate-200 hover:border-slate-300 hover:bg-slate-50' => $createMode !== 'existing',
                            ])
                        >
                            <p class="text-sm font-semibold text-slate-900">{{ __('Use an existing server') }}</p>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Connect a host you already own over SSH and let Dply manage it as customer-owned infrastructure.') }}</p>
                        </button>

                        <button
                            type="button"
                            wire:click="useProviderProvisioningPath"
                            @class([
                                'rounded-2xl border p-5 text-left transition',
                                'border-sky-300 bg-sky-50/70 shadow-sm' => $createMode === 'provider',
                                'border-slate-200 hover:border-slate-300 hover:bg-slate-50' => $createMode !== 'provider',
                            ])
                        >
                            <p class="text-sm font-semibold text-slate-900">{{ __('Provision with a provider') }}</p>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Create a new machine in DigitalOcean, Hetzner, AWS, or another connected account, then continue into the standard Dply setup flow.') }}</p>
                        </button>
                    </div>

                    @if ($createMode === 'provider')
                        <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <div>
                                    <h3 class="text-lg font-semibold text-slate-900">{{ __('Choose provider') }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Pick the provider that should create the machine. Then connect an account or continue with a saved credential.') }}</p>
                                </div>
                                <a href="{{ route('docs.connect-provider') }}" wire:navigate class="text-sm font-medium text-sky-700 hover:text-sky-900">{{ __('Provider setup guide') }}</a>
                            </div>

                            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($provisionProviderCards as $card)
                                    <button
                                        type="button"
                                        wire:click="useProviderProvisioningPath('{{ $card['id'] }}')"
                                        @class([
                                            'rounded-2xl border p-4 text-left transition',
                                            'border-sky-300 bg-white shadow-sm ring-2 ring-sky-200' => $createMode === 'provider' && $form->type === $card['id'],
                                            'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' => ! ($createMode === 'provider' && $form->type === $card['id']),
                                        ])
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-sm font-semibold text-slate-900">{{ $card['label'] }}</p>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $card['linked'] ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">
                                                {{ $card['linked'] ? __('Connected') : __('Needs account') }}
                                            </span>
                                        </div>
                                    </button>
                                @endforeach
                            </div>

                            <div class="mt-4 flex flex-wrap gap-6 text-sm text-slate-600">
                                <p>{{ __('Choose account') }}</p>
                                <p>{{ __('Add a provider credential') }}</p>
                            </div>
                        </div>
                    @endif
                </section>

                @if ($createMode === 'provider')
                    <section class="space-y-6" aria-labelledby="provider-provisioning-heading">
                        <div>
                            <h2 id="provider-provisioning-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('Provision with a provider') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Choose a provider, connect or select an account, then define the server shape and software defaults for the machine Dply should build.') }}</p>
                        </div>

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

                            $selectedInstallProfile = collect($installProfiles ?? [])->firstWhere('id', $form->install_profile);
                            $selectedServerRole = collect($provisionOptions['server_roles'] ?? [])->firstWhere('id', $form->server_role);
                            $serverRoleInstalls = collect($selectedServerRole['installs'] ?? [])
                                ->filter(fn ($item) => filled($item))
                                ->take(5)
                                ->values();
                            $availableWebservers = collect($provisionOptions['webservers'] ?? [])
                                ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'none')
                                ->values();
                            $selectedWebserver = $availableWebservers->firstWhere('id', $form->webserver);
                        @endphp

                        <form wire:submit="store" class="space-y-6">
                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-5">
                                <h3 class="text-base font-semibold text-slate-900">{{ __('Choose account') }}</h3>

                                @if ($catalog['credentials']->isEmpty())
                                    @if ($digitalOceanEnvCatalog)
                                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sky-950">
                                            <p class="font-medium">{{ __('No linked DigitalOcean account') }}</p>
                                            <p class="mt-1 text-sm">
                                                {{ __('Regions and sizes are available from DIGITALOCEAN_TOKEN, but you still need a saved credential before Dply can provision the server.') }}
                                                <a href="{{ route('credentials.index') }}" wire:navigate class="underline font-medium">{{ __('Go to Server providers') }}</a>
                                            </p>
                                        </div>
                                    @else
                                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                                            <p class="font-medium">{{ __('Add a credential first') }}</p>
                                            <p class="mt-1 text-sm">
                                                {{ __('Save an API token for this provider under Server providers, then return here to continue with provisioning.') }}
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
                                        @if ($catalog['credentials']->isEmpty()) disabled @endif
                                    >
                                        <option value="">{{ __('Select account') }}</option>
                                        @foreach ($catalog['credentials'] as $credential)
                                            <option value="{{ $credential->id }}">{{ $credential->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                                </div>

                                @if ($catalog['credentials']->isNotEmpty() && $form->provider_credential_id === '')
                                    <p class="text-sm text-slate-600">{{ __('Choose an account above to continue with cloud provisioning.') }}</p>
                                @endif

                                @if ($preflight['provider_health'])
                                    <div class="rounded-xl border px-4 py-3 {{ $preflightItemClasses($preflight['provider_health']['severity']) }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-semibold">{{ __('Credential health') }}</p>
                                                <p class="mt-1 text-sm leading-6">{{ $preflight['provider_health']['detail'] }}</p>
                                                @if ($preflight['provider_health']['provider_message'])
                                                    <p class="mt-2 text-xs opacity-80">{{ $preflight['provider_health']['provider_message'] }}</p>
                                                @endif
                                            </div>
                                            <span class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                                                {{ str((string) $preflight['provider_health']['status'])->replace('_', ' ')->title() }}
                                            </span>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            @if ($showCloudStackFields)
                                <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-6">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-base font-semibold text-slate-900">{{ __('Core server config') }}</h3>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('Start with the essentials. You can fine-tune the stack later in advanced options.') }}</p>
                                        </div>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600">
                                            {{ __('Essentials first') }}
                                        </span>
                                    </div>

                                    <div>
                                        <x-input-label for="provider_server_name" :value="__('Server name')" />
                                        <div class="mt-1 flex gap-2">
                                            <x-text-input id="provider_server_name" wire:model="form.name" type="text" class="block w-full" required autocomplete="off" />
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

                                    <section class="space-y-4">
                                        <div>
                                            <h4 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('1. Choose an install profile') }}</h4>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('Start with a preset and then fine-tune anything in advanced options.') }}</p>
                                            <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]">
                                                <div>
                                                    <x-input-label for="install_profile" :value="__('Install profile')" />
                                                    <select wire:model.live="form.install_profile" id="install_profile" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                        @foreach ($installProfiles as $profile)
                                                            <option value="{{ $profile['id'] }}">{{ $profile['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$errors->get('install_profile')" class="mt-1" />
                                                </div>

                                                @if ($selectedInstallProfile)
                                                    <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div>
                                                                <p class="text-sm font-semibold text-slate-900">{{ $selectedInstallProfile['label'] }}</p>
                                                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $selectedInstallProfile['summary'] ?? '' }}</p>
                                                            </div>
                                                            <span class="inline-flex shrink-0 items-center rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600 ring-1 ring-slate-200">
                                                                {{ __('Preset') }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <div>
                                            <h4 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('2. Choose the server role') }}</h4>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('Pick what this machine is mainly responsible for. We will adapt the default software stack to match.') }}</p>
                                            <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]">
                                                <div>
                                                    <x-input-label for="server_role" :value="__('Server type')" />
                                                    <select wire:model.live="form.server_role" id="server_role" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                        @foreach ($provisionOptions['server_roles'] as $role)
                                                            <option value="{{ $role['id'] }}">{{ $role['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$errors->get('server_role')" class="mt-1" />
                                                </div>

                                                @if ($selectedServerRole)
                                                    <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div>
                                                                <p class="text-sm font-semibold text-slate-900">{{ $selectedServerRole['label'] }}</p>
                                                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $selectedServerRole['summary'] ?? ($selectedServerRole['detail'] ?? '') }}</p>
                                                            </div>
                                                            <span class="inline-flex shrink-0 items-center rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600 ring-1 ring-slate-200">
                                                                {{ __('Role') }}
                                                            </span>
                                                        </div>

                                                        @if ($serverRoleInstalls->isNotEmpty())
                                                            <div class="mt-4">
                                                                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Default installs') }}</p>
                                                                <div class="mt-2 flex flex-wrap gap-2">
                                                                    @foreach ($serverRoleInstalls as $install)
                                                                        <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-200">{{ $install }}</span>
                                                                    @endforeach
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </section>

                                    <div
                                        class="grid gap-4 sm:grid-cols-2"
                                        wire:key="catalog-{{ $form->type }}-{{ $form->provider_credential_id }}-{{ $form->region }}"
                                    >
                                        <div>
                                            <x-input-label for="form_region" :value="$catalog['region_label']" />
                                            @php
                                                $regionOptions = collect($catalog['regions'] ?? [])->values();
                                                $selectedRegionOption = $regionOptions->firstWhere('value', $form->region);
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
                                                x-data="{ open: false, search: '', mapOpen: false }"
                                                x-on:dply-region-selected.window="$wire.set('form.region', $event.detail.value); mapOpen = false"
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
                                                        @if (! $regionSizePickReady) disabled @endif
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
                                                                <x-heroicon-m-chevron-down class="h-5 w-5 transition-transform" aria-hidden="true" />
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
                                                        <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                                                            <div class="flex items-center justify-between gap-3">
                                                                <div>
                                                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Map') }}</div>
                                                                    <div class="mt-1 text-sm text-slate-600">{{ __('Open the full map modal for easier geographic selection.') }}</div>
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
                                                                    x-show="'{{ Str::lower((string) ($regionOption['label'] ?? '')) }}'.includes(search.toLowerCase()) || '{{ Str::lower((string) ($regionOption['value'] ?? '')) }}'.includes(search.toLowerCase())"
                                                                    aria-selected="{{ $form->region === $regionOption['value'] ? 'true' : 'false' }}"
                                                                    class="w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $form->region === $regionOption['value'] ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                                                                >
                                                                    <div class="flex items-start justify-between gap-4">
                                                                        <div class="min-w-0">
                                                                            <div class="truncate text-sm font-semibold text-slate-900">{{ $regionOption['label'] }}</div>
                                                                            <div class="mt-1 text-[11px] uppercase tracking-[0.16em] text-slate-500">{{ $regionOption['value'] }}</div>
                                                                        </div>
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
                                                    >
                                                        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="mapOpen = false"></div>

                                                        <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
                                                            <div class="relative w-full max-w-7xl overflow-hidden rounded-3xl border border-brand-ink/10 bg-white shadow-2xl">
                                                                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                                                                    <div>
                                                                        <h3 class="text-lg font-semibold text-slate-900">{{ __('Region map') }}</h3>
                                                                        <p class="mt-1 text-sm text-slate-600">{{ __('Choose a region visually, or use the grouped list below.') }}</p>
                                                                    </div>
                                                                    <button type="button" x-on:click="mapOpen = false" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">
                                                                        <x-heroicon-m-x-mark class="h-5 w-5" aria-hidden="true" />
                                                                    </button>
                                                                </div>

                                                                <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.9fr)]">
                                                                    <div class="rounded-3xl border border-slate-200 bg-[linear-gradient(180deg,#dbeafe_0%,#eff6ff_55%,#f8fafc_100%)] p-5">
                                                                        <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                                                                            <div class="mb-3 text-sm font-medium text-slate-700">{{ __('Interactive world map') }}</div>
                                                                            <div
                                                                                data-region-map
                                                                                data-selected-region="{{ $form->region }}"
                                                                                data-region-points='@json($digitalOceanRegionMarkers)'
                                                                                class="h-[24rem] w-full overflow-hidden rounded-2xl border border-slate-200"
                                                                            ></div>
                                                                        </div>
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
                                                        @if (! $regionSizePickReady) disabled @endif
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
                                                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] tracking-[0.16em] text-emerald-700 ring-1 ring-emerald-200">{{ __('Recommended') }}</span>
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
                                                                @else
                                                                    <div class="mt-1 text-sm text-slate-500">{{ __('Select a plan') }}</div>
                                                                @endif
                                                            </div>
                                                            <div class="shrink-0 pt-1 text-slate-400" x-bind:class="{ 'rotate-180': open }">
                                                                <x-heroicon-m-chevron-down class="h-5 w-5 transition-transform" aria-hidden="true" />
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
                                                        <div class="max-h-96 space-y-2 overflow-y-auto overscroll-contain pr-1">
                                                            @foreach ($sizeCards as $sizeCard)
                                                                @php
                                                                    $rawSize = collect($catalog['sizes'] ?? [])->firstWhere('value', $sizeCard['value']);
                                                                @endphp
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
                                                                                @if (($rawSize['recommendation']['state'] ?? null) === 'good_starting_point')
                                                                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-emerald-700 ring-1 ring-emerald-200">{{ __('Good starting point') }}</span>
                                                                                @elseif (($rawSize['recommendation']['state'] ?? null) === 'too_small')
                                                                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-amber-700 ring-1 ring-amber-200">{{ __('Too small') }}</span>
                                                                                @elseif (($rawSize['recommendation']['state'] ?? null) === 'overkill')
                                                                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-700 ring-1 ring-slate-200">{{ __('Overkill') }}</span>
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

                                        <div class="rounded-2xl border border-slate-200 p-4">
                                            <h4 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('4. Default stack') }}</h4>
                                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                                <div>
                                                    <x-input-label for="webserver" :value="__('Web server')" />
                                                    <select id="webserver" wire:model.live="form.webserver" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                        @foreach ($provisionOptions['webservers'] as $option)
                                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$errors->get('webserver')" class="mt-1" />
                                                </div>

                                                <div>
                                                    <x-input-label for="php_version" :value="__('PHP version')" />
                                                    <select id="php_version" wire:model.live="form.php_version" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                        @foreach ($provisionOptions['php_versions'] as $option)
                                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$errors->get('php_version')" class="mt-1" />
                                                </div>

                                                <div>
                                                    <x-input-label for="database" :value="__('Database')" />
                                                    <select id="database" wire:model.live="form.database" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                        @foreach ($provisionOptions['databases'] as $option)
                                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$errors->get('database')" class="mt-1" />
                                                </div>

                                                <div>
                                                    <x-input-label for="cache_service" :value="__('Cache service')" />
                                                    <select id="cache_service" wire:model.live="form.cache_service" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                        @foreach ($provisionOptions['cache_services'] as $option)
                                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$errors->get('cache_service')" class="mt-1" />
                                                </div>
                                            </div>

                                            @if ($selectedWebserver)
                                                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                                                    <p class="text-sm font-semibold text-slate-900">{{ $selectedWebserver['label'] }}</p>
                                                    <p class="mt-1 text-sm text-slate-600">{{ $selectedWebserver['summary'] ?? $selectedWebserver['detail'] ?? __('Selected as the default web server for this machine.') }}</p>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-5">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">{{ __('Advanced options') }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Use these only when the provider supports them and your rollout needs the extra option.') }}</p>
                                </div>

                                @if ($form->type === 'digitalocean')
                                    <div class="grid gap-3 md:grid-cols-3">
                                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700">
                                            <input type="checkbox" wire:model.live="form.do_backups" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500">
                                            <span>{{ __('Enable DigitalOcean backups') }}</span>
                                        </label>
                                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700">
                                            <input type="checkbox" wire:model.live="form.do_monitoring" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500">
                                            <span>{{ __('Enable monitoring') }}</span>
                                        </label>
                                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-700">
                                            <input type="checkbox" wire:model.live="form.do_ipv6" class="mt-1 rounded border-slate-300 text-sky-600 shadow-sm focus:ring-sky-500">
                                            <span>{{ __('Enable IPv6') }}</span>
                                        </label>
                                    </div>
                                @else
                                    <p class="rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-600">{{ __('Advanced provider-specific toggles are lightweight for this builder right now. The main account, region, size, and stack choices above drive the provisioning flow.') }}</p>
                                @endif
                            </div>

                            <div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and cost preview') }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
                                        {{ match ($preflight['status']) {
                                            'ready' => __('Ready'),
                                            'warning' => __('Needs review'),
                                            default => __('Blocked'),
                                        } }}
                                    </span>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.85fr)]">
                                    <div class="space-y-4">
                                        @foreach ($preflight['groups'] as $groupKey => $groupChecks)
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                                    {{ match ($groupKey) {
                                                        'account_readiness' => __('Account readiness'),
                                                        'infrastructure_selection' => __('Infrastructure selection'),
                                                        'stack_readiness' => __('Stack readiness'),
                                                        'verification' => __('Verification'),
                                                        default => __('Cost clarity'),
                                                    } }}
                                                </p>
                                                <div class="mt-3 space-y-3">
                                                    @foreach ($groupChecks as $check)
                                                        <div class="rounded-xl border px-4 py-3 {{ $preflightItemClasses($check['severity']) }}">
                                                            <div class="flex items-start justify-between gap-3">
                                                                <div>
                                                                    <p class="text-sm font-semibold">{{ $check['label'] }}</p>
                                                                    <p class="mt-1 text-sm leading-6">{{ $check['detail'] }}</p>
                                                                </div>
                                                                <span class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                                                                    {{ $check['blocking'] ? __('Blocking') : match ($check['severity']) {
                                                                        'warning' => __('Warning'),
                                                                        default => __('Ready'),
                                                                    } }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Estimated provider cost') }}</p>
                                        <div class="mt-3 space-y-3">
                                            <div>
                                                <p class="text-sm font-medium text-slate-900">{{ __('Provider') }}</p>
                                                <p class="mt-1 text-sm text-slate-600">{{ str($preflight['cost_preview']['provider'])->replace('_', ' ')->title() }}</p>
                                            </div>
                                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                                                <div>
                                                    <p class="text-sm font-medium text-slate-900">{{ __('Region') }}</p>
                                                    <p class="mt-1 text-sm text-slate-600">{{ $preflight['cost_preview']['region'] ?? __('Not selected') }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-medium text-slate-900">{{ __('Size') }}</p>
                                                    <p class="mt-1 text-sm text-slate-600">{{ $preflight['cost_preview']['size'] ?? __('Not selected') }}</p>
                                                </div>
                                            </div>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Estimate') }}</p>
                                                <p class="mt-1 text-lg font-semibold text-slate-900">{{ $preflight['cost_preview']['formatted_price'] ?? __('Unavailable') }}</p>
                                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $preflight['cost_preview']['detail'] }}</p>
                                            </div>
                                            @if (($preflight['cost_preview']['extras'] ?? []) !== [])
                                                <div class="space-y-2">
                                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Known extras') }}</p>
                                                    @foreach ($preflight['cost_preview']['extras'] as $extra)
                                                        <div class="rounded-xl border border-slate-200 px-3 py-2">
                                                            <div class="flex items-center justify-between gap-3">
                                                                <p class="text-sm font-medium text-slate-900">{{ $extra['label'] }}</p>
                                                                <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ str((string) $extra['state'])->replace('_', ' ')->title() }}</span>
                                                            </div>
                                                            <p class="mt-1 text-sm text-slate-600">{{ $extra['detail'] }}</p>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 font-semibold uppercase tracking-[0.16em] text-slate-600">
                                                    {{ $preflight['cost_preview']['source'] ? str((string) $preflight['cost_preview']['source'])->replace('_', ' ')->title() : __('No price source') }}
                                                </span>
                                                @if ($preflight['cost_preview']['price_hourly'] !== null)
                                                    <span>{{ __('Hourly: $:amount/hr', ['amount' => number_format((float) $preflight['cost_preview']['price_hourly'], 4)]) }}</span>
                                                @endif
                                            </div>
                                            @if (($preflight['cost_preview']['notes'] ?? []) !== [])
                                                <div class="space-y-1 text-xs text-slate-500">
                                                    @foreach ($preflight['cost_preview']['notes'] as $note)
                                                        <p>{{ $note }}</p>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                                <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">{{ __('Cancel') }}</a>
                                <button
                                    type="submit"
                                    @disabled(! $preflight['can_submit'])
                                    class="inline-flex items-center rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600"
                                >
                                    {{ __('Create server') }}
                                </button>
                            </div>
                        </form>
                    </section>
                @elseif ($createMode === 'existing')
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
                                    <x-input-label for="custom_host_kind" :value="__('Host target')" />
                                    <select id="custom_host_kind" wire:model="form.custom_host_kind" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                        <option value="vm">{{ __('Standard VM / VPS') }}</option>
                                        <option value="docker">{{ __('Docker host') }}</option>
                                    </select>
                                    <p class="mt-2 text-sm text-slate-600">{{ __('Use Docker host when this machine should run container-based site deploys instead of the classic SSH plus webserver stack.') }}</p>
                                    <x-input-error :messages="$errors->get('custom_host_kind')" class="mt-1" />
                                </div>

                                <div>
                                    <x-input-label for="ip_address" :value="__('IP address')" />
                                    <x-text-input id="ip_address" wire:model="form.ip_address" type="text" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('ip_address')" class="mt-1" />
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
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
                                </div>

                                <div>
                                    <x-input-label for="ssh_private_key" :value="__('SSH private key (PEM / OpenSSH)')" />
                                    <textarea id="ssh_private_key" wire:model="form.ssh_private_key" rows="6" class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"></textarea>
                                    <x-input-error :messages="$errors->get('ssh_private_key')" class="mt-1" />
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ __('Test connection') }}</p>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('Verify the current host, username, and private key before saving this BYO server.') }}</p>
                                        </div>
                                        <button type="button" wire:click="testCustomConnection" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            {{ __('Test connection') }}
                                        </button>
                                    </div>
                                    @if ($customConnectionTestMessage !== '')
                                        <p class="mt-3 text-sm {{ $customConnectionTestState === 'success' ? 'text-emerald-700' : ($customConnectionTestState === 'error' ? 'text-rose-700' : 'text-amber-700') }}">
                                            {{ $customConnectionTestMessage }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and cost preview') }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
                                        {{ match ($preflight['status']) {
                                            'ready' => __('Ready'),
                                            'warning' => __('Needs review'),
                                            default => __('Blocked'),
                                        } }}
                                    </span>
                                </div>

                                <div class="space-y-4">
                                    @foreach ($preflight['groups'] as $groupKey => $groupChecks)
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                                {{ match ($groupKey) {
                                                    'account_readiness' => __('Account readiness'),
                                                    'infrastructure_selection' => __('Infrastructure selection'),
                                                    'stack_readiness' => __('Stack readiness'),
                                                    'verification' => __('Verification'),
                                                    default => __('Cost clarity'),
                                                } }}
                                            </p>
                                            <div class="mt-3 space-y-3">
                                                @foreach ($groupChecks as $check)
                                                    <div class="rounded-xl border px-4 py-3 {{ $preflightItemClasses($check['severity']) }}">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div>
                                                                <p class="text-sm font-semibold">{{ $check['label'] }}</p>
                                                                <p class="mt-1 text-sm leading-6">{{ $check['detail'] }}</p>
                                                            </div>
                                                            <span class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                                                                {{ $check['blocking'] ? __('Blocking') : match ($check['severity']) {
                                                                    'warning' => __('Warning'),
                                                                    default => __('Ready'),
                                                                } }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Estimated provider cost') }}</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-900">{{ $preflight['cost_preview']['formatted_price'] ?? __('Unavailable') }}</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $preflight['cost_preview']['detail'] }}</p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                                <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">{{ __('Cancel') }}</a>
                                <button
                                    type="submit"
                                    @disabled(! $preflight['can_submit'])
                                    class="inline-flex items-center rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600"
                                >
                                    {{ __('Create BYO server') }}
                                </button>
                            </div>
                        </form>
                    </section>
                @endif
            </div>
        </div>
    </div>
</div>
