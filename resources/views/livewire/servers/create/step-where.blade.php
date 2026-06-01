@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $isProvider = $form->mode === 'provider';
    $selectedProviderHostKind = $form->provider_host_kind ?? 'vm';
    $providerLabel = '';
    if ($isProvider && filled($form->type) && $form->type !== 'custom') {
        $providerLabel = collect($providerCards)->firstWhere('id', str_replace('_kubernetes', '', $form->type))['label'] ?? '';
    }
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-server-create-stepper :current="2" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" :providerHostKind="$form->provider_host_kind" />

    <form wire:submit.prevent="next" class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
        <div class="space-y-6 min-w-0">
            {{-- Hero --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                @if ($isProvider)
                                    <x-heroicon-o-cloud-arrow-up class="h-6 w-6" aria-hidden="true" />
                                @else
                                    <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                                @endif
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Step :n of :total', ['n' => 2, 'total' => $totalSteps]) }}</p>
                                <h1 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">
                                    {{ $isProvider ? __('Where it runs') : __('Connect your server') }}
                                </h1>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ $isProvider
                                        ? __('Pick the cloud provider, account, region, and size for the new VM.')
                                        : __('Give dply SSH access to the server you already have. We connect read-only at first to verify before doing anything destructive.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-2 lg:col-span-5">
                        <div @class([
                            'rounded-2xl border px-4 py-3 shadow-sm',
                            'border-brand-sage/30 bg-brand-sage/8' => $isProvider ? filled($form->provider_host_kind) : filled($form->custom_host_kind),
                            'border-brand-ink/10 bg-white' => $isProvider ? blank($form->provider_host_kind) : blank($form->custom_host_kind),
                        ])>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host kind') }}</dt>
                            <dd class="mt-1 truncate text-sm font-semibold text-brand-ink">
                                @if ($isProvider)
                                    {{ match ($form->provider_host_kind) { 'vm' => __('VM'), 'docker' => __('Docker host'), 'kubernetes' => __('Kubernetes'), default => __('Not set') } }}
                                @else
                                    {{ match ($form->custom_host_kind) { 'vm' => __('VM / VPS'), 'docker' => __('Docker host'), default => __('Not set') } }}
                                @endif
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ $isProvider ? __('Provider mode') : __('Custom mode') }}</p>
                        </div>
                        <div @class([
                            'rounded-2xl border px-4 py-3 shadow-sm',
                            'border-brand-sage/30 bg-brand-sage/8' => $isProvider ? (filled($form->type) && filled($form->provider_credential_id)) : filled($form->ip_address),
                            'border-brand-ink/10 bg-white' => $isProvider ? (blank($form->type) || blank($form->provider_credential_id)) : blank($form->ip_address),
                        ])>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $isProvider ? __('Account') : __('Endpoint') }}</dt>
                            <dd class="mt-1 truncate text-sm font-semibold text-brand-ink">
                                @if ($isProvider)
                                    {{ filled($form->provider_credential_id) && filled($providerLabel) ? $providerLabel : ($providerLabel ?: __('Not set')) }}
                                @else
                                    <span class="font-mono">{{ filled($form->ip_address) ? $form->ip_address : '—' }}</span>
                                @endif
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">
                                {{ $isProvider ? __('Provider & credential') : __('IP / hostname') }}
                            </p>
                        </div>
                    </dl>
                </div>
            </section>

            @if ($isProvider)
                {{-- Provider host kind --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Host') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Host kind') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Traditional VM, Docker-only host, or register a managed Kubernetes cluster.') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Required') }}</span>
                    </div>
                    <div class="p-6 sm:p-7">
                        <div class="grid gap-3 sm:grid-cols-3">
                            @foreach ([
                                ['kind' => 'vm', 'icon' => 'server', 'label' => __('Traditional VM'), 'desc' => __('A traditional VPS — install whatever software and stack you need.')],
                                ['kind' => 'docker', 'icon' => 'cube-transparent', 'label' => __('Docker host'), 'desc' => __('Skip the stack install. Dply provisions Docker and orchestrates containers.')],
                                ['kind' => 'kubernetes', 'icon' => 'server-stack', 'label' => __('Managed Kubernetes'), 'desc' => __('Register an existing DOKS or EKS cluster — dply deploys into it.')],
                            ] as $opt)
                                @php $selected = $selectedProviderHostKind === $opt['kind']; @endphp
                                <button
                                    type="button"
                                    wire:click="chooseProviderHostKind('{{ $opt['kind'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="chooseProviderHostKind"
                                    @class([
                                        'group relative flex flex-col rounded-2xl border-2 p-4 text-left shadow-sm transition-all disabled:cursor-wait disabled:opacity-60',
                                        'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $selected,
                                        'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/30 hover:shadow-md' => ! $selected,
                                    ])
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <span @class([
                                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 transition-colors',
                                            'bg-brand-sage text-white ring-brand-sage/30' => $selected,
                                            'bg-brand-sage/15 text-brand-forest ring-brand-sage/25 group-hover:bg-brand-sage/20' => ! $selected,
                                        ])>
                                            @switch($opt['icon'])
                                                @case('server')
                                                    <x-heroicon-o-server class="h-5 w-5" aria-hidden="true" />
                                                    @break
                                                @case('cube-transparent')
                                                    <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
                                                    @break
                                                @case('server-stack')
                                                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                                                    @break
                                            @endswitch
                                        </span>
                                        <span @class([
                                            'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                                            'border-brand-sage bg-brand-sage text-white' => $selected,
                                            'border-brand-ink/20 bg-white' => ! $selected,
                                        ])>
                                            @if ($selected)
                                                <x-heroicon-s-check class="h-3 w-3" />
                                            @endif
                                        </span>
                                    </div>
                                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ $opt['label'] }}</p>
                                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $opt['desc'] }}</p>
                                </button>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('form.provider_host_kind')" class="mt-3" />
                    </div>
                </section>

                {{-- Provider tile picker --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-cloud class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Provider') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $form->provider_host_kind === 'kubernetes' ? __('Cluster provider') : __('Cloud provider') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Pick the cloud — connect an account right here if one is missing.') }}</p>
                        </div>
                        <x-add-provider-credential-link
                            class="!inline-flex !items-center !gap-1.5 !rounded-lg !border !border-brand-ink/15 !bg-white !px-3 !py-1.5 !text-xs !font-semibold !text-brand-ink !shadow-sm !transition hover:!bg-brand-sand/40 hover:!underline-offset-0 hover:!no-underline whitespace-nowrap shrink-0"
                        >
                            <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Connect provider') }}
                        </x-add-provider-credential-link>
                    </div>
                    <div class="p-6 sm:p-7">
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($providerCards as $card)
                                @php
                                    $isCardSelected = $form->type === $card['id']
                                        || ($form->provider_host_kind === 'kubernetes' && $form->type === $card['id'].'_kubernetes');
                                @endphp
                                <div
                                    wire:key="provider-card-{{ $card['id'] }}"
                                    @class([
                                        'group flex flex-col rounded-2xl border-2 p-4 text-left shadow-sm transition-all',
                                        'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $isCardSelected,
                                        'border-brand-ink/10 bg-white hover:border-brand-sage/30 hover:shadow-md' => ! $isCardSelected,
                                    ])
                                >
                                    <button
                                        type="button"
                                        wire:click="chooseProvider('{{ $card['id'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="chooseProvider"
                                        class="flex w-full items-center justify-between gap-3 text-left disabled:cursor-wait disabled:opacity-60"
                                    >
                                        <span class="min-w-0 truncate text-sm font-semibold text-brand-ink">{{ $card['label'] }}</span>
                                        <span @class([
                                            'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                            'border-emerald-200 bg-emerald-50 text-emerald-700' => $card['linked'],
                                            'border-amber-200 bg-amber-50 text-amber-800' => ! $card['linked'],
                                        ])>
                                            @if ($card['linked'])
                                                <x-heroicon-m-check-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Connected') }}
                                            @else
                                                <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Needs account') }}
                                            @endif
                                        </span>
                                    </button>
                                    @if ($card['linked'])
                                        <div class="mt-3 flex flex-col gap-2 border-t border-brand-ink/8 pt-3 text-[11px] text-brand-moss">
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" />
                                                    {{ trans_choice(':count server|:count servers', $card['server_count'], ['count' => $card['server_count']]) }}
                                                </span>
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" />
                                                    {{ trans_choice(':count site|:count sites', $card['site_count'], ['count' => $card['site_count']]) }}
                                                </span>
                                            </div>
                                            @if (($card['installed_roles'] ?? []) !== [])
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    @foreach ($card['installed_roles'] as $role)
                                                        <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/50 px-1.5 py-0.5 text-[10px] font-medium text-brand-ink ring-1 ring-brand-ink/8">
                                                            @if ($role['count'] > 1)
                                                                {{ $role['count'] }}× {{ $role['label'] }}
                                                            @else
                                                                {{ $role['label'] }}
                                                            @endif
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if (($card['installed_locations'] ?? []) !== [])
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-brand-moss">
                                                    <span class="inline-flex items-center gap-1 font-medium text-brand-mist">
                                                        <x-heroicon-o-map-pin class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                        {{ __('Installed in') }}
                                                    </span>
                                                    @foreach ($card['installed_locations'] as $location)
                                                        <span class="font-medium text-brand-ink">
                                                            {{ $location['label'] }}
                                                            @if ($location['count'] > 1)
                                                                <span class="text-brand-moss">({{ $location['count'] }})</span>
                                                            @endif
                                                        </span>
                                                        @if (! $loop->last)
                                                            <span class="text-brand-mist">·</span>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                    @unless ($card['linked'])
                                        <x-add-provider-credential-link
                                            :provider="$card['id']"
                                            class="!mt-3 !inline-flex !items-center !gap-1.5 !whitespace-nowrap !rounded-lg !border !border-brand-ink/15 !bg-white !px-2.5 !py-1 !text-[11px] !font-semibold !text-brand-ink !shadow-sm !transition hover:!bg-brand-sand/40 hover:!no-underline"
                                        >
                                            <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ __('Connect') }}
                                        </x-add-provider-credential-link>
                                    @endunless
                                </div>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('form.type')" class="mt-3" />
                    </div>
                </section>

                {{-- Account / credential picker --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Account') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Use which API credential?') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Pick a stored token, or connect a fresh one without leaving this step.') }}</p>
                        </div>
                        @if ($form->type !== '' && $form->type !== 'custom')
                            @php $credentialProvider = str_replace('_kubernetes', '', $form->type); @endphp
                            <x-add-provider-credential-link
                                :provider="$credentialProvider"
                                class="!inline-flex !items-center !gap-1.5 !whitespace-nowrap !rounded-lg !border !border-brand-ink/15 !bg-white !px-3 !py-1.5 !text-xs !font-semibold !text-brand-ink !shadow-sm !transition hover:!bg-brand-sand/40 hover:!no-underline shrink-0"
                            >
                                <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Add new') }}
                            </x-add-provider-credential-link>
                        @endif
                    </div>
                    <div class="p-6 sm:p-7">
                        @if ($catalog['credentials']->isEmpty())
                            <div class="rounded-2xl border border-amber-200 bg-amber-50/70 p-5">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-amber-900">{{ __('No saved credential for this provider') }}</p>
                                        <p class="mt-1 text-xs leading-relaxed text-amber-900/85">
                                            {{ __('Connect an API token here without leaving this step. Your draft will still be waiting.') }}
                                        </p>
                                        @php $credentialProvider = str_replace('_kubernetes', '', $form->type); @endphp
                                        <x-add-provider-credential-link
                                            :provider="$credentialProvider"
                                            class="!mt-3 !inline-flex !items-center !gap-2 !whitespace-nowrap !rounded-xl !bg-brand-ink !px-3 !py-1.5 !text-xs !font-semibold !text-brand-cream !shadow-sm !transition hover:!bg-brand-forest hover:!no-underline"
                                        >
                                            <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Connect account') }}
                                        </x-add-provider-credential-link>
                                    </div>
                                </div>
                            </div>
                        @else
                            @include('livewire.servers.create._rich-select', [
                                'id' => 'provider_credential_id',
                                'label' => __('Use credential'),
                                'field' => 'form.provider_credential_id',
                                'value' => $form->provider_credential_id,
                                'options' => $catalog['credentials']->map(fn ($c) => ['id' => (string) $c->id, 'label' => $c->name])->all(),
                                'errorKey' => 'form.provider_credential_id',
                                'eyebrow' => __('Account'),
                                'placeholder' => __('Select an account'),
                            ])
                        @endif
                    </div>
                </section>

                {{-- Server purpose (sizes recommendations key off server_role). --}}
                @if ($form->provider_credential_id !== '' && $form->provider_host_kind !== 'kubernetes')
                    @include('livewire.servers.create._existing-provider-servers', [
                        'existingProviderServers' => $existingProviderServers,
                        'regionLabels' => $regionLabels,
                    ])

                    @include('livewire.servers.create._server-purpose-picker', [
                        'provisionOptions' => $provisionOptions,
                        'form' => $form,
                    ])
                @endif

                {{-- Region + size (VM / Docker hosts only). --}}
                @if ($form->provider_credential_id !== '' && $form->provider_host_kind !== 'kubernetes')
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Placement') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Region & size') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Pick where the VM lives and how big it should be.') }}</p>
                                @if ($selectedServerRole)
                                    <p class="mt-2 text-xs font-medium text-brand-forest">
                                        {{ __('Sizing recommendations are tuned for :role.', ['role' => $selectedServerRole['label'] ?? $form->server_role]) }}
                                    </p>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-6 p-6 sm:grid-cols-2 sm:items-start sm:p-7">
                            @include('livewire.servers.create._provider-region-picker', [
                                'existingServersByRegion' => $existingServersByRegion ?? [],
                            ])
                            @include('livewire.servers.create._provider-size-picker', [
                                'selectedServerRole' => $selectedServerRole,
                            ])
                        </div>
                    </section>
                @endif

                {{-- K8s hint --}}
                @if ($form->provider_host_kind === 'kubernetes' && $form->provider_credential_id !== '')
                    @php $k8sProviderLabel = $form->type === 'aws_kubernetes' ? __('AWS EKS') : __('DigitalOcean DOKS'); @endphp
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Cluster') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Pick a cluster on the next step') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                    {{ __('You will pick the cluster from your :provider account on the next step. Region is inherited from the cluster.', ['provider' => $k8sProviderLabel]) }}
                                </p>
                            </div>
                        </div>
                    </section>
                @endif
            @else
                {{-- Custom (BYO) host kind --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Host') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Host kind') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Traditional VM/VPS over SSH, or a Docker host for container workloads.') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Required') }}</span>
                    </div>
                    <div class="p-6 sm:p-7">
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                ['kind' => 'vm', 'icon' => 'server', 'label' => __('Traditional VM / VPS'), 'desc' => __('Your server — install whatever software and stack you need.')],
                                ['kind' => 'docker', 'icon' => 'cube-transparent', 'label' => __('Docker host'), 'desc' => __('Skip stack install. Dply just connects over SSH and orchestrates containers.')],
                            ] as $opt)
                                @php $selected = $form->custom_host_kind === $opt['kind']; @endphp
                                <button
                                    type="button"
                                    wire:click="chooseHostKind('{{ $opt['kind'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="chooseHostKind"
                                    @class([
                                        'group relative flex flex-col rounded-2xl border-2 p-5 text-left shadow-sm transition-all disabled:cursor-wait disabled:opacity-60',
                                        'border-brand-sage bg-brand-sage/5 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $selected,
                                        'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/30 hover:shadow-md' => ! $selected,
                                    ])
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <span @class([
                                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 transition-colors',
                                            'bg-brand-sage text-white ring-brand-sage/30' => $selected,
                                            'bg-brand-sage/15 text-brand-forest ring-brand-sage/25 group-hover:bg-brand-sage/20' => ! $selected,
                                        ])>
                                            @if ($opt['icon'] === 'server')
                                                <x-heroicon-o-server class="h-5 w-5" aria-hidden="true" />
                                            @else
                                                <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
                                            @endif
                                        </span>
                                        <span @class([
                                            'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                                            'border-brand-sage bg-brand-sage text-white' => $selected,
                                            'border-brand-ink/20 bg-white' => ! $selected,
                                        ])>
                                            @if ($selected)
                                                <x-heroicon-s-check class="h-3 w-3" />
                                            @endif
                                        </span>
                                    </div>
                                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ $opt['label'] }}</p>
                                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $opt['desc'] }}</p>
                                </button>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('form.custom_host_kind')" class="mt-3" />
                    </div>
                </section>

                {{-- SSH connection --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Connection') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('SSH connection') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('We connect read-only first to verify access. Private key is stored encrypted at rest.') }}</p>
                        </div>
                    </div>
                    <div class="space-y-5 p-6 sm:p-7">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="ip_address" :value="__('IP address or hostname')" />
                                <x-text-input id="ip_address" wire:model.live.debounce.500ms="form.ip_address" type="text" class="mt-1 block w-full font-mono" required autocomplete="off" placeholder="203.0.113.10" />
                                <x-input-error :messages="$errors->get('form.ip_address')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="ssh_port" :value="__('SSH port')" />
                                <x-text-input id="ssh_port" wire:model.live.debounce.500ms="form.ssh_port" type="text" class="mt-1 block w-full font-mono" autocomplete="off" placeholder="22" />
                                <x-input-error :messages="$errors->get('form.ssh_port')" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="ssh_user" :value="__('SSH user')" />
                            <x-text-input id="ssh_user" wire:model.live.debounce.500ms="form.ssh_user" type="text" class="mt-1 block w-full font-mono" required autocomplete="off" placeholder="root" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Usually root, ubuntu, or a sudo-enabled deploy user.') }}</p>
                            <x-input-error :messages="$errors->get('form.ssh_user')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="ssh_private_key" :value="__('Private key (PEM/OpenSSH)')" />
                            <textarea
                                id="ssh_private_key"
                                wire:model.live.debounce.750ms="form.ssh_private_key"
                                rows="8"
                                class="mt-1 block w-full rounded-xl border-brand-ink/15 bg-brand-cream/30 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;…&#10;-----END OPENSSH PRIVATE KEY-----"
                                required
                            ></textarea>
                            <p class="mt-1 inline-flex items-center gap-1 text-xs text-brand-mist">
                                <x-heroicon-m-lock-closed class="h-3 w-3 shrink-0" aria-hidden="true" />
                                {{ __('Stored encrypted at rest. Used only to connect to this server.') }}
                            </p>
                            <x-input-error :messages="$errors->get('form.ssh_private_key')" class="mt-1" />
                        </div>

                        <div class="flex flex-wrap items-center gap-3 border-t border-brand-ink/10 pt-4">
                            <button
                                type="button"
                                wire:click="testCustomConnection"
                                wire:loading.attr="disabled"
                                wire:target="testCustomConnection"
                                class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-o-bolt wire:loading.remove wire:target="testCustomConnection" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span wire:loading.remove wire:target="testCustomConnection">{{ __('Test connection') }}</span>
                                <span wire:loading wire:target="testCustomConnection" class="inline-flex items-center gap-2 whitespace-nowrap">
                                    <x-spinner variant="zinc" size="sm" />
                                    {{ __('Testing…') }}
                                </span>
                            </button>
                            @if ($customConnectionTestState !== 'idle' && $customConnectionTestMessage !== '')
                                <span @class([
                                    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-semibold',
                                    'border-emerald-200 bg-emerald-50 text-emerald-800' => $customConnectionTestState === 'success',
                                    'border-amber-200 bg-amber-50 text-amber-900' => $customConnectionTestState === 'warning',
                                    'border-red-200 bg-red-50 text-red-700' => $customConnectionTestState === 'error',
                                ])>
                                    @if ($customConnectionTestState === 'success')
                                        <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    @elseif ($customConnectionTestState === 'warning')
                                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    @else
                                        <x-heroicon-m-x-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    @endif
                                    {{ $customConnectionTestMessage }}
                                </span>
                            @endif
                        </div>
                    </div>
                </section>
            @endif

            {{-- Footer actions --}}
            <footer class="flex flex-col-reverse items-stretch justify-between gap-3 rounded-2xl border border-brand-ink/10 bg-brand-sand/25 px-5 py-4 shadow-sm sm:flex-row sm:items-center">
                <button
                    type="button"
                    wire:click="openDiscardDraftModal"
                    class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-transparent px-3 py-2 text-sm font-medium text-brand-moss transition-colors hover:bg-white hover:text-red-700"
                >
                    <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Discard draft') }}
                </button>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        wire:click="previous"
                        class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Back') }}
                    </button>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="next"
                        class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="next">{{ __('Continue') }}</span>
                        <x-heroicon-o-arrow-right wire:loading.remove wire:target="next" class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span wire:loading wire:target="next" class="inline-flex items-center gap-2 whitespace-nowrap">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Saving…') }}
                        </span>
                    </button>
                </div>
            </footer>
        </div>

        {{-- Sidebar: live recommendations + preflight teaser --}}
        <aside class="space-y-4 lg:sticky lg:top-24 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:overscroll-contain lg:self-start">
            @if ($form->mode === 'provider')
                @include('livewire.servers.create._sidebar-provider', [
                    'preflight' => $preflight,
                    'catalog' => $catalog,
                    'form' => $form,
                    'selectedServerRole' => $selectedServerRole,
                    'roleSizingTip' => $roleSizingTip,
                ])
            @else
                @include('livewire.servers.create._sidebar-custom', [
                    'form' => $form,
                    'connectionState' => $customConnectionTestState,
                    'connectionMessage' => $customConnectionTestMessage,
                ])
            @endif
        </aside>
    </form>

    @include('livewire.servers.create._discard-draft-modal')

    <livewire:credentials.add-provider-credential-modal capability="compute" />
</div>
