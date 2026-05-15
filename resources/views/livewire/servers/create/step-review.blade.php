<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    <x-server-create-stepper :current="4" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" :providerHostKind="$form->provider_host_kind" />
    @include('livewire.servers.create._container-launch-banner')

    <form wire:submit.prevent="store" class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div class="space-y-8 min-w-0">

        <header class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/20 px-6 py-8 shadow-sm sm:px-10 sm:py-10">
            <div class="absolute -right-12 -top-12 h-44 w-44 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>
            <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>
            <div class="relative">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Step :n of :total', ['n' => 4, 'total' => $totalSteps]) }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ __('Review and launch') }}</h1>
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss sm:text-base">{{ __('Confirm what dply is about to spin up. The preflight panel on the right surfaces anything blocking before you can create.') }}</p>
            </div>
        </header>

        @if (($migrationSourcePloiServerId || $migrationSourceForgeServerId) && ! empty($migrationSiteSelection))
            @php
                $isForge = $migrationSourceKind === 'forge';
                $sourceLabel = $isForge ? 'Laravel Forge' : 'Ploi';
                $sourceSites = $isForge
                    ? \App\Models\ForgeSite::query()
                        ->where('forge_server_id', $migrationSourceForgeServerId)
                        ->orderBy('domain')
                        ->get()
                    : \App\Models\PloiSite::query()
                        ->where('ploi_server_id', $migrationSourcePloiServerId)
                        ->orderBy('domain')
                        ->get();
                $totalCount = $sourceSites->count();
                $checkedCount = collect($migrationSiteSelection)->filter(fn ($v) => $v === true)->count();
            @endphp
            <section class="rounded-2xl border border-amber-200 bg-amber-50/70 p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-800">{{ __('Migrate from :source', ['source' => $sourceLabel]) }}</p>
                        <h2 class="mt-1 text-xl font-semibold text-amber-950">{{ __('Sites to migrate from :label', ['label' => $migrationSourceLabel]) }}</h2>
                    </div>
                    <p class="text-sm text-amber-900">
                        {{ trans_choice('{1} 1 site selected|[2,*] :count selected', $checkedCount, ['count' => $checkedCount]) }}
                        · {{ trans_choice('{1} 1 site total|[2,*] :count sites total', $totalCount, ['count' => $totalCount]) }}
                    </p>
                </div>
                <ul class="mt-4 divide-y divide-amber-200/70 rounded-xl bg-white/60 ring-1 ring-amber-200">
                    @foreach ($sourceSites as $site)
                        @php
                            $eligible = $site->isMigrationEligible() && ! $site->removed_from_source;
                            $pillLabel = match (true) {
                                $site->removed_from_source => __('Removed on :source', ['source' => $sourceLabel]),
                                ! $site->isMigrationEligible() => __('Unsupported in v1'),
                                default => __('Eligible'),
                            };
                            $pillClass = match (true) {
                                $site->removed_from_source => 'bg-red-100 text-red-900 ring-red-200',
                                ! $site->isMigrationEligible() => 'bg-amber-200 text-amber-950 ring-amber-300',
                                default => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/30',
                            };
                        @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <label class="flex flex-1 min-w-0 items-start gap-3 {{ $eligible ? '' : 'opacity-60 cursor-not-allowed' }}">
                                <input type="checkbox"
                                    wire:model.live="migrationSiteSelection.{{ $site->id }}"
                                    @disabled(! $eligible)
                                    class="mt-1 rounded border-amber-300 text-amber-700 focus:ring-amber-500" />
                                <span class="min-w-0">
                                    <span class="block truncate font-mono text-sm text-amber-950">{{ $site->domain }}</span>
                                    <span class="block text-xs text-amber-900">
                                        {{ $site->site_type }}@if ($site->php_version) · PHP {{ $site->php_version }} @endif
                                        @if ($site->repository_url) · <span class="font-mono">{{ $site->repository_url }}</span>@endif
                                    </span>
                                </span>
                            </label>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $pillClass }}">
                                {{ $pillLabel }}
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-amber-900">{{ __('Unsupported sites stay on :source. You can migrate eligible sites one at a time from the inventory page later if you change your mind.', ['source' => $sourceLabel]) }}</p>
            </section>
        @endif

        @php
            $modeLabel = $form->mode === 'provider'
                ? __('Provision with :provider', ['provider' => $form->type ?: __('a provider')])
                : __('Custom / BYO').' — '.($form->custom_host_kind === 'docker' ? __('Docker host') : __('VM'));
            $languageRuntimes = array_filter([
                'Ruby' => $form->ruby_version,
                'Node' => $form->node_version,
                'Python' => $form->python_version,
                'Go' => $form->go_version,
            ], fn ($v) => $v !== '');
        @endphp

        {{-- 1. SUMMARY — chip-strip pattern matching step-what's "Template filled in" panel --}}
        <section class="rounded-2xl border-2 border-brand-sage/20 bg-white p-6 shadow-sm space-y-5 sm:p-7">
            <div class="flex items-start gap-4">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest">
                    <x-heroicon-o-clipboard-document-check class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('What you are creating') }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Final shape of the server. Anything missing here came from a step you can still go back to.') }}</p>
                </div>
            </div>

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/40 p-5">
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Type') }}</span>
                        <span class="font-medium text-brand-ink">{{ $modeLabel }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</span>
                        <span class="font-mono font-medium text-brand-ink">{{ $form->name ?: '—' }}</span>
                    </span>

                    @if ($form->mode === 'provider')
                        @if ($isKubernetes)
                            @if ($form->do_kubernetes_cluster_name !== '')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cluster') }}</span>
                                    <span class="font-mono font-medium text-brand-ink">{{ $form->do_kubernetes_cluster_name }}</span>
                                </span>
                            @endif
                            @if ($form->do_kubernetes_namespace !== '')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Namespace') }}</span>
                                    <span class="font-mono font-medium text-brand-ink">{{ $form->do_kubernetes_namespace }}</span>
                                </span>
                            @endif
                        @else
                            @if ($form->region !== '')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</span>
                                    <span class="font-medium text-brand-ink">{{ $form->region }}</span>
                                </span>
                            @endif
                            @if ($form->size !== '')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Plan') }}</span>
                                    <span class="font-medium text-brand-ink">{{ $form->size }}</span>
                                </span>
                            @endif
                        @endif
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host') }}</span>
                            <span class="font-mono font-medium text-brand-ink">{{ $form->ssh_user }}@{{ $form->ip_address }}:{{ $form->ssh_port ?: 22 }}</span>
                        </span>
                    @endif
                </div>

                @if ($isVmShaped)
                    <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
                        @if ($form->install_profile !== '')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Bundle') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->install_profile }}</span>
                            </span>
                        @endif
                        @if ($form->server_role !== '')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Job') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->server_role }}</span>
                            </span>
                        @endif
                        @if ($form->webserver !== '' && $form->webserver !== 'none')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Web') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->webserver }}</span>
                            </span>
                        @endif
                        @if ($form->php_version !== '' && $form->php_version !== 'none')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('PHP') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->php_version }}</span>
                            </span>
                        @endif
                        @if ($form->database !== '' && $form->database !== 'none')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('DB') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->database }}</span>
                            </span>
                        @endif
                        @if ($form->cache_service !== '' && $form->cache_service !== 'none')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cache') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->cache_service }}</span>
                            </span>
                        @endif
                        @foreach ($languageRuntimes as $name => $version)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $name }}</span>
                                <span class="font-medium text-brand-ink">{{ $version }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- 2. ADVANCED OPTIONS — collapsed disclosures matching step-what's override pattern --}}
        @if ($form->mode === 'provider' && $form->type === 'digitalocean')
            <details class="group rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                <summary class="flex cursor-pointer list-none items-start gap-4 px-6 py-5 sm:px-7">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest transition-colors group-hover:bg-brand-sage/15">
                        <x-heroicon-o-adjustments-horizontal class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="text-base font-semibold text-brand-ink">{{ __('Advanced DigitalOcean options') }}</span>
                            <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform group-open:rotate-180" />
                        </span>
                        <span class="mt-1 block text-sm text-brand-moss">{{ __('IPv6, automated backups, monitoring agent, tags, cloud-init user-data.') }}</span>
                    </span>
                </summary>
                <div class="border-t border-brand-ink/10 px-6 py-6 sm:px-7">
                    <div class="grid gap-4 sm:grid-cols-2">
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
                </div>
            </details>
        @endif

        @if ($isVmShaped)
            <details class="group rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                <summary class="flex cursor-pointer list-none items-start gap-4 px-6 py-5 sm:px-7">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest transition-colors group-hover:bg-brand-sage/15">
                        <x-heroicon-o-document-text class="h-5 w-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="text-base font-semibold text-brand-ink">{{ __('Optional setup-script recipe') }}</span>
                            <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform group-open:rotate-180" />
                        </span>
                        <span class="mt-1 block text-sm text-brand-moss">{{ __('Run a recipe defined in config/setup_scripts.php after the base provision.') }}</span>
                    </span>
                </summary>
                <div class="border-t border-brand-ink/10 px-6 py-6 sm:px-7">
                    <x-input-label for="setup_script_key" :value="__('Recipe key')" />
                    <x-text-input id="setup_script_key" wire:model.live="form.setup_script_key" type="text" class="mt-1 block w-full font-mono" placeholder="none" />
                    <p class="mt-2 text-xs text-brand-mist">{{ __('Leave blank or "none" to skip.') }}</p>
                </div>
            </details>
        @endif

        {{-- Preflight + cost preview lives in the main column (not the sidebar)
             because the panel has its own internal 2-column layout — squeezing
             it into a narrow sidebar makes the inner grid overflow. --}}
        @include('livewire.servers.create._preflight-panel', ['preflight' => $preflight])

        @if ($errors->has('org'))
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first('org') }}</div>
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
      </div>

      {{-- Sidebar: cost preview + helper context, sticky on lg.
           The preflight checks panel stays in the main column where
           it has room; the cost preview lifts up here so the operator
           sees pricing at a glance while scanning the summary. --}}
      <aside class="space-y-4 lg:sticky lg:top-24 lg:self-start">
        @if ($isKubernetes)
            <div data-testid="k8s-billing-disclosure" class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                    <x-heroicon-m-banknotes class="h-3.5 w-3.5" />
                    {{ __('Billing') }}
                </p>
                <p class="mt-3 text-sm leading-relaxed text-brand-moss">
                    {{ __('DigitalOcean bills you directly for the cluster and its node pool — dply does not add anything on top.') }}
                </p>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                    {{ __('Dply manages container deploys into the cluster as part of your existing dply plan.') }}
                </p>
            </div>
        @else
            @include('livewire.servers.create._cost-preview-panel', ['preflight' => $preflight])
        @endif

        <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
            <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                <x-heroicon-m-information-circle class="h-3.5 w-3.5" />
                {{ __('After you click create') }}
            </p>
            <ol class="mt-3 space-y-2 text-xs leading-5 text-brand-moss">
                <li class="flex gap-2"><span class="font-semibold text-brand-ink">1.</span> {{ __('Server row appears in your fleet immediately.') }}</li>
                <li class="flex gap-2"><span class="font-semibold text-brand-ink">2.</span> {{ __('Provisioning journey opens — watch each step land in real time.') }}</li>
                <li class="flex gap-2"><span class="font-semibold text-brand-ink">3.</span> {{ __('Once "ready", create sites or scaffold a fresh Laravel / WordPress install.') }}</li>
            </ol>
        </div>

        <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/30 p-5">
            <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                <x-heroicon-m-arrow-path class="h-3.5 w-3.5" />
                {{ __('Need to change something?') }}
            </p>
            <p class="mt-2 text-xs leading-5 text-brand-moss">{{ __('Use the stepper above or the Back button. Your draft persists across navigation; nothing is created until you click "Create server".') }}</p>
        </div>
      </aside>
    </form>

    @include('livewire.servers.create._discard-draft-modal')

    {{-- The preflight panel above includes preflight-check-row, which has
         "Add SSH key" buttons that dispatch open-modal => personal-ssh-key-modal.
         The modal listener has to live on the same page, so include it here. --}}
    <livewire:profile.personal-ssh-key-modal source="servers.create" />
</div>
