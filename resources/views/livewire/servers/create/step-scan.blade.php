@php
    $statusTone = static fn (?string $status): string => match (strtolower((string) $status)) {
        'active', 'running', 'ok' => 'text-emerald-700',
        'off', 'stopped', 'shutting_down', 'offline' => 'text-brand-mist',
        default => 'text-amber-800',
    };
@endphp

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    {{-- Import mode leaves the four-step build path: the machine exists, so
         there is no "what it runs" to choose. Two pills say so plainly. --}}
    <nav aria-label="{{ __('Import server progress') }}" class="mb-4">
        <ol class="flex flex-wrap items-center gap-2 sm:gap-3">
            <li class="flex items-center gap-2 sm:gap-3">
                <a href="{{ route('servers.create', ['edit' => 1]) }}" wire:navigate class="flex items-center gap-2 rounded-full border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-900 transition-colors hover:border-emerald-400 hover:bg-emerald-100">
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-emerald-400 bg-emerald-500 text-xs text-white">
                        <x-heroicon-o-check class="h-3.5 w-3.5" aria-hidden="true" />
                    </span>
                    {{ __('Type & name') }}
                </a>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </li>
            <li>
                <span aria-current="step" class="flex items-center gap-2 rounded-full border border-sky-500 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-900">
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-sky-500 bg-white text-xs text-sky-700">2</span>
                    {{ __('Scan & import') }}
                </span>
            </li>
        </ol>
    </nav>

    <x-hero-card
        compact
        icon="magnifying-glass"
        iconSize="md"
        :eyebrow="__('Step 2 of 2')"
        :title="__('Import an existing server')"
        :description="__('dply reads the machines on your provider account and shows the ones it does not manage yet. Name, address, region and size come from the API — you only supply SSH access.')"
    />

    <div class="mt-4 space-y-4">
        <section class="dply-card overflow-hidden">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-key"
                :title="__('Which account should we scan?')"
                :note="__('Only providers whose API can list machines appear here — DigitalOcean, Hetzner, Linode and Vultr.')"
                class="border-b border-brand-ink/10"
            >
                <x-slot:actions>
                    {{-- Preselects whichever provider is chosen above, so the
                         modal opens on the account you were about to add. --}}
                    <x-add-provider-credential-link
                        :provider="$provider !== '' ? $provider : null"
                        class="!inline-flex !h-6 !shrink-0 !items-center !gap-1 !whitespace-nowrap !rounded-md !border !border-brand-ink/15 !bg-white !px-2 !text-xs !font-semibold !text-brand-ink !shadow-sm !transition hover:!bg-brand-sand/40 hover:!no-underline"
                    >
                        <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ __('Connect account') }}
                    </x-add-provider-credential-link>
                </x-slot:actions>
            </x-workspace-panel-head>

            @if ($scannableProviders === [])
                <x-empty-state
                    borderless
                    compact
                    icon="heroicon-o-key"
                    :title="__('No scannable accounts yet')"
                    :description="__('Connect a DigitalOcean, Hetzner, Linode or Vultr account and dply can list what is already running on it.')"
                />
            @else
                <div class="flex flex-wrap items-end gap-2 px-4 py-3.5 sm:px-5">
                    <div class="min-w-0 flex-1 basis-48">
                        <x-input-label for="scan-provider" :value="__('Provider')" />
                        <select
                            id="scan-provider"
                            wire:model.live="provider"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        >
                            @foreach ($scannableProviders as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0 flex-1 basis-48">
                        <x-input-label for="scan-credential" :value="__('Account')" />
                        <select
                            id="scan-credential"
                            wire:model.live="credentialId"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        >
                            <option value="">{{ __('Choose an account…') }}</option>
                            @foreach ($credentials as $credential)
                                <option value="{{ $credential->id }}">{{ $credential->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button
                        type="button"
                        wire:click="scan"
                        wire:loading.attr="disabled"
                        wire:target="scan"
                        @disabled($credentialId === '')
                        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="scan" class="inline-flex items-center gap-1.5">
                            <x-heroicon-m-magnifying-glass class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Scan') }}
                        </span>
                        <span wire:loading wire:target="scan" class="inline-flex items-center gap-1.5">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Scanning…') }}
                        </span>
                    </button>
                </div>
            @endif

            @if ($scanError !== '')
                <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-t border-brand-ink/10 bg-rose-50/60 px-4 py-2.5 text-xs text-rose-800 sm:px-5">
                    <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ $scanError }}
                </p>
            @endif
        </section>

        @if ($scanned)
            <section class="dply-card overflow-hidden">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-server-stack"
                    :title="__('Servers on this account')"
                    :count="count($found) > 0 ? count($found) : null"
                    :note="trans_choice('{0} Everything here is already in dply.|{1} :count server is not in dply yet.|[2,*] :count servers are not in dply yet.', $importableCount, ['count' => $importableCount])"
                    class="border-b border-brand-ink/10"
                />

                @if ($found === [])
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-server-stack"
                        :title="__('Nothing on this account')"
                        :description="__('The provider returned no machines for this credential.')"
                    />
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($found as $row)
                            @php $isImported = (bool) ($row['imported'] ?? false); @endphp
                            <li
                                wire:key="scan-{{ $row['provider_id'] }}"
                                @class([
                                    'flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 sm:px-5',
                                    'bg-brand-sand/15' => $isImported,
                                ])
                            >
                                <p @class(['shrink-0 text-xs font-semibold', $isImported ? 'text-brand-mist' : 'text-brand-ink'])>{{ $row['name'] ?: __('(unnamed)') }}</p>
                                <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-xs text-brand-mist">
                                    <span>{{ $row['public_ipv4'] ?: '—' }}</span>
                                    @if ($row['private_ipv4'])
                                        <span class="flex items-center gap-1">
                                            <x-heroicon-m-lock-closed class="h-2.5 w-2.5 shrink-0 text-emerald-500" aria-hidden="true" />
                                            {{ $row['private_ipv4'] }}
                                        </span>
                                    @endif
                                    @if ($row['region'])
                                        <span>{{ $row['region'] }}</span>
                                    @endif
                                    @if ($row['size'])
                                        <span>{{ $row['size'] }}</span>
                                    @endif
                                </div>
                                @if ($row['status'])
                                    <span class="shrink-0 text-2xs font-semibold uppercase tracking-wide {{ $statusTone($row['status']) }}">{{ $row['status'] }}</span>
                                @endif
                                @if ($isImported)
                                    {{-- "Already in dply" only says a row exists.
                                         Whether dply can still log in is the
                                         thing worth knowing, so offer the answer
                                         rather than implying it. --}}
                                    @php $verdict = $reachability[$row['provider_id']] ?? null; @endphp
                                    @if ($verdict !== null)
                                        <span @class([
                                            'inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-2xs font-semibold ring-1',
                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $verdict['ok'],
                                            'bg-rose-50 text-rose-700 ring-rose-200' => ! $verdict['ok'],
                                        ])
                                            title="{{ $verdict['message'] }}"
                                        >
                                            @if ($verdict['ok'])
                                                <x-heroicon-m-check-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('dply can connect') }}
                                            @else
                                                <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Cannot connect') }}
                                            @endif
                                        </span>
                                    @else
                                        <span class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full bg-white px-2 text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                            <x-heroicon-m-check class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ __('In dply') }}
                                        </span>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="checkReachability('{{ $row['provider_id'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="checkReachability('{{ $row['provider_id'] }}')"
                                        class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="checkReachability('{{ $row['provider_id'] }}')" class="inline-flex items-center gap-1">
                                            <x-heroicon-m-signal class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ $verdict === null ? __('Check') : __('Recheck') }}
                                        </span>
                                        <span wire:loading wire:target="checkReachability('{{ $row['provider_id'] }}')" class="inline-flex items-center gap-1">
                                            <x-spinner variant="forest" size="sm" />
                                            {{ __('Checking…') }}
                                        </span>
                                    </button>
                                    @if ($row['server_id'])
                                        <a href="{{ route('servers.overview', ['server' => $row['server_id']]) }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-forest hover:underline">
                                            {{ __('Open') }}
                                        </a>
                                    @endif
                                @else
                                    <button
                                        type="button"
                                        wire:click="openAdopt('{{ $row['provider_id'] }}')"
                                        class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                                    >
                                        <x-heroicon-m-arrow-down-tray class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        {{ __('Import') }}
                                    </button>
                                @endif

                                {{-- The reason lives on its own line: "connection
                                     refused on port 22" is the useful part and
                                     doesn't fit in a pill. --}}
                                @if ($isImported && ($reachability[$row['provider_id']] ?? null) !== null && ! $reachability[$row['provider_id']]['ok'])
                                    <p class="flex w-full items-start gap-1.5 text-xs text-rose-800">
                                        <x-heroicon-m-arrow-turn-down-right class="mt-px h-3 w-3 shrink-0 text-rose-400" aria-hidden="true" />
                                        <span class="min-w-0">{{ $reachability[$row['provider_id']]['message'] }}</span>
                                        {{-- A red verdict needs somewhere to go: the same key
                                             form, pointed at the server dply already has. --}}
                                        <button
                                            type="button"
                                            wire:click="openRepair('{{ $row['provider_id'] }}')"
                                            class="ml-auto inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-rose-600 px-2 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700"
                                        >
                                            <x-heroicon-m-wrench-screwdriver class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ __('Fix access') }}
                                        </button>
                                    </p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($importedCount > 0)
                    <p class="border-t border-brand-ink/10 bg-brand-sand/25 px-4 py-2 text-xs text-brand-mist sm:px-5">
                        {{ trans_choice('{1} :count server is already managed by dply.|[2,*] :count servers are already managed by dply.', $importedCount, ['count' => $importedCount]) }}
                    </p>
                @endif
            </section>
        @endif

        @if ($adoptId !== null)
            {{-- Adopt form: everything the provider knows is filled in, so this
                 asks only for the part the API cannot give us — SSH access. --}}
            <section class="dply-card overflow-hidden">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-arrow-down-tray"
                    :icon="$adoptMode === 'repair' ? 'heroicon-o-wrench-screwdriver' : 'heroicon-o-arrow-down-tray'"
                    :tone="$adoptMode === 'repair' ? 'amber' : null"
                    :title="$adoptMode === 'repair'
                        ? __('Fix access to :name', ['name' => $adoptName])
                        : __('Import :name', ['name' => $adoptName])"
                    :note="$adoptMode === 'repair'
                        ? __('Replaces the SSH details dply has for this server. The server and everything on it stay as they are.')
                        : __('dply stores the key and connects over SSH. Nothing is installed or restarted on import.')"
                    class="border-b border-brand-ink/10"
                >
                    <x-slot:actions>
                        <button type="button" wire:click="closeAdopt" class="inline-flex h-6 shrink-0 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            {{ __('Cancel') }}
                        </button>
                    </x-slot:actions>
                </x-workspace-panel-head>

                <form wire:submit.prevent="adopt" class="space-y-3 px-4 py-3.5 sm:px-5">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <x-input-label for="adopt-name" :value="__('Name in dply')" />
                            <x-text-input id="adopt-name" wire:model="adoptName" class="mt-1 block w-full font-mono text-sm" required />
                            <x-input-error :messages="$errors->get('adoptName')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="adopt-ip" :value="__('IP address')" />
                            <x-text-input id="adopt-ip" wire:model="adoptIp" class="mt-1 block w-full font-mono text-sm" required />
                            <x-input-error :messages="$errors->get('adoptIp')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="adopt-user" :value="__('SSH user')" />
                            <x-text-input id="adopt-user" wire:model="adoptSshUser" class="mt-1 block w-full font-mono text-sm" required />
                            <x-input-error :messages="$errors->get('adoptSshUser')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="adopt-port" :value="__('SSH port')" />
                            <x-text-input id="adopt-port" wire:model="adoptSshPort" class="mt-1 block w-full font-mono text-sm" required />
                            <x-input-error :messages="$errors->get('adoptSshPort')" class="mt-1" />
                        </div>
                    </div>

                    {{-- Provider APIs only inject keys when a machine is created,
                         so nothing can push one to a running host. Reusing a key
                         dply already holds is the way around that: sibling
                         machines on one account almost always authorize the same
                         key. Tile picker rather than bare radios, matching the
                         mode picker on step 1 — this is the one real decision on
                         the form, so it should read like one. --}}
                    @php
                        $keyOptions = collect([
                            $reusableKeyServers->isNotEmpty() ? [
                                'value' => 'existing',
                                'icon' => 'heroicon-o-key',
                                'title' => __('Reuse a stored key'),
                                'body' => __('A key dply already holds for another server here.'),
                            ] : null,
                            [
                                'value' => 'paste',
                                'icon' => 'heroicon-o-clipboard-document',
                                'title' => __('Paste a key'),
                                'body' => __('You supply the private key dply should log in with.'),
                            ],
                            [
                                'value' => 'generate',
                                'icon' => 'heroicon-o-sparkles',
                                'title' => __('Generate a key'),
                                'body' => __('dply mints one; you install the public half on the host.'),
                            ],
                        ])->filter()->values();
                    @endphp

                    <fieldset>
                        <legend class="text-xs font-semibold text-brand-ink">{{ __('SSH key') }}</legend>
                        <div @class(['mt-1.5 grid gap-2', 'sm:grid-cols-3' => $keyOptions->count() === 3, 'sm:grid-cols-2' => $keyOptions->count() === 2])>
                            @foreach ($keyOptions as $option)
                                @php $isOn = $adoptKeySource === $option['value']; @endphp
                                <label @class([
                                    'group relative flex cursor-pointer gap-2 rounded-xl border p-3 transition-all',
                                    'border-brand-sage bg-brand-sage/5 ring-1 ring-brand-sage/30' => $isOn,
                                    'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:shadow-sm' => ! $isOn,
                                ])>
                                    <input
                                        type="radio"
                                        value="{{ $option['value'] }}"
                                        wire:model.live="adoptKeySource"
                                        class="peer sr-only"
                                    />
                                    <span @class([
                                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ring-1 transition-colors',
                                        'bg-brand-sage text-white ring-brand-sage/30' => $isOn,
                                        'bg-brand-sand/55 text-brand-forest ring-brand-ink/10' => ! $isOn,
                                    ])>
                                        <x-dynamic-component :component="$option['icon']" class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-xs font-semibold text-brand-ink">{{ $option['title'] }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ $option['body'] }}</span>
                                    </span>
                                    <x-heroicon-s-check-circle @class([
                                        'h-4 w-4 shrink-0 transition-colors',
                                        'text-brand-sage' => $isOn,
                                        'text-brand-ink/10' => ! $isOn,
                                    ]) aria-hidden="true" />
                                    {{-- Keyboard focus lands on the sr-only input, so mirror
                                         its ring onto the tile the user can actually see. --}}
                                    <span class="pointer-events-none absolute inset-0 rounded-xl ring-2 ring-brand-sage/50 opacity-0 peer-focus-visible:opacity-100" aria-hidden="true"></span>
                                </label>
                            @endforeach
                        </div>

                        {{-- Whatever the chosen tile needs, plus the pre-import
                             check, in one panel hanging off the picker. --}}
                        <div class="mt-2 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3">
                            @if ($adoptKeySource === 'existing')
                                <label for="adopt-key-server" class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Borrow the key from') }}</label>
                                <select
                                    id="adopt-key-server"
                                    wire:model.live="adoptKeyServerId"
                                    class="mt-1 block w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                >
                                    @foreach ($reusableKeyServers as $keyServer)
                                        @php
                                            // Server casts provider to the enum; older rows can
                                            // still hold a bare string, so handle both.
                                            $keyProvider = $keyServer->provider;
                                            $keyProviderLabel = $keyProvider instanceof \App\Enums\ServerProvider
                                                ? $keyProvider->label()
                                                : (\App\Enums\ServerProvider::tryFrom((string) $keyProvider)?->label() ?? ucfirst((string) $keyProvider));
                                        @endphp
                                        <option value="{{ $keyServer->id }}">
                                            {{ $keyServer->name }} — {{ $keyProviderLabel }}{{ $keyServer->ip_address ? ' · '.$keyServer->ip_address : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('adoptKeyServerId')" class="mt-1" />
                            @elseif ($adoptKeySource === 'paste')
                                <label for="adopt-key-paste" class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Private key') }}</label>
                                <textarea
                                    id="adopt-key-paste"
                                    wire:model="adoptSshPrivateKey"
                                    rows="4"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                ></textarea>
                                <x-input-error :messages="$errors->get('adoptSshPrivateKey')" class="mt-1" />
                            @else
                                <p class="flex gap-1.5 text-xs leading-relaxed text-brand-moss">
                                    <x-heroicon-m-information-circle class="mt-px h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                    <span>{{ __('The public half appears after import so you can add it to the host. No provider API can install it for you — keys are only injected when a machine is created.') }}</span>
                                </p>
                            @endif

                            {{-- Verify before the row exists: importing succeeds
                                 either way, so without this an unreachable server
                                 looks fine until the first task against it fails. --}}
                            @if ($adoptKeySource !== 'generate')
                                <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-brand-ink/10 pt-2">
                                    <button
                                        type="button"
                                        wire:click="testConnection"
                                        wire:loading.attr="disabled"
                                        wire:target="testConnection"
                                        class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="testConnection" class="inline-flex items-center gap-1.5">
                                            <x-heroicon-m-signal class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Test connection') }}
                                        </span>
                                        <span wire:loading wire:target="testConnection" class="inline-flex items-center gap-1.5">
                                            <x-spinner variant="forest" size="sm" />
                                            {{ __('Connecting…') }}
                                        </span>
                                    </button>

                                    @if ($probeResult !== null)
                                        <p @class([
                                            'inline-flex min-w-0 items-start gap-1.5 text-xs',
                                            'text-emerald-700' => $probeResult['ok'],
                                            'text-rose-800' => ! $probeResult['ok'],
                                        ])>
                                            @if ($probeResult['ok'])
                                                <x-heroicon-m-check-circle class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            @else
                                                <x-heroicon-m-exclamation-triangle class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            @endif
                                            <span class="min-w-0">{{ $probeResult['message'] }}</span>
                                        </p>
                                    @else
                                        <span class="text-xs text-brand-mist">{{ __('Optional — checks dply can actually log in before the server is created.') }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </fieldset>

                    @if ($adoptError !== '')
                        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800">
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ $adoptError }}
                        </p>
                    @endif

                    <div class="flex justify-end">
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="adopt">
                            {{ $adoptMode === 'repair' ? __('Save access') : __('Import server') }}
                        </x-primary-button>
                    </div>
                </form>
            </section>
        @endif

        {{-- Sits last, where the import happened: this is the follow-up to the
             adopt above it, not a banner over the whole page. --}}
        @if ($generatedPublicKey !== '')
            @php
                // mkdir + chmod, because the naive `echo >> ~/.ssh/authorized_keys`
                // fails outright on a host that has never had a key installed.
                $installCommand = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo "'
                    .$generatedPublicKey
                    .'" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys';
            @endphp
            <section class="dply-card overflow-hidden ring-1 ring-brand-sage/40" x-data="{ copied: false }">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-check-circle"
                    :title="__('Imported — one step left')"
                    :note="__('dply stored the private half of a new keypair. It cannot reach the server until the public half is on the host.')"
                    class="border-b border-brand-ink/10"
                >
                    <x-slot:actions>
                        <button type="button" wire:click="dismissGeneratedKey" class="inline-flex h-6 shrink-0 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            {{ __('Dismiss') }}
                        </button>
                    </x-slot:actions>
                </x-workspace-panel-head>

                <ol class="divide-y divide-brand-ink/10">
                    <li class="px-4 py-3 sm:px-5">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-brand-sage/20 text-2xs font-semibold text-brand-forest">1</span>
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Run this on the server, as a user that can write to :user’s authorized_keys', ['user' => $adoptSshUser ?: 'root']) }}</p>
                        </div>
                        <div class="relative mt-1.5">
                            {{-- pre, not a one-line code chip: the key is ~90
                                 chars and the point is to copy it whole. --}}
                            {{-- Bottom padding, not right: the command is one long
                                 line, so a button parked over its right edge sits
                                 on top of the text as it scrolls under it. --}}
                            <pre class="overflow-x-auto rounded-lg bg-brand-ink px-3 pb-9 pt-2 font-mono text-xs leading-relaxed text-brand-cream">{{ $installCommand }}</pre>
                            <button
                                type="button"
                                class="absolute bottom-1.5 right-1.5 inline-flex h-6 items-center gap-1 rounded-md bg-brand-cream px-2 text-2xs font-semibold text-brand-ink shadow-sm transition hover:bg-white"
                                @click="navigator.clipboard.writeText(@js($installCommand)); copied = true; setTimeout(() => copied = false, 1500)"
                            >
                                <x-heroicon-m-clipboard class="h-3 w-3 shrink-0" aria-hidden="true" />
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                            </button>
                        </div>
                    </li>
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2.5 sm:px-5">
                        <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-brand-sage/20 text-2xs font-semibold text-brand-forest">2</span>
                        <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Open the server — dply will connect on the next task it runs.') }}</p>
                        <a href="{{ $adoptedServerUrl }}" wire:navigate class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                            {{ __('Open server') }}
                            <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                        </a>
                    </li>
                </ol>
            </section>
        @endif

        <footer class="flex flex-col-reverse items-stretch justify-between gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/25 px-4 py-2.5 shadow-sm sm:flex-row sm:items-center">
            <a href="{{ route('servers.create', ['edit' => 1]) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold text-brand-moss transition-colors hover:bg-white hover:text-brand-ink">
                <x-heroicon-m-arrow-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Back to type & name') }}
            </a>
            <a href="{{ route('servers.index') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                {{ __('All servers') }}
            </a>
        </footer>
    </div>

    {{-- x-add-provider-credential-link only dispatches the open event; the modal
         has to be on the page to hear it. Without this the button does nothing.
         Its 'provider-credential-created' event is what refreshCredentials()
         listens for, so a new account lands in the picker without a reload. --}}
    <livewire:credentials.add-provider-credential-modal capability="compute" />
</div>
