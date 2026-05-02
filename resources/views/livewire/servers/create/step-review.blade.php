<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="4" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" />

    <form wire:submit.prevent="store" class="space-y-8">
        <header>
            <h1 class="text-2xl font-semibold text-brand-ink sm:text-3xl">{{ __('Review and launch') }}</h1>
            <p class="mt-2 text-sm text-brand-moss">{{ __('Step :n of :total — confirm the details below, then create.', ['n' => 4, 'total' => $totalSteps]) }}</p>
        </header>

        {{-- Summary --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">{{ __('Summary') }}</h2>
            <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-brand-moss">{{ __('Type') }}</dt>
                    <dd class="font-medium text-brand-ink">
                        @if ($form->mode === 'provider')
                            {{ __('Provision with :provider', ['provider' => $form->type ?: __('a provider')]) }}
                        @else
                            {{ __('Custom / BYO') }} — {{ $form->custom_host_kind === 'docker' ? __('Docker host') : __('VM') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-brand-moss">{{ __('Name') }}</dt>
                    <dd class="font-medium text-brand-ink">{{ $form->name }}</dd>
                </div>
                @if ($form->mode === 'provider')
                    <div>
                        <dt class="text-brand-moss">{{ __('Region') }}</dt>
                        <dd class="font-medium text-brand-ink">{{ $form->region ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Plan / size') }}</dt>
                        <dd class="font-medium text-brand-ink">{{ $form->size ?: '—' }}</dd>
                    </div>
                @else
                    <div>
                        <dt class="text-brand-moss">{{ __('Host') }}</dt>
                        <dd class="font-medium text-brand-ink">{{ $form->ssh_user }}@{{ $form->ip_address }}:{{ $form->ssh_port ?: 22 }}</dd>
                    </div>
                @endif
                @if ($isVmShaped)
                    <div>
                        <dt class="text-brand-moss">{{ __('Install profile') }}</dt>
                        <dd class="font-medium text-brand-ink">{{ $form->install_profile ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Server role') }}</dt>
                        <dd class="font-medium text-brand-ink">{{ $form->server_role ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Web / PHP / DB / Cache') }}</dt>
                        <dd class="font-medium text-brand-ink">{{ $form->webserver }} · {{ $form->php_version }} · {{ $form->database }} · {{ $form->cache_service }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- Advanced options collapsed --}}
        @if ($form->mode === 'provider' && $form->type === 'digitalocean')
            <details class="group rounded-2xl border border-slate-200 bg-white p-5">
                <summary class="cursor-pointer list-none text-sm font-semibold text-slate-900">
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform group-open:rotate-180" />
                        {{ __('Advanced DigitalOcean options') }}
                    </span>
                </summary>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="inline-flex items-center gap-3 text-sm">
                        <input type="checkbox" wire:model.live="form.do_ipv6" class="rounded border-slate-300">
                        {{ __('IPv6 networking') }}
                    </label>
                    <label class="inline-flex items-center gap-3 text-sm">
                        <input type="checkbox" wire:model.live="form.do_backups" class="rounded border-slate-300">
                        {{ __('Enable automated backups') }}
                    </label>
                    <label class="inline-flex items-center gap-3 text-sm">
                        <input type="checkbox" wire:model.live="form.do_monitoring" class="rounded border-slate-300">
                        {{ __('Enable monitoring agent') }}
                    </label>
                    <div class="sm:col-span-2">
                        <x-input-label for="do_tags" :value="__('Tags (comma-separated)')" />
                        <x-text-input id="do_tags" wire:model.live="form.do_tags" type="text" class="mt-1 block w-full" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="do_user_data" :value="__('Cloud-init user-data (optional)')" />
                        <textarea id="do_user_data" wire:model.live="form.do_user_data" rows="4" class="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm focus:border-sky-500 focus:ring-sky-500"></textarea>
                    </div>
                </div>
            </details>
        @endif

        @if ($isVmShaped)
            <details class="group rounded-2xl border border-slate-200 bg-white p-5">
                <summary class="cursor-pointer list-none text-sm font-semibold text-slate-900">
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform group-open:rotate-180" />
                        {{ __('Optional setup-script recipe') }}
                    </span>
                </summary>
                <div class="mt-4">
                    <x-input-label for="setup_script_key" :value="__('Recipe key')" />
                    <x-text-input id="setup_script_key" wire:model.live="form.setup_script_key" type="text" class="mt-1 block w-full" placeholder="none" />
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank or "none" to skip. Recipe ids are defined in config/setup_scripts.php.') }}</p>
                </div>
            </details>
        @endif

        @include('livewire.servers.create._preflight-panel', ['preflight' => $preflight])

        @if ($errors->has('org'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first('org') }}</div>
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
                    wire:target="store"
                    @disabled(! ($preflight['can_submit'] ?? false))
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="store">{{ __('Create server') }}</span>
                    <span wire:loading wire:target="store" class="inline-flex items-center gap-2">
                        <x-spinner variant="white" size="sm" />
                        {{ __('Creating…') }}
                    </span>
                </button>
            </div>
        </footer>
    </form>

    @include('livewire.servers.create._discard-draft-modal')
</div>
