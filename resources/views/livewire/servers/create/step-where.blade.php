<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="2" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" />

    <form wire:submit.prevent="next" class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div class="space-y-8 min-w-0">
        <header>
            <h1 class="text-2xl font-semibold text-brand-ink sm:text-3xl">
                @if ($form->mode === 'provider')
                    {{ __('Where it runs') }}
                @else
                    {{ __('Connect your server') }}
                @endif
            </h1>
            <p class="mt-2 text-sm text-brand-moss">
                @if ($form->mode === 'provider')
                    {{ __('Step 2 of :total — pick the provider, account, region, and size for the new VM.', ['total' => $totalSteps]) }}
                @else
                    {{ __('Step 2 of :total — give Dply SSH access to the server you already have.', ['total' => $totalSteps]) }}
                @endif
            </p>
        </header>

        @if ($form->mode === 'provider')
            {{-- Provider tile picker --}}
            <section class="space-y-3">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('Provider') }}</h2>
                    <a href="{{ route('credentials.index') }}" wire:navigate class="text-sm font-medium text-sky-700 hover:text-sky-900">{{ __('Manage credentials') }}</a>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($providerCards as $card)
                        <button
                            type="button"
                            wire:click="chooseProvider('{{ $card['id'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="chooseProvider"
                            @class([
                                'rounded-2xl border p-4 text-left transition disabled:cursor-wait disabled:opacity-60',
                                'border-sky-500 bg-sky-50 ring-2 ring-sky-200' => $form->type === $card['id'],
                                'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' => $form->type !== $card['id'],
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
                <x-input-error :messages="$errors->get('form.type')" class="mt-1" />
            </section>

            {{-- Account / credential picker --}}
            <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">{{ __('Account') }}</h2>

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

            {{-- Region + size pickers, only when there's a credential --}}
            @if ($form->provider_credential_id !== '')
                <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
                    @include('livewire.servers.create._provider-region-picker')
                    @include('livewire.servers.create._provider-size-picker')
                </section>
            @endif
        @else
            {{-- Custom / BYO --}}
            <section class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('Host kind') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        type="button"
                        wire:click="chooseHostKind('vm')"
                        @class([
                            'rounded-2xl border-2 p-4 text-left transition',
                            'border-sky-500 bg-sky-50' => $form->custom_host_kind === 'vm',
                            'border-slate-200 bg-white hover:border-slate-300' => $form->custom_host_kind !== 'vm',
                        ])
                    >
                        <p class="text-sm font-semibold text-slate-900">{{ __('Standard VM / VPS') }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Dply will install Nginx, PHP, your database, etc. — full stack setup.') }}</p>
                    </button>
                    <button
                        type="button"
                        wire:click="chooseHostKind('docker')"
                        @class([
                            'rounded-2xl border-2 p-4 text-left transition',
                            'border-sky-500 bg-sky-50' => $form->custom_host_kind === 'docker',
                            'border-slate-200 bg-white hover:border-slate-300' => $form->custom_host_kind !== 'docker',
                        ])
                    >
                        <p class="text-sm font-semibold text-slate-900">{{ __('Docker host') }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Skip stack install. Dply just connects over SSH and orchestrates containers.') }}</p>
                    </button>
                </div>
                <x-input-error :messages="$errors->get('form.custom_host_kind')" class="mt-1" />
            </section>

            <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="ip_address" :value="__('IP address or hostname')" />
                        <x-text-input id="ip_address" wire:model.live.debounce.500ms="form.ip_address" type="text" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('form.ip_address')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="ssh_port" :value="__('SSH port')" />
                        <x-text-input id="ssh_port" wire:model.live.debounce.500ms="form.ssh_port" type="text" class="mt-1 block w-full" autocomplete="off" placeholder="22" />
                        <x-input-error :messages="$errors->get('form.ssh_port')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="ssh_user" :value="__('SSH user')" />
                    <x-text-input id="ssh_user" wire:model.live.debounce.500ms="form.ssh_user" type="text" class="mt-1 block w-full" required autocomplete="off" />
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Usually root, ubuntu, or a sudo-enabled deploy user.') }}</p>
                    <x-input-error :messages="$errors->get('form.ssh_user')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="ssh_private_key" :value="__('Private key (PEM/OpenSSH)')" />
                    <textarea
                        id="ssh_private_key"
                        wire:model.live.debounce.750ms="form.ssh_private_key"
                        rows="8"
                        class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm focus:border-sky-500 focus:ring-sky-500"
                        placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;…&#10;-----END OPENSSH PRIVATE KEY-----"
                        required
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Stored encrypted at rest. Used only to connect to this server.') }}</p>
                    <x-input-error :messages="$errors->get('form.ssh_private_key')" class="mt-1" />
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                    <button
                        type="button"
                        wire:click="testCustomConnection"
                        wire:loading.attr="disabled"
                        wire:target="testCustomConnection"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="testCustomConnection">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="testCustomConnection" class="inline-flex items-center gap-2">
                            <x-spinner variant="zinc" size="sm" />
                            {{ __('Testing…') }}
                        </span>
                    </button>
                    @if ($customConnectionTestState !== 'idle' && $customConnectionTestMessage !== '')
                        <span @class([
                            'text-sm',
                            'text-emerald-700' => $customConnectionTestState === 'success',
                            'text-amber-800' => $customConnectionTestState === 'warning',
                            'text-red-700' => $customConnectionTestState === 'error',
                        ])>{{ $customConnectionTestMessage }}</span>
                    @endif
                </div>
            </section>
        @endif

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-5">
            <button
                type="button"
                wire:click="openDiscardDraftModal"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-white px-5 text-sm font-semibold text-rose-700 hover:bg-rose-50"
            >
                <x-heroicon-o-trash class="h-4 w-4" />
                {{ __('Discard draft') }}
            </button>
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    wire:click="previous"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                    <x-heroicon-o-arrow-left class="h-4 w-4" />
                    {{ __('Back') }}
                </button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="next"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-sky-600 px-5 text-sm font-semibold text-white shadow-sm hover:bg-sky-700 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="next">{{ __('Continue') }}</span>
                    <span wire:loading wire:target="next" class="inline-flex items-center gap-2">
                        <x-spinner variant="white" size="sm" />
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
