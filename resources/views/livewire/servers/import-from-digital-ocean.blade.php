@php
    $hasCredentials = $credentials->isNotEmpty();
    $hasDroplets = ! empty($droplets);
    $activeCredential = $credentials->firstWhere('id', $credentialId);
    $stepConnect = ! $hasCredentials;
    $stepScan = $hasCredentials && ! $hasDroplets && $generatedPublicKey === '';
    $stepAdopt = $hasDroplets || $generatedPublicKey !== '';
@endphp

<div class="dply-page-shell space-y-8 py-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'],
        ['label' => __('Import from DigitalOcean'), 'icon' => 'cloud'],
    ]" />

    {{-- Hero --}}
    <section class="relative overflow-hidden rounded-3xl border border-[#0080FF]/20 bg-gradient-to-br from-[#0080FF]/[0.07] via-white to-brand-cream/80 p-6 shadow-sm sm:p-8">
        <div class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-[#0080FF]/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 left-1/3 h-40 w-72 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-2xl space-y-4">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0080FF]/10 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-[#0066CC] ring-1 ring-[#0080FF]/20">
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Recover servers') }}
                    </span>
                    <x-provider-badge provider="digitalocean" :label="__('DigitalOcean')" />
                </div>
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight text-brand-ink sm:text-[2rem]">{{ __('Import existing droplets') }}</h1>
                    <p class="mt-3 text-sm leading-7 text-brand-moss sm:text-base">
                        {{ __('Find droplets still running in your DigitalOcean account and bring them back under dply management — without reprovisioning hardware.') }}
                    </p>
                </div>
            </div>

            <ol class="relative flex shrink-0 flex-wrap gap-2 text-xs font-semibold sm:gap-3">
                @foreach ([
                    ['id' => 'connect', 'label' => __('Connect'), 'active' => $stepConnect, 'done' => $hasCredentials],
                    ['id' => 'scan', 'label' => __('Scan'), 'active' => $stepScan, 'done' => $hasDroplets],
                    ['id' => 'adopt', 'label' => __('Adopt'), 'active' => $stepAdopt, 'done' => $generatedPublicKey !== ''],
                ] as $step)
                    <li @class([
                        'inline-flex items-center gap-2 rounded-full px-3 py-1.5 ring-1 transition',
                        'bg-[#0080FF] text-white ring-[#0080FF]/30 shadow-sm shadow-[#0080FF]/20' => $step['active'],
                        'bg-white/90 text-brand-ink ring-brand-ink/10' => ! $step['active'] && $step['done'],
                        'bg-white/60 text-brand-mist ring-brand-ink/5' => ! $step['active'] && ! $step['done'],
                    ])>
                        @if ($step['done'] && ! $step['active'])
                            <x-heroicon-s-check-circle class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                        @else
                            <span @class([
                                'flex h-5 w-5 items-center justify-center rounded-full text-[10px]',
                                'bg-white/20 text-white' => $step['active'],
                                'bg-brand-sand/60 text-brand-moss' => ! $step['active'],
                            ])>{{ $loop->iteration }}</span>
                        @endif
                        {{ $step['label'] }}
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    @if (session('status'))
        <div class="rounded-2xl border border-brand-forest/20 bg-brand-forest/5 p-4 text-sm text-brand-forest">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)] xl:grid-cols-[minmax(0,19rem)_minmax(0,1fr)]">
        {{-- Sidebar guide --}}
        <aside class="space-y-4 lg:sticky lg:top-24">
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('How recovery works') }}</p>
                <ol class="mt-4 space-y-4 text-sm text-brand-moss">
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-[#0080FF]/10 text-xs font-bold text-[#0066CC]">1</span>
                        <span>{{ __('Link a DigitalOcean API token for this organization.') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-[#0080FF]/10 text-xs font-bold text-[#0066CC]">2</span>
                        <span>{{ __('Scan the account — dply lists every droplet and flags ones already imported.') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-[#0080FF]/10 text-xs font-bold text-[#0066CC]">3</span>
                        <span>{{ __('Adopt a droplet with SSH credentials so it becomes a managed server.') }}</span>
                    </li>
                </ol>
            </div>

            @if ($hasDroplets)
                <dl class="grid grid-cols-3 gap-2 rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                    <div class="text-center">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Found') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ $dropletStats['total'] }}</dd>
                    </div>
                    <div class="text-center">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Ready') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-forest">{{ $dropletStats['available'] }}</dd>
                    </div>
                    <div class="text-center">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('In dply') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-mist">{{ $dropletStats['imported'] }}</dd>
                    </div>
                </dl>
            @endif
        </aside>

        {{-- Main column --}}
        <div class="min-w-0 space-y-6">
            {{-- Step 1: credentials --}}
            <section class="rounded-3xl border border-brand-ink/10 bg-white shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/8 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('DigitalOcean account') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('Pick the credential whose droplets you want to discover.') }}
                        </p>
                    </div>
                    @if ($hasCredentials)
                        <x-add-provider-credential-link provider="digitalocean" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5 text-xs font-semibold text-brand-ink no-underline hover:bg-brand-sand/40">
                            <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Add account') }}
                        </x-add-provider-credential-link>
                    @endif
                </div>

                <div class="p-6">
                    @if (! $hasCredentials)
                        <div class="rounded-2xl border border-dashed border-[#0080FF]/25 bg-gradient-to-br from-[#0080FF]/[0.04] via-white to-brand-cream/50 p-8 text-center">
                            <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-[#0080FF]/10 text-[#0080FF] ring-1 ring-[#0080FF]/20">
                                <x-heroicon-o-key class="h-7 w-7" aria-hidden="true" />
                            </span>
                            <p class="mt-5 text-lg font-semibold text-brand-ink">{{ __('Connect DigitalOcean first') }}</p>
                            <p class="mx-auto mt-2 max-w-md text-sm leading-7 text-brand-moss">
                                {{ __('Save a read/write API token for this organization. We verify it with DigitalOcean before storing it encrypted.') }}
                            </p>
                            <button
                                type="button"
                                x-on:click="$dispatch('open-modal', 'add-provider-credential-modal')"
                                class="mt-6 inline-flex h-11 items-center gap-2 rounded-xl bg-[#0080FF] px-5 text-sm font-semibold text-white shadow-md shadow-[#0080FF]/25 transition hover:bg-[#0066CC]"
                            >
                                <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Connect DigitalOcean') }}
                            </button>
                        </div>
                    @else
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($credentials as $credential)
                                <label
                                    wire:key="do-cred-{{ $credential->id }}"
                                    class="group relative cursor-pointer rounded-2xl border p-4 transition @if ($credentialId === (string) $credential->id) border-[#0080FF]/40 bg-[#0080FF]/[0.04] ring-2 ring-[#0080FF]/20 @else border-brand-ink/10 bg-brand-cream/20 hover:border-[#0080FF]/25 hover:bg-white @endif"
                                >
                                    <input
                                        type="radio"
                                        wire:model.live="credentialId"
                                        value="{{ $credential->id }}"
                                        class="sr-only"
                                    />
                                    <div class="flex items-start gap-3">
                                        <span @class([
                                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1',
                                            'bg-[#0080FF]/15 text-[#0080FF] ring-[#0080FF]/20' => $credentialId === (string) $credential->id,
                                            'bg-white text-brand-moss ring-brand-ink/10 group-hover:text-[#0080FF]' => $credentialId !== (string) $credential->id,
                                        ])>
                                            <x-heroicon-o-cloud class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-semibold text-brand-ink">{{ $credential->name ?: $credential->id }}</span>
                                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('DigitalOcean API token') }}</span>
                                        </span>
                                        @if ($credentialId === (string) $credential->id)
                                            <x-heroicon-s-check-circle class="h-5 w-5 shrink-0 text-[#0080FF]" aria-hidden="true" />
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="scan"
                                wire:loading.attr="disabled"
                                wire:target="scan"
                                @disabled($credentialId === '')
                                class="inline-flex h-11 items-center gap-2 rounded-xl bg-brand-ink px-5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="scan" class="inline-flex items-center gap-2">
                                    <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Scan droplets') }}
                                </span>
                                <span wire:loading wire:target="scan" class="inline-flex items-center gap-2">
                                    <x-spinner variant="cream" size="sm" />
                                    {{ __('Scanning…') }}
                                </span>
                            </button>
                            @if ($activeCredential)
                                <p class="text-xs text-brand-moss">
                                    {{ __('Scanning account') }} <span class="font-semibold text-brand-ink">{{ $activeCredential->name ?: $activeCredential->id }}</span>
                                </p>
                            @endif
                        </div>
                    @endif

                    @if ($scanError !== '')
                        <div class="mt-4 flex items-start gap-2 rounded-xl border border-brand-rust/30 bg-brand-rust/5 p-4 text-sm text-brand-rust">
                            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <p>{{ $scanError }}</p>
                        </div>
                    @endif
                </div>
            </section>

            {{-- Step 2: droplets --}}
            @if ($hasDroplets)
                <section
                    class="rounded-3xl border border-brand-ink/10 bg-white shadow-sm"
                    x-data="{ filter: '' }"
                >
                    <div class="flex flex-col gap-4 border-b border-brand-ink/8 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Droplets in account') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Found :count droplet(s). Already-imported droplets are disabled.', ['count' => $dropletStats['total']]) }}
                            </p>
                        </div>
                        <label class="relative block w-full sm:max-w-xs">
                            <span class="sr-only">{{ __('Filter droplets') }}</span>
                            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                            <input
                                type="search"
                                x-model="filter"
                                placeholder="{{ __('Filter by name…') }}"
                                class="block w-full rounded-xl border border-brand-ink/10 bg-brand-cream/30 py-2.5 pl-9 pr-3 text-sm text-brand-ink placeholder:text-brand-mist focus:border-[#0080FF]/40 focus:outline-none focus:ring-2 focus:ring-[#0080FF]/15"
                            />
                        </label>
                    </div>

                    <ul class="grid gap-4 p-6 sm:grid-cols-2">
                        @foreach ($droplets as $d)
                            @php
                                $alreadyImported = (bool) ($d['_already_imported'] ?? false);
                                $status = (string) ($d['status'] ?? 'unknown');
                                $statusTone = match ($status) {
                                    'active' => 'bg-emerald-500',
                                    'off' => 'bg-brand-mist',
                                    default => 'bg-amber-400',
                                };
                            @endphp
                            <li
                                wire:key="do-droplet-{{ $d['id'] ?? $loop->index }}"
                                data-name="{{ Str::lower($d['name'] ?? '') }}"
                                x-show="filter === '' || $el.dataset.name.includes(filter.toLowerCase())"
                                @class([
                                    'group relative flex flex-col rounded-2xl border p-4 transition',
                                    'border-brand-ink/8 bg-brand-cream/20 opacity-70' => $alreadyImported,
                                    'border-brand-ink/10 bg-white hover:border-[#0080FF]/25 hover:shadow-md hover:shadow-[#0080FF]/5' => ! $alreadyImported,
                                ])
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span @class(['h-2 w-2 shrink-0 rounded-full', $statusTone]) aria-hidden="true"></span>
                                            <p class="truncate font-semibold text-brand-ink">{{ $d['name'] ?? '—' }}</p>
                                        </div>
                                        <p class="mt-2 font-mono text-xs text-brand-moss">{{ $d['_public_ipv4'] ?? '—' }}</p>
                                    </div>
                                    @if ($alreadyImported)
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-brand-ink/5 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-mist ring-1 ring-brand-ink/10">
                                            <x-heroicon-s-check class="h-3 w-3" aria-hidden="true" />
                                            {{ __('In dply') }}
                                        </span>
                                    @endif
                                </div>

                                <dl class="mt-4 grid grid-cols-3 gap-2 text-[11px]">
                                    <div class="rounded-lg bg-brand-sand/30 px-2 py-1.5">
                                        <dt class="font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                                        <dd class="mt-0.5 font-mono text-brand-ink">{{ $d['region']['slug'] ?? '—' }}</dd>
                                    </div>
                                    <div class="rounded-lg bg-brand-sand/30 px-2 py-1.5">
                                        <dt class="font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</dt>
                                        <dd class="mt-0.5 truncate font-mono text-brand-ink">{{ $d['size_slug'] ?? '—' }}</dd>
                                    </div>
                                    <div class="rounded-lg bg-brand-sand/30 px-2 py-1.5">
                                        <dt class="font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                                        <dd class="mt-0.5 font-mono capitalize text-brand-ink">{{ $status }}</dd>
                                    </div>
                                </dl>

                                @unless ($alreadyImported)
                                    <button
                                        type="button"
                                        wire:click="openAdoptModal({{ (int) ($d['id'] ?? 0) }})"
                                        class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-brand-forest px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-ink"
                                    >
                                        {{ __('Adopt into dply') }}
                                        <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
                                    </button>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                </section>
            @elseif ($hasCredentials && $credentialId !== '' && ! $scanning && $scanError === '')
                <div class="rounded-2xl border border-dashed border-brand-ink/12 bg-brand-cream/30 px-6 py-10 text-center">
                    <x-heroicon-o-server-stack class="mx-auto h-10 w-10 text-brand-mist" aria-hidden="true" />
                    <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No scan yet') }}</p>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Run a scan to list droplets from the selected account.') }}</p>
                </div>
            @endif

            {{-- Success: generated SSH key --}}
            @if ($generatedPublicKey !== '')
                <section class="overflow-hidden rounded-3xl border-2 border-brand-forest/25 bg-gradient-to-br from-brand-forest/5 via-white to-brand-cream/40 shadow-sm">
                    <div class="flex items-start gap-4 p-6 sm:p-8">
                        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-forest text-white shadow-lg shadow-brand-forest/20">
                            <x-heroicon-o-check class="h-6 w-6" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-xl font-semibold text-brand-ink">{{ __('Server imported — add the public key') }}</h2>
                            <p class="mt-2 text-sm leading-7 text-brand-moss">
                                {{ __('A fresh ed25519 keypair was generated. The private key is stored encrypted on dply. Paste the public key below into') }}
                                <code class="rounded bg-brand-sand/60 px-1 py-0.5 font-mono text-xs">~/.ssh/authorized_keys</code>
                                {{ __('on the droplet. The DigitalOcean web console works if you cannot SSH in yet.') }}
                            </p>

                            <div class="mt-5 rounded-2xl border border-brand-ink/10 bg-white p-4">
                                <x-input-label :value="__('Public key')" />
                                <textarea
                                    readonly
                                    rows="3"
                                    class="mt-2 block w-full rounded-xl border-brand-ink/15 bg-brand-cream/20 font-mono text-xs"
                                >{{ $generatedPublicKey }}</textarea>
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <button
                                        type="button"
                                        onclick="navigator.clipboard.writeText({{ json_encode($generatedPublicKey) }}); this.textContent = '{{ __('Copied') }}'; setTimeout(() => this.textContent = '{{ __('Copy to clipboard') }}', 1500)"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest"
                                    >
                                        <x-heroicon-o-clipboard-document class="h-4 w-4" aria-hidden="true" />
                                        {{ __('Copy to clipboard') }}
                                    </button>
                                    <p class="text-xs text-brand-mist">{{ __('Select all & copy, or use the button.') }}</p>
                                </div>
                            </div>

                            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-brand-forest/15 pt-5">
                                <button type="button" wire:click="dismissGeneratedKey" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                                    {{ __('Import another droplet') }}
                                </button>
                                <a href="{{ $adoptedServerUrl }}" wire:navigate class="inline-flex h-10 items-center gap-2 rounded-xl bg-brand-forest px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink">
                                    {{ __('Open server') }}
                                    <x-heroicon-o-arrow-right class="h-4 w-4" />
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </div>

    {{-- Adopt modal --}}
    @if ($adoptDropletId !== null)
        @php
            $modalDroplet = collect($droplets)->firstWhere('id', $adoptDropletId);
        @endphp
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-ink/50 p-4 sm:items-center" wire:keydown.escape="closeAdoptModal">
            <div class="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div class="border-b border-brand-ink/10 bg-gradient-to-r from-[#0080FF]/10 via-white to-brand-cream/40 px-6 py-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-[#0066CC]">{{ __('Adopt droplet') }}</p>
                            <h3 class="mt-1 text-xl font-semibold text-brand-ink">{{ $modalDroplet['name'] ?? __('Droplet') }}</h3>
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $modalDroplet['_public_ipv4'] ?? '—' }}</p>
                        </div>
                        <button type="button" wire:click="closeAdoptModal" class="rounded-lg p-1.5 text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink">
                            <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                            <span class="sr-only">{{ __('Close') }}</span>
                        </button>
                    </div>
                </div>

                <div class="p-6">
                    <p class="text-sm text-brand-moss">{{ __('dply needs SSH access to manage this server. Paste the matching private key — it will be stored encrypted.') }}</p>

                    @if ($adoptError !== '')
                        <div class="mt-4 flex items-start gap-2 rounded-xl border border-brand-rust/30 bg-brand-rust/5 p-3 text-xs text-brand-rust">
                            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <p>{{ $adoptError }}</p>
                        </div>
                    @endif

                    <form wire:submit="adopt" class="mt-5 space-y-4">
                        <div>
                            <x-input-label for="adopt_name" :value="__('Server name')" />
                            <x-text-input id="adopt_name" wire:model="adoptName" class="mt-1 block w-full font-mono text-sm" required />
                            <x-input-error :messages="$errors->get('adoptName')" class="mt-1" />
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            <div class="col-span-2">
                                <x-input-label for="adopt_ip" :value="__('Public IPv4')" />
                                <x-text-input id="adopt_ip" wire:model="adoptIp" class="mt-1 block w-full font-mono text-sm" required />
                                <x-input-error :messages="$errors->get('adoptIp')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="adopt_ssh_port" :value="__('SSH port')" />
                                <x-text-input id="adopt_ssh_port" wire:model="adoptSshPort" class="mt-1 block w-full font-mono text-sm" />
                                <x-input-error :messages="$errors->get('adoptSshPort')" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="adopt_ssh_user" :value="__('SSH user')" />
                            <x-text-input id="adopt_ssh_user" wire:model="adoptSshUser" class="mt-1 block w-full font-mono text-sm" required />
                            <x-input-error :messages="$errors->get('adoptSshUser')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label :value="__('SSH key')" />
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    wire:click="$set('adoptKeySource', 'paste')"
                                    @class([
                                        'rounded-xl border px-3 py-2.5 text-xs font-semibold transition',
                                        'border-[#0080FF]/30 bg-[#0080FF]/10 text-[#0066CC]' => $adoptKeySource === 'paste',
                                        'border-brand-ink/15 bg-white text-brand-moss hover:border-[#0080FF]/25' => $adoptKeySource !== 'paste',
                                    ])
                                >{{ __('Paste existing key') }}</button>
                                <button
                                    type="button"
                                    wire:click="$set('adoptKeySource', 'generate')"
                                    @class([
                                        'rounded-xl border px-3 py-2.5 text-xs font-semibold transition',
                                        'border-[#0080FF]/30 bg-[#0080FF]/10 text-[#0066CC]' => $adoptKeySource === 'generate',
                                        'border-brand-ink/15 bg-white text-brand-moss hover:border-[#0080FF]/25' => $adoptKeySource !== 'generate',
                                    ])
                                >{{ __('Generate a new key') }}</button>
                            </div>
                        </div>

                        @if ($adoptKeySource === 'paste')
                            <div>
                                <x-input-label for="adopt_ssh_key" :value="__('SSH private key (PEM)')" />
                                <textarea
                                    id="adopt_ssh_key"
                                    wire:model="adoptSshPrivateKey"
                                    rows="6"
                                    class="mt-1 block w-full rounded-xl border-brand-ink/15 font-mono text-xs"
                                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..."
                                ></textarea>
                                <x-input-error :messages="$errors->get('adoptSshPrivateKey')" class="mt-1" />
                            </div>
                        @else
                            <div class="rounded-xl border border-brand-sand bg-brand-sand/20 p-4 text-xs text-brand-moss">
                                <p class="font-semibold text-brand-ink">{{ __('A fresh ed25519 keypair will be generated when you click Import.') }}</p>
                                <p class="mt-1 leading-6">{{ __('The private key is stored encrypted in dply. The matching public key will be shown on the next screen so you can add it to') }} <code class="font-mono">~/.ssh/authorized_keys</code> {{ __('on the droplet.') }}</p>
                            </div>
                        @endif

                        <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 pt-5">
                            <button type="button" wire:click="closeAdoptModal" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                                {{ __('Cancel') }}
                            </button>
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="adopt"
                                class="inline-flex h-10 items-center gap-2 rounded-xl bg-brand-ink px-5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-60"
                            >
                                <span wire:loading.remove wire:target="adopt">{{ __('Import server') }}</span>
                                <span wire:loading wire:target="adopt">{{ __('Importing…') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <livewire:credentials.add-provider-credential-modal
        default-provider="digitalocean"
        capability="compute"
    />
</div>
