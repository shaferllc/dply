@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-server-create-stepper :current="4" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" :providerHostKind="$form->provider_host_kind" />

    @if (! $canCreateServer && $billingUrl)
        <section class="mt-6 dply-card overflow-hidden border-amber-200">
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Plan limit') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Server limit reached for your plan.') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Upgrade to add more servers to this organization.') }}</p>
                    </div>
                </div>
                <a href="{{ $billingUrl }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest sm:self-auto">
                    {{ __('Upgrade plan') }}
                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
            </div>
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

        // Compact stat tiles for the hero — show whichever facts apply to this mode.
        $heroStats = [];
        $heroStats[] = ['label' => __('Name'), 'value' => $form->name ?: '—', 'mono' => true];
        if ($form->mode === 'provider') {
            if ($isKubernetes) {
                $heroStats[] = ['label' => __('Cluster'), 'value' => $form->do_kubernetes_cluster_name ?: '—', 'mono' => true];
            } else {
                $heroStats[] = ['label' => __('Region'), 'value' => $form->region ?: '—', 'mono' => false];
                $heroStats[] = ['label' => __('Plan'), 'value' => $form->size ?: '—', 'mono' => false];
            }
        } else {
            $heroStats[] = ['label' => __('Host'), 'value' => $form->ip_address ?: '—', 'mono' => true];
        }
        $heroStats = array_slice($heroStats, 0, 3);
    @endphp

    <form wire:submit.prevent="store" class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div class="space-y-6 min-w-0">

        {{-- Hero --}}
        <section class="dply-card overflow-hidden">
            <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                <div class="lg:col-span-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-check-circle class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Step :n of :total', ['n' => 4, 'total' => $totalSteps]) }}</p>
                            <h1 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Review and launch') }}</h1>
                            <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">{{ __('Confirm what dply is about to spin up. The preflight panel on the right surfaces anything blocking before you can create.') }}</p>
                        </div>
                    </div>
                </div>
                @if (! empty($heroStats))
                    <dl @class([
                        'grid gap-2 lg:col-span-5',
                        'grid-cols-1' => count($heroStats) === 1,
                        'grid-cols-2' => count($heroStats) === 2,
                        'grid-cols-2 sm:grid-cols-3' => count($heroStats) === 3,
                    ])>
                        @foreach ($heroStats as $stat)
                            <div class="rounded-2xl border border-brand-ink/10 bg-white px-3 py-2.5 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $stat['label'] }}</dt>
                                <dd class="mt-1 truncate text-sm font-semibold text-brand-ink {{ $stat['mono'] ? 'font-mono tabular-nums' : '' }}">{{ $stat['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </section>

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
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                        <x-heroicon-o-arrow-path-rounded-square class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Migrate from :source', ['source' => $sourceLabel]) }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Sites to migrate from :label', ['label' => $migrationSourceLabel]) }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ trans_choice('{1} 1 site selected|[2,*] :count selected', $checkedCount, ['count' => $checkedCount]) }}
                            · {{ trans_choice('{1} 1 site total|[2,*] :count sites total', $totalCount, ['count' => $totalCount]) }}
                        </p>
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                    <ul class="divide-y divide-amber-200/70 rounded-xl bg-white/60 ring-1 ring-amber-200">
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
                </div>
            </section>
        @endif

        {{-- 1. SUMMARY — chip-strip pattern matching step-what's "Template filled in" panel --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-clipboard-document-check class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Summary') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('What you are creating') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Final shape of the server. Anything missing here came from a step you can still go back to.') }}</p>
                </div>
            </div>
            <div class="p-6 sm:p-7">
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
                                @php $osImageLabel = \App\Support\Servers\ServerImageCatalog::labelFor($form->os_image); @endphp
                                @if ($osImageLabel !== null)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('OS') }}</span>
                                        <span class="font-medium text-brand-ink">{{ $osImageLabel }}</span>
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
                            @if ($form->server_role === 'database' && ($form->database_remote_access || $form->database_initial_name !== ''))
                                @if ($form->database_initial_name !== '')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('DB name') }}</span>
                                        <span class="font-medium font-mono text-brand-ink">{{ $form->database_initial_name }}</span>
                                    </span>
                                @endif
                                @if ($form->database_username !== '')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('DB user') }}</span>
                                        <span class="font-medium font-mono text-brand-ink">{{ $form->database_username }}</span>
                                    </span>
                                @endif
                                @if ($form->database_remote_access && $form->database_allowed_from !== '')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('DB access') }}</span>
                                        <span class="font-medium font-mono text-brand-ink">{{ $form->database_allowed_from }}</span>
                                    </span>
                                @elseif ($form->database_remote_access)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('DB access') }}</span>
                                        <span class="font-medium text-brand-ink">{{ __('Remote (CIDR pending)') }}</span>
                                    </span>
                                @endif
                            @endif
                            @if (in_array($form->server_role, ['redis', 'valkey'], true) && ($form->cache_remote_access || $form->cache_require_password))
                                @if ($form->cache_remote_access && $form->cache_allowed_from !== '')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cache access') }}</span>
                                        <span class="font-medium font-mono text-brand-ink">{{ $form->cache_allowed_from }}</span>
                                    </span>
                                @elseif ($form->cache_remote_access)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cache access') }}</span>
                                        <span class="font-medium text-brand-ink">{{ __('Remote (CIDR pending)') }}</span>
                                    </span>
                                @endif
                                @if ($form->cache_require_password)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cache auth') }}</span>
                                        <span class="font-medium text-brand-ink">{{ __('Password required') }}</span>
                                    </span>
                                @endif
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
            </div>
        </section>

        {{-- 2. ADVANCED OPTIONS — collapsed disclosures matching step-what's override pattern --}}
        @if ($form->mode === 'provider' && $form->type === 'digitalocean')
            <section class="dply-card overflow-hidden">
                <details class="group">
                    <summary class="flex cursor-pointer list-none items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Advanced') }}</p>
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Advanced DigitalOcean options') }}</h3>
                                <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition-transform group-open:rotate-180" />
                            </div>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('IPv6, automated backups, monitoring agent, tags, cloud-init user-data.') }}</p>
                        </div>
                    </summary>
                    <div class="p-6 sm:p-7">
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
            </section>
        @endif

        @if ($isVmShaped)
            <section class="dply-card overflow-hidden">
                <details class="group">
                    <summary class="flex cursor-pointer list-none items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Recipe') }}</p>
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Optional setup-script recipe') }}</h3>
                                <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition-transform group-open:rotate-180" />
                            </div>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Run a recipe defined in config/setup_scripts.php after the base provision.') }}</p>
                        </div>
                    </summary>
                    <div class="p-6 sm:p-7">
                        <x-input-label for="setup_script_key" :value="__('Recipe key')" />
                        <x-text-input id="setup_script_key" wire:model.live="form.setup_script_key" type="text" class="mt-1 block w-full font-mono" placeholder="none" />
                        <p class="mt-2 text-xs text-brand-mist">{{ __('Leave blank or "none" to skip.') }}</p>
                    </div>
                </details>
            </section>
        @endif

        {{-- Preflight + cost preview lives in the main column (not the sidebar)
             because the panel has its own internal 2-column layout — squeezing
             it into a narrow sidebar makes the inner grid overflow. --}}
        @include('livewire.servers.create._preflight-panel', ['preflight' => $preflight])

        @if ($errors->has('org'))
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first('org') }}</div>
        @endif

        {{-- StepReview is a summary screen — most form fields aren't rendered here,
             so any errors attached to "form.*" keys (e.g. a DigitalOcean API call that
             failed on submit) would otherwise be invisible. Surface them in a banner. --}}
        @php
            $formErrors = collect($errors->keys())->filter(fn ($k) => str_starts_with((string) $k, 'form.'));
        @endphp
        @if ($formErrors->isNotEmpty())
            <div data-testid="form-error-banner" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <p class="font-semibold">{{ __('Server could not be created') }}</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach ($formErrors as $key)
                        <li>{{ $errors->first($key) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php
            // Surface why the submit button is disabled so users don't click a
            // grey button thinking it's broken. The preflight already exposes
            // the blocker list — we just pull the headline issue and how many
            // remain so the footer can show "Fix N issue(s) to create".
            $canSubmit = (bool) ($preflight['can_submit'] ?? false);
            $blockingChecksForFooter = collect($preflight['checks'] ?? [])->where('blocking', true)->values();
            $firstBlockerLabel = (string) ($blockingChecksForFooter->first()['label'] ?? '');
            $blockingCountForFooter = $blockingChecksForFooter->count();
        @endphp

        @if (! $canSubmit && $blockingCountForFooter > 0)
            <div data-testid="submit-blocked-explainer" class="flex flex-col gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-2">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                    <div>
                        <p class="font-semibold">
                            {{ trans_choice('{1} :count issue blocking :|[2,*] :count issues blocking', $blockingCountForFooter, ['count' => $blockingCountForFooter]) }}
                        </p>
                        @if ($firstBlockerLabel !== '')
                            <p class="text-xs text-amber-800">{{ $firstBlockerLabel }}{{ $blockingCountForFooter > 1 ? ' '.__('(and :n more — see the checklist above)', ['n' => $blockingCountForFooter - 1]) : '' }}</p>
                        @endif
                    </div>
                </div>
            </div>
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
                    wire:target="store"
                    @disabled(! $canSubmit)
                    title="{{ ! $canSubmit && $firstBlockerLabel !== '' ? $firstBlockerLabel : '' }}"
                    class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:bg-slate-400 disabled:opacity-60"
                >
                    <x-heroicon-o-rocket-launch wire:loading.remove wire:target="store" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span wire:loading.remove wire:target="store">{{ $canSubmit ? __('Create server') : __('Fix issues to create') }}</span>
                    <span wire:loading wire:target="store" class="inline-flex items-center gap-2 whitespace-nowrap">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Creating…') }}
                    </span>
                </button>
            </div>
        </footer>
      </div>

      {{-- Sidebar: cost preview + helper context.
           The preflight checks panel stays in the main column where
           it has room; the cost preview lifts up here so the operator
           sees pricing at a glance while scanning the summary. --}}
      <aside class="space-y-4 lg:sticky lg:top-24 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:overscroll-contain lg:self-start">
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
