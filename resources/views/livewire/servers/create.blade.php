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
                        <div class="mt-4 grid gap-4 md:grid-cols-3">
                            <button
                                type="button"
                                wire:click="$set('form.type', 'digitalocean')"
                                class="rounded-2xl border-2 p-5 text-left transition {{ ! in_array($form->type, ['custom', 'digitalocean_functions', 'digitalocean_kubernetes', 'aws_lambda'], true) ? 'border-sky-600 bg-sky-50/80 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                            >
                                <span class="block text-lg font-semibold text-slate-900">{{ __('Cloud server') }}</span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600">
                                    {{ __('Provision a new server with a connected provider, then choose the account, region, size, and core stack.') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="$set('form.type', 'digitalocean_functions')"
                                class="rounded-2xl border-2 p-5 text-left transition {{ $form->type === 'digitalocean_functions' ? 'border-sky-600 bg-sky-50/80 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                            >
                                <span class="block text-lg font-semibold text-slate-900">{{ __('DigitalOcean Functions') }}</span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600">
                                    {{ __('Use the existing server flow, but back the host with a DigitalOcean Functions namespace instead of an SSH machine.') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="$set('form.type', 'digitalocean_kubernetes')"
                                class="rounded-2xl border-2 p-5 text-left transition {{ $form->type === 'digitalocean_kubernetes' ? 'border-sky-600 bg-sky-50/80 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                            >
                                <span class="block text-lg font-semibold text-slate-900">{{ __('DigitalOcean Kubernetes') }}</span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600">
                                    {{ __('Register a managed Kubernetes cluster target backed by your DigitalOcean account instead of an SSH server.') }}
                                </span>
                            </button>
                            <button
                                type="button"
                                wire:click="$set('form.type', 'aws_lambda')"
                                class="rounded-2xl border-2 p-5 text-left transition {{ $form->type === 'aws_lambda' ? 'border-sky-600 bg-sky-50/80 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300' }}"
                            >
                                <span class="block text-lg font-semibold text-slate-900">{{ __('AWS Lambda') }}</span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600">
                                    {{ __('Use the repo-first serverless flow with AWS credentials so Laravel/PHP projects can deploy through a Lambda/Bref target.') }}
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

                @if ($hasAnyProviderCredentials && ! in_array($form->type, ['custom', 'digitalocean_functions', 'digitalocean_kubernetes', 'aws_lambda'], true))
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
                                            <p class="mt-1 text-sm text-slate-600">
                                                {{ __('Start with the essentials. You can fine-tune the stack later in advanced options.') }}
                                            </p>
                                        </div>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600">
                                            {{ __('Essentials first') }}
                                        </span>
                                    </div>

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

                                    @php
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

                                    <section class="space-y-4">
                                        <div>
                                            <h4 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('1. Choose an install profile') }}</h4>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('Start with a preset and then fine-tune anything in advanced options.') }}</p>
                                            <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]">
                                                <div>
                                                    <x-input-label for="install_profile" :value="__('Install profile')" />
                                                    <select wire:model.live="form.install_profile" id="install_profile" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                        @foreach ($installProfiles ?? [] as $profile)
                                                            <option value="{{ $profile['id'] }}">{{ $profile['label'] }}</option>
                                                        @endforeach
                                                    </select>
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

                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <h4 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('2. Choose the server role') }}</h4>
                                                <p class="mt-1 text-sm text-slate-600">{{ __('Pick what this machine is mainly responsible for. We will adapt the default software stack to match.') }}</p>
                                            </div>
                                        </div>

                                        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]">
                                            <div>
                                            <x-input-label for="server_role" :value="__('Server type')" />
                                            <select wire:model.live="form.server_role" id="server_role" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                @foreach ($provisionOptions['server_roles'] ?? [] as $role)
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
                                                            @if (! empty($selectedServerRole['summary'] ?? null))
                                                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $selectedServerRole['summary'] }}</p>
                                                            @elseif (! empty($selectedServerRole['detail'] ?? null))
                                                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $selectedServerRole['detail'] }}</p>
                                                            @endif
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
                                                                    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-200">
                                                                        {{ $install }}
                                                                    </span>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endif

                                                    <dl class="mt-4 space-y-3 text-sm">
                                                        @if (! empty($selectedServerRole['best_for'] ?? null))
                                                            <div>
                                                                <dt class="font-medium text-slate-900">{{ __('Best for') }}</dt>
                                                                <dd class="mt-1 leading-6 text-slate-600">{{ $selectedServerRole['best_for'] }}</dd>
                                                            </div>
                                                        @endif
                                                        @if (! empty($selectedServerRole['does_not_include'] ?? null))
                                                            <div>
                                                                <dt class="font-medium text-slate-900">{{ __('Not included') }}</dt>
                                                                <dd class="mt-1 leading-6 text-slate-600">{{ $selectedServerRole['does_not_include'] }}</dd>
                                                            </div>
                                                        @endif
                                                    </dl>
                                                </div>
                                            @endif
                                        </div>

                                        @if ($availableWebservers->isNotEmpty())
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <h4 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('3. Choose the web server') }}</h4>
                                                    <p class="mt-1 text-sm text-slate-600">{{ __('Pick the web server Dply should install for this machine. NGINX stays selected by default because it is the safest path for most PHP apps.') }}</p>
                                                </div>
                                            </div>

                                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]">
                                                <div>
                                                    <x-input-label for="webserver" :value="__('Web server')" />
                                                    <select wire:model.live="form.webserver" id="webserver" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                        @foreach ($availableWebservers as $opt)
                                                            <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <p class="mt-1 text-xs text-slate-500">{{ __('Choose the default web stack for this host. You can still adjust PHP, database, and cache settings below.') }}</p>
                                                    <x-input-error :messages="$errors->get('webserver')" class="mt-1" />
                                                </div>

                                                @if ($selectedWebserver)
                                                    <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                                                        <div class="flex items-start justify-between gap-3">
                                                            <div>
                                                                <p class="text-sm font-semibold text-slate-900">{{ $selectedWebserver['label'] }}</p>
                                                                @if (! empty($selectedWebserver['summary'] ?? null))
                                                                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ $selectedWebserver['summary'] }}</p>
                                                                @endif
                                                            </div>
                                                            <span class="inline-flex shrink-0 items-center rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-600 ring-1 ring-slate-200">
                                                                {{ ! empty($selectedWebserver['recommended'] ?? false) ? __('Default') : __('Option') }}
                                                            </span>
                                                        </div>

                                                        <dl class="mt-4 space-y-3 text-sm">
                                                            @if (! empty($selectedWebserver['pros'] ?? []))
                                                                <div>
                                                                    <dt class="font-medium text-slate-900">{{ __('Pros') }}</dt>
                                                                    <dd class="mt-2 flex flex-wrap gap-2">
                                                                        @foreach ($selectedWebserver['pros'] as $pro)
                                                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-800 ring-1 ring-emerald-100">
                                                                                {{ $pro }}
                                                                            </span>
                                                                        @endforeach
                                                                    </dd>
                                                                </div>
                                                            @endif
                                                            @if (! empty($selectedWebserver['cons'] ?? []))
                                                                <div>
                                                                    <dt class="font-medium text-slate-900">{{ __('Tradeoffs') }}</dt>
                                                                    <dd class="mt-2 flex flex-wrap gap-2">
                                                                        @foreach ($selectedWebserver['cons'] as $con)
                                                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800 ring-1 ring-amber-100">
                                                                                {{ $con }}
                                                                            </span>
                                                                        @endforeach
                                                                    </dd>
                                                                </div>
                                                            @endif
                                                        </dl>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
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
                                                                    x-on:click="open = false; mapOpen = true; window.dispatchEvent(new CustomEvent('dply:region-map-open'))"
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
                                                                            @php
                                                                                $plotlyRegionMarkers = collect([
                                                                                    ['value' => 'nyc1', 'label' => 'New York', 'lat' => 40.7128, 'lon' => -74.0060],
                                                                                    ['value' => 'nyc2', 'label' => 'New York', 'lat' => 40.7128, 'lon' => -74.0060],
                                                                                    ['value' => 'nyc3', 'label' => 'New York', 'lat' => 40.7128, 'lon' => -74.0060],
                                                                                    ['value' => 'tor1', 'label' => 'Toronto', 'lat' => 43.6532, 'lon' => -79.3832],
                                                                                    ['value' => 'sfo1', 'label' => 'San Francisco', 'lat' => 37.7749, 'lon' => -122.4194],
                                                                                    ['value' => 'sfo2', 'label' => 'San Francisco', 'lat' => 37.7749, 'lon' => -122.4194],
                                                                                    ['value' => 'sfo3', 'label' => 'San Francisco', 'lat' => 37.7749, 'lon' => -122.4194],
                                                                                    ['value' => 'atl1', 'label' => 'Atlanta', 'lat' => 33.7490, 'lon' => -84.3880],
                                                                                    ['value' => 'ams2', 'label' => 'Amsterdam', 'lat' => 52.3676, 'lon' => 4.9041],
                                                                                    ['value' => 'ams3', 'label' => 'Amsterdam', 'lat' => 52.3676, 'lon' => 4.9041],
                                                                                    ['value' => 'lon1', 'label' => 'London', 'lat' => 51.5072, 'lon' => -0.1276],
                                                                                    ['value' => 'fra1', 'label' => 'Frankfurt', 'lat' => 50.1109, 'lon' => 8.6821],
                                                                                    ['value' => 'blr1', 'label' => 'Bangalore', 'lat' => 12.9716, 'lon' => 77.5946],
                                                                                    ['value' => 'sgp1', 'label' => 'Singapore', 'lat' => 1.3521, 'lon' => 103.8198],
                                                                                    ['value' => 'syd1', 'label' => 'Sydney', 'lat' => -33.8688, 'lon' => 151.2093],
                                                                                ])->filter(
                                                                                    fn (array $marker) => $regionOptions->contains(fn (array $region) => ($region['value'] ?? null) === $marker['value'])
                                                                                )->values();
                                                                            @endphp

                                                                            <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                                                                                <div class="mb-3 text-sm font-medium text-slate-700">{{ __('Interactive world map') }}</div>
                                                                                <div
                                                                                    data-region-map
                                                                                    data-selected-region="{{ $form->region }}"
                                                                                    data-region-points='@json($plotlyRegionMarkers)'
                                                                                    class="h-[24rem] w-full overflow-hidden rounded-2xl border border-slate-200"
                                                                                ></div>
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
                                                                    @php
                                                                        $selectedRecommendation = $selectedSizeCard
                                                                            ? collect($catalog['sizes'] ?? [])->firstWhere('value', $selectedSizeCard['value'])
                                                                            : null;
                                                                    @endphp
                                                                    @if (($selectedRecommendation['recommendation']['state'] ?? null) === 'good_starting_point')
                                                                        <div class="mt-2 text-[11px] font-medium text-emerald-700">
                                                                            {{ $selectedRecommendation['recommendation']['detail'] ?? __('Balanced starting size for the selected install profile.') }}
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
                                                                                @php
                                                                                    $rawSize = collect($catalog['sizes'] ?? [])->firstWhere('value', $sizeCard['value']);
                                                                                @endphp
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
                                    @if ($form->type === 'scaleway')
                                        <p class="text-xs text-slate-500">{{ __('Choose a zone first; instance types load for that zone.') }}</p>
                                    @endif
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5 space-y-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and cost preview') }}</h3>
                                            <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] }}</p>
                                        </div>
                                        <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
                                            {{ match($preflight['status']) {
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
                                                        {{ match($groupKey) {
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
                                                                        @if ($check['key'] === 'user_ssh_keys')
                                                                            <div class="mt-3">
                                                                                <button
                                                                                    type="button"
                                                                                    x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                                                                                    class="inline-flex items-center rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-700 shadow-sm transition hover:bg-rose-50"
                                                                                >
                                                                                    {{ __('Add SSH key') }}
                                                                                </button>
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                    <span class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                                                                        {{ $check['blocking'] ? __('Blocking') : match($check['severity']) {
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

                                <details class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                                    <summary class="cursor-pointer list-none text-base font-semibold text-slate-900">
                                        {{ __('Advanced options') }}
                                    </summary>
                                    <div class="mt-2 text-sm text-slate-600">
                                        {{ __('Adjust cache, runtime versions, database choices, and optional provisioning behavior only if you need something specific.') }}
                                    </div>
                                    <div class="mt-5 space-y-5">
                                        <div>
                                            <x-input-label for="cache_service" :value="__('Cache service')" />
                                            <select wire:model="form.cache_service" id="cache_service" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                                @foreach ($provisionOptions['cache_services'] ?? [] as $opt)
                                                    <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </select>
                                            <p class="mt-1 text-xs text-slate-500">{{ __('Used when this server role includes an application stack or cache-backed services.') }}</p>
                                            <x-input-error :messages="$errors->get('cache_service')" class="mt-1" />
                                        </div>

                                        <div class="grid gap-4 sm:grid-cols-2" wire:key="provision-stack-{{ $form->server_role }}">
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
                @elseif ($hasAnyProviderCredentials && $form->type === 'aws_lambda')
                    <section aria-labelledby="aws-lambda-details-heading">
                        <h2 id="aws-lambda-details-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('2. AWS Lambda setup') }}</h2>

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

                        <form wire:submit="store" class="mt-4 space-y-6">
                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-5">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">{{ __('Lambda target basics') }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('This target keeps the same server and site workflow, but deploys repo builds to AWS Lambda so Laravel/PHP projects can use the Bref path without an SSH machine.') }}</p>
                                </div>

                                <div>
                                    <x-input-label for="aws_lambda_name" :value="__('Host name')" />
                                    <div class="mt-1 flex gap-2">
                                        <x-text-input id="aws_lambda_name" wire:model="form.name" type="text" class="block w-full" required autocomplete="off" />
                                        <button type="button" wire:click="regenerateServerName" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            {{ __('Regenerate') }}
                                        </button>
                                    </div>
                                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                                </div>

                                <div class="grid gap-5 md:grid-cols-2">
                                    <div>
                                        <x-input-label for="provider_credential_id_aws_lambda" :value="__('AWS credential')" />
                                        <select wire:model.live="form.provider_credential_id" id="provider_credential_id_aws_lambda" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500" @if($catalog['credentials']->isEmpty()) disabled @endif>
                                            <option value="">{{ __('Select account') }}</option>
                                            @foreach ($catalog['credentials'] as $c)
                                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('provider_credential_id')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="aws_lambda_region" :value="__('Lambda region')" />
                                        <x-text-input id="aws_lambda_region" wire:model="form.aws_lambda_region" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="us-east-1" autocomplete="off" />
                                        <p class="mt-2 text-sm text-slate-600">{{ __('Choose the region where the target Lambda functions already live, such as `us-east-1`.') }}</p>
                                        <x-input-error :messages="$errors->get('aws_lambda_region')" class="mt-1" />
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5 space-y-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and runtime notes') }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
                                        {{ match($preflight['status']) {
                                            'ready' => __('Ready'),
                                            'warning' => __('Needs review'),
                                            default => __('Blocked'),
                                        } }}
                                    </span>
                                </div>

                                <div class="space-y-4">
                                    @foreach ($preflight['groups'] as $groupChecks)
                                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                            <div class="space-y-3">
                                                @foreach ($groupChecks as $check)
                                                    <div class="rounded-xl border px-4 py-3 {{ $preflightItemClasses($check['severity']) }}">
                                                        <p class="text-sm font-semibold">{{ $check['label'] }}</p>
                                                        <p class="mt-1 text-sm leading-6">{{ $check['detail'] }}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600">
                                    {{ __('Create server') }}
                                </button>
                            </div>
                        </form>
                    </section>
                @elseif ($hasAnyProviderCredentials && $form->type === 'digitalocean_functions')
                    <section aria-labelledby="functions-details-heading">
                        <h2 id="functions-details-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('2. DigitalOcean Functions setup') }}</h2>

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

                        <form wire:submit="store" class="mt-4 space-y-6">
                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-5">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">{{ __('Functions host basics') }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('This keeps the existing server workflow entry point, but the resulting host uses a DigitalOcean Functions namespace instead of an SSH machine.') }}</p>
                                </div>

                                <div>
                                    <x-input-label for="functions_name" :value="__('Host name')" />
                                    <div class="mt-1 flex gap-2">
                                        <x-text-input id="functions_name" wire:model="form.name" type="text" class="block w-full" required autocomplete="off" />
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
                                    <x-input-label for="provider_credential_id_functions" :value="__('DigitalOcean credential')" />
                                    <select
                                        wire:model.live="form.provider_credential_id"
                                        id="provider_credential_id_functions"
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

                                <div class="grid gap-5 md:grid-cols-2">
                                    <div>
                                        <x-input-label for="do_functions_api_host" :value="__('Functions API host')" />
                                        <x-text-input id="do_functions_api_host" wire:model="form.do_functions_api_host" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="https://faas-nyc1-xxxx.doserverless.co" autocomplete="off" />
                                        <p class="mt-2 text-sm text-slate-600">{{ __('Use the API host returned by `doctl serverless connect`.') }}</p>
                                        <x-input-error :messages="$errors->get('do_functions_api_host')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="do_functions_namespace" :value="__('Namespace')" />
                                        <x-text-input id="do_functions_namespace" wire:model="form.do_functions_namespace" type="text" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                                        <x-input-error :messages="$errors->get('do_functions_namespace')" class="mt-1" />
                                    </div>
                                    <div class="md:col-span-2">
                                        <x-input-label for="do_functions_access_key" :value="__('Namespace access key')" />
                                        <textarea id="do_functions_access_key" wire:model="form.do_functions_access_key" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm font-mono text-sm focus:border-sky-500 focus:ring-sky-500" placeholder="dof_v1_xxx:secret"></textarea>
                                        <p class="mt-2 text-sm text-slate-600">{{ __('Paste the namespace access key in `id:secret` format. This is separate from your DigitalOcean API token.') }}</p>
                                        <x-input-error :messages="$errors->get('do_functions_access_key')" class="mt-1" />
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5 space-y-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and runtime notes') }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
                                        {{ match($preflight['status']) {
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
                                                {{ match($groupKey) {
                                                    'account_readiness' => __('Account readiness'),
                                                    'verification' => __('Verification'),
                                                    default => __('Runtime readiness'),
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
                                                                {{ $check['blocking'] ? __('Blocking') : match($check['severity']) {
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
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                                <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-medium text-sm text-slate-700 shadow-sm hover:bg-slate-50">{{ __('Cancel') }}</a>
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
                @elseif ($hasAnyProviderCredentials && $form->type === 'digitalocean_kubernetes')
                    <section aria-labelledby="kubernetes-details-heading">
                        <h2 id="kubernetes-details-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('2. DigitalOcean Kubernetes setup') }}</h2>

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

                        <form wire:submit="store" class="mt-4 space-y-6">
                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-5">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">{{ __('Cluster target basics') }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Use this when Dply should target a managed DigitalOcean Kubernetes cluster instead of provisioning an SSH machine.') }}</p>
                                </div>

                                <div>
                                    <x-input-label for="kubernetes_name" :value="__('Target name')" />
                                    <div class="mt-1 flex gap-2">
                                        <x-text-input id="kubernetes_name" wire:model="form.name" type="text" class="block w-full" required autocomplete="off" />
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
                                    <x-input-label for="provider_credential_id_kubernetes" :value="__('DigitalOcean credential')" />
                                    <select
                                        wire:model.live="form.provider_credential_id"
                                        id="provider_credential_id_kubernetes"
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

                                <div class="grid gap-5 md:grid-cols-2">
                                    <div>
                                        <x-input-label for="do_kubernetes_cluster_name" :value="__('Cluster name')" />
                                        <x-text-input id="do_kubernetes_cluster_name" wire:model="form.do_kubernetes_cluster_name" type="text" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                                        <p class="mt-2 text-sm text-slate-600">{{ __('Enter the managed cluster name exactly as it appears in DigitalOcean.') }}</p>
                                        <x-input-error :messages="$errors->get('do_kubernetes_cluster_name')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="do_kubernetes_namespace" :value="__('Namespace')" />
                                        <x-text-input id="do_kubernetes_namespace" wire:model="form.do_kubernetes_namespace" type="text" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                                        <x-input-error :messages="$errors->get('do_kubernetes_namespace')" class="mt-1" />
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5 space-y-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and runtime notes') }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
                                        {{ match($preflight['status']) {
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
                                                {{ match($groupKey) {
                                                    'account_readiness' => __('Account readiness'),
                                                    'verification' => __('Verification'),
                                                    default => __('Cluster readiness'),
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
                                                                {{ $check['blocking'] ? __('Blocking') : match($check['severity']) {
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
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                                <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-medium text-sm text-slate-700 shadow-sm hover:bg-slate-50">{{ __('Cancel') }}</a>
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
                                    <x-input-label for="custom_host_kind" :value="__('Host target')" />
                                    <select id="custom_host_kind" wire:model="form.custom_host_kind" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                                        <option value="vm">{{ __('Standard VM / VPS') }}</option>
                                        <option value="docker">{{ __('Docker host') }}</option>
                                    </select>
                                    <p class="mt-2 text-sm text-slate-600">{{ __('Use Docker host when this machine should run container-based site deploys instead of the classic SSH + webserver stack.') }}</p>
                                    <x-input-error :messages="$errors->get('custom_host_kind')" class="mt-1" />
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
                                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ __('Test connection') }}</p>
                                            <p class="mt-1 text-sm text-slate-600">{{ __('Verify the current host, username, and private key before saving this custom server.') }}</p>
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

                            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5 space-y-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-900">{{ __('Preflight and cost preview') }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">{{ $preflight['summary'] }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] ring-1 {{ $preflightBadgeClasses }}">
                                        {{ match($preflight['status']) {
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
                                                {{ match($groupKey) {
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
                                                                {{ $check['blocking'] ? __('Blocking') : match($check['severity']) {
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
                                <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-medium text-sm text-slate-700 shadow-sm hover:bg-slate-50">{{ __('Cancel') }}</a>
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
                @endif
            </div>
        </div>

        <x-slot name="modals">
        <livewire:profile.personal-ssh-key-modal source="servers.create" />
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
