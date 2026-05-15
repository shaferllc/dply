<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="2" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" :providerHostKind="$form->provider_host_kind" />
    @include('livewire.servers.create._container-launch-banner')

    <form wire:submit.prevent="next" class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div class="space-y-8 min-w-0">
        <header class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/20 px-6 py-8 shadow-sm sm:px-10 sm:py-10">
            <div class="absolute -right-12 -top-12 h-44 w-44 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>
            <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>
            <div class="relative">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Step :n of :total', ['n' => 2, 'total' => $totalSteps]) }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">
                    @if ($form->mode === 'provider')
                        {{ __('Where it runs') }}
                    @else
                        {{ __('Connect your server') }}
                    @endif
                </h1>
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss sm:text-base">
                    @if ($form->mode === 'provider')
                        {{ __('Pick the cloud provider, account, region, and size for the new VM.') }}
                    @else
                        {{ __('Give dply SSH access to the server you already have. We connect read-only at first to verify before doing anything destructive.') }}
                    @endif
                </p>
            </div>
        </header>

        @if ($form->mode === 'provider')
            {{-- Host kind picker (VM / Docker / Managed Kubernetes). Pre-selected from the
                 Containers launcher's host_target=docker|kubernetes hint; default 'vm'. --}}
            <section class="space-y-4">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Host kind') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <button
                        type="button"
                        wire:click="chooseProviderHostKind('vm')"
                        @class([
                            'group relative flex flex-col rounded-2xl border-2 p-6 text-left shadow-sm transition-all',
                            'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream' => $form->provider_host_kind === 'vm',
                            'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->provider_host_kind !== 'vm',
                        ])
                    >
                        <span @class([
                            'inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-colors',
                            'bg-brand-sage text-white shadow-md shadow-brand-sage/20' => $form->provider_host_kind === 'vm',
                            'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15' => $form->provider_host_kind !== 'vm',
                        ])>
                            <x-heroicon-o-server class="h-6 w-6" />
                        </span>
                        <p class="mt-4 text-base font-semibold text-brand-ink">{{ __('Full stack VM') }}</p>
                        <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">{{ __('Dply installs Nginx, PHP, your database, etc. — the traditional VPS-style setup.') }}</p>
                    </button>
                    <button
                        type="button"
                        wire:click="chooseProviderHostKind('docker')"
                        @class([
                            'group relative flex flex-col rounded-2xl border-2 p-6 text-left shadow-sm transition-all',
                            'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream' => $form->provider_host_kind === 'docker',
                            'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->provider_host_kind !== 'docker',
                        ])
                    >
                        <span @class([
                            'inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-colors',
                            'bg-brand-sage text-white shadow-md shadow-brand-sage/20' => $form->provider_host_kind === 'docker',
                            'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15' => $form->provider_host_kind !== 'docker',
                        ])>
                            <x-heroicon-o-cube-transparent class="h-6 w-6" />
                        </span>
                        <p class="mt-4 text-base font-semibold text-brand-ink">{{ __('Docker host') }}</p>
                        <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">{{ __('Skip the stack install. Dply just provisions the VM with Docker and orchestrates containers.') }}</p>
                    </button>
                    <button
                        type="button"
                        wire:click="chooseProviderHostKind('kubernetes')"
                        @class([
                            'group relative flex flex-col rounded-2xl border-2 p-6 text-left shadow-sm transition-all',
                            'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream' => $form->provider_host_kind === 'kubernetes',
                            'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->provider_host_kind !== 'kubernetes',
                        ])
                    >
                        <span @class([
                            'inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-colors',
                            'bg-brand-sage text-white shadow-md shadow-brand-sage/20' => $form->provider_host_kind === 'kubernetes',
                            'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15' => $form->provider_host_kind !== 'kubernetes',
                        ])>
                            <x-heroicon-o-server-stack class="h-6 w-6" />
                        </span>
                        <p class="mt-4 text-base font-semibold text-brand-ink">{{ __('Managed Kubernetes') }}</p>
                        <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">{{ __('Register an existing DOKS cluster. Dply deploys containers into it; DigitalOcean bills you for the cluster.') }}</p>
                    </button>
                </div>
                <x-input-error :messages="$errors->get('form.provider_host_kind')" class="mt-1" />
            </section>

            {{-- Provider tile picker. Hidden for Kubernetes hosts (DO is auto-selected
                 in this PR; AWS EKS ships in a follow-up). --}}
            <section @class(['space-y-4', 'hidden' => $form->provider_host_kind === 'kubernetes'])>
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Provider') }}</h2>
                    <a href="{{ route('credentials.index') }}" wire:navigate class="text-sm font-medium text-brand-sage transition-colors hover:text-brand-forest">{{ __('Manage credentials') }} →</a>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($providerCards as $card)
                        <button
                            type="button"
                            wire:click="chooseProvider('{{ $card['id'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="chooseProvider"
                            @class([
                                'group rounded-2xl border-2 p-4 text-left shadow-sm transition-all disabled:cursor-wait disabled:opacity-60',
                                'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream' => $form->type === $card['id'],
                                'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->type !== $card['id'],
                            ])
                        >
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-semibold text-brand-ink">{{ $card['label'] }}</p>
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $card['linked'] ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-amber-50 text-amber-800 ring-1 ring-amber-200' }}">
                                    @if ($card['linked'])
                                        <x-heroicon-m-check-circle class="h-3 w-3" />
                                    @else
                                        <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                    @endif
                                    {{ $card['linked'] ? __('Connected') : __('Needs account') }}
                                </span>
                            </div>
                        </button>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('form.type')" class="mt-1" />
            </section>

            {{-- Account / credential picker --}}
            <section class="space-y-4 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Account') }}</h2>

                @if ($catalog['credentials']->isEmpty())
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                        <p class="font-medium">{{ __('No saved credential for this provider') }}</p>
                        <p class="mt-1 text-sm">
                            {{ __('Save an API token under Server providers, then come back here. Your draft will still be waiting.') }}
                            <a href="{{ route('credentials.index') }}" wire:navigate class="underline font-medium">{{ __('Go to Server providers') }}</a>
                        </p>
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
            </section>

            {{-- Region + size pickers, only when there's a credential and the host is VM/Docker.
                 K8s hosts inherit region from the picked cluster (chosen on the next step) and
                 don't have a VM size. --}}
            @if ($form->provider_credential_id !== '' && $form->provider_host_kind !== 'kubernetes')
                <section class="grid gap-6 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:grid-cols-2">
                    @include('livewire.servers.create._provider-region-picker')
                    @include('livewire.servers.create._provider-size-picker')
                </section>
            @endif

            {{-- K8s: account-only card with a hint about what comes next. --}}
            @if ($form->provider_host_kind === 'kubernetes' && $form->provider_credential_id !== '')
                <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Cluster') }}</p>
                    <p class="mt-3 text-sm leading-relaxed text-brand-moss">
                        {{ __('You will pick the cluster from your DigitalOcean account on the next step. Region is inherited from the cluster.') }}
                    </p>
                </section>
            @endif
        @else
            {{-- Custom / BYO --}}
            <section class="space-y-4">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Host kind') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <button
                        type="button"
                        wire:click="chooseHostKind('vm')"
                        @class([
                            'group relative flex flex-col rounded-2xl border-2 p-6 text-left shadow-sm transition-all',
                            'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream' => $form->custom_host_kind === 'vm',
                            'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->custom_host_kind !== 'vm',
                        ])
                    >
                        <span @class([
                            'inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-colors',
                            'bg-brand-sage text-white shadow-md shadow-brand-sage/20' => $form->custom_host_kind === 'vm',
                            'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15' => $form->custom_host_kind !== 'vm',
                        ])>
                            <x-heroicon-o-server class="h-6 w-6" />
                        </span>
                        <p class="mt-4 text-base font-semibold text-brand-ink">{{ __('Standard VM / VPS') }}</p>
                        <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">{{ __('Dply will install Nginx, PHP, your database, etc. — full stack setup.') }}</p>
                    </button>
                    <button
                        type="button"
                        wire:click="chooseHostKind('docker')"
                        @class([
                            'group relative flex flex-col rounded-2xl border-2 p-6 text-left shadow-sm transition-all',
                            'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-brand-cream' => $form->custom_host_kind === 'docker',
                            'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->custom_host_kind !== 'docker',
                        ])
                    >
                        <span @class([
                            'inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-colors',
                            'bg-brand-sage text-white shadow-md shadow-brand-sage/20' => $form->custom_host_kind === 'docker',
                            'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15' => $form->custom_host_kind !== 'docker',
                        ])>
                            <x-heroicon-o-cube-transparent class="h-6 w-6" />
                        </span>
                        <p class="mt-4 text-base font-semibold text-brand-ink">{{ __('Docker host') }}</p>
                        <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">{{ __('Skip stack install. Dply just connects over SSH and orchestrates containers.') }}</p>
                    </button>
                </div>
                <x-input-error :messages="$errors->get('form.custom_host_kind')" class="mt-1" />
            </section>

            <section class="space-y-5 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('SSH connection') }}</h2>
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
                        <x-heroicon-m-lock-closed class="h-3 w-3" />
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
                        class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage hover:text-brand-sage disabled:cursor-wait disabled:opacity-60"
                    >
                        <x-heroicon-o-bolt wire:loading.remove wire:target="testCustomConnection" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="testCustomConnection">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="testCustomConnection" class="inline-flex items-center gap-2">
                            <x-spinner variant="zinc" size="sm" />
                            {{ __('Testing…') }}
                        </span>
                    </button>
                    @if ($customConnectionTestState !== 'idle' && $customConnectionTestMessage !== '')
                        <span @class([
                            'inline-flex items-center gap-1.5 text-sm',
                            'text-emerald-700' => $customConnectionTestState === 'success',
                            'text-amber-800' => $customConnectionTestState === 'warning',
                            'text-red-700' => $customConnectionTestState === 'error',
                        ])>
                            @if ($customConnectionTestState === 'success')
                                <x-heroicon-m-check-circle class="h-4 w-4" />
                            @elseif ($customConnectionTestState === 'warning')
                                <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                            @else
                                <x-heroicon-m-x-circle class="h-4 w-4" />
                            @endif
                            {{ $customConnectionTestMessage }}
                        </span>
                    @endif
                </div>
            </section>
        @endif

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
                    <span wire:loading.remove wire:target="next">{{ __('Continue') }}</span>
                    <span wire:loading wire:target="next" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Saving…') }}
                    </span>
                    <x-heroicon-o-arrow-right wire:loading.remove wire:target="next" class="h-4 w-4" />
                </button>
            </div>
        </footer>
      </div>

      {{-- Sidebar: live recommendations + preflight teaser --}}
      <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
        @if ($form->mode === 'provider')
            @include('livewire.servers.create._sidebar-provider', [
                'preflight' => $preflight,
                'catalog' => $catalog,
                'form' => $form,
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
</div>
