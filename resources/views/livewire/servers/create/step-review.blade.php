<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="4" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" />

    <form wire:submit.prevent="store" class="space-y-8">
        <header class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/20 px-6 py-8 shadow-sm sm:px-10 sm:py-10">
            <div class="absolute -right-12 -top-12 h-44 w-44 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>
            <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>
            <div class="relative">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Step :n of :total', ['n' => 4, 'total' => $totalSteps]) }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ __('Review and launch') }}</h1>
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss sm:text-base">{{ __('Confirm the details below, then create. Anything blocking will surface in the preflight panel.') }}</p>
            </div>
        </header>

        {{-- Summary --}}
        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Summary') }}</h2>
                <span class="text-xs text-brand-mist">{{ __('What will be created') }}</span>
            </div>
            <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                    <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Type') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">
                        @if ($form->mode === 'provider')
                            {{ __('Provision with :provider', ['provider' => $form->type ?: __('a provider')]) }}
                        @else
                            {{ __('Custom / BYO') }} — {{ $form->custom_host_kind === 'docker' ? __('Docker host') : __('VM') }}
                        @endif
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                    <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Name') }}</dt>
                    <dd class="mt-1 font-mono font-medium text-brand-ink">{{ $form->name }}</dd>
                </div>
                @if ($form->mode === 'provider')
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Region') }}</dt>
                        <dd class="mt-1 font-medium text-brand-ink">{{ $form->region ?: '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Plan / size') }}</dt>
                        <dd class="mt-1 font-medium text-brand-ink">{{ $form->size ?: '—' }}</dd>
                    </div>
                @else
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4 sm:col-span-2">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Host') }}</dt>
                        <dd class="mt-1 font-mono font-medium text-brand-ink">{{ $form->ssh_user }}@{{ $form->ip_address }}:{{ $form->ssh_port ?: 22 }}</dd>
                    </div>
                @endif
                @if ($isVmShaped)
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Install profile') }}</dt>
                        <dd class="mt-1 font-medium text-brand-ink">{{ $form->install_profile ?: '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Server role') }}</dt>
                        <dd class="mt-1 font-medium text-brand-ink">{{ $form->server_role ?: '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4 sm:col-span-2">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Web / PHP / DB / Cache') }}</dt>
                        <dd class="mt-1 font-medium text-brand-ink">{{ $form->webserver }} · {{ $form->php_version }} · {{ $form->database }} · {{ $form->cache_service }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- Advanced options collapsed --}}
        @if ($form->mode === 'provider' && $form->type === 'digitalocean')
            <details class="group rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink">
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform group-open:rotate-180" />
                        {{ __('Advanced DigitalOcean options') }}
                    </span>
                </summary>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="inline-flex items-center gap-3 text-sm text-brand-moss">
                        <input type="checkbox" wire:model.live="form.do_ipv6" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                        {{ __('IPv6 networking') }}
                    </label>
                    <label class="inline-flex items-center gap-3 text-sm text-brand-moss">
                        <input type="checkbox" wire:model.live="form.do_backups" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                        {{ __('Enable automated backups') }}
                    </label>
                    <label class="inline-flex items-center gap-3 text-sm text-brand-moss">
                        <input type="checkbox" wire:model.live="form.do_monitoring" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                        {{ __('Enable monitoring agent') }}
                    </label>
                    <div class="sm:col-span-2">
                        <x-input-label for="do_tags" :value="__('Tags (comma-separated)')" />
                        <x-text-input id="do_tags" wire:model.live="form.do_tags" type="text" class="mt-1 block w-full" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="do_user_data" :value="__('Cloud-init user-data (optional)')" />
                        <textarea id="do_user_data" wire:model.live="form.do_user_data" rows="4" class="mt-1 block w-full rounded-xl border-brand-ink/15 bg-brand-cream/30 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage"></textarea>
                    </div>
                </div>
            </details>
        @endif

        @if ($isVmShaped)
            <details class="group rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
                <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink">
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform group-open:rotate-180" />
                        {{ __('Optional setup-script recipe') }}
                    </span>
                </summary>
                <div class="mt-4">
                    <x-input-label for="setup_script_key" :value="__('Recipe key')" />
                    <x-text-input id="setup_script_key" wire:model.live="form.setup_script_key" type="text" class="mt-1 block w-full font-mono" placeholder="none" />
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank or "none" to skip. Recipe ids are defined in config/setup_scripts.php.') }}</p>
                </div>
            </details>
        @endif

        @include('livewire.servers.create._preflight-panel', ['preflight' => $preflight])

        @if ($errors->has('org'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first('org') }}</div>
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
                    wire:target="store"
                    @disabled(! ($preflight['can_submit'] ?? false))
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-gradient-to-br from-emerald-600 to-emerald-700 px-6 text-sm font-semibold text-white shadow-md shadow-emerald-700/20 transition-all hover:from-emerald-500 hover:to-emerald-600 hover:shadow-lg hover:shadow-emerald-700/25 disabled:cursor-not-allowed disabled:from-slate-400 disabled:to-slate-500 disabled:opacity-60 disabled:shadow-none"
                >
                    <x-heroicon-o-rocket-launch wire:loading.remove wire:target="store" class="h-4 w-4" />
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

    {{-- The preflight panel above includes preflight-check-row, which has
         "Add SSH key" buttons that dispatch open-modal => personal-ssh-key-modal.
         The modal listener has to live on the same page, so include it here. --}}
    <livewire:profile.personal-ssh-key-modal source="servers.create" />
</div>
