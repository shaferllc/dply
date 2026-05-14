<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Imports · Ploi') }}</h1>
            <p class="max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Your existing Ploi servers and sites. Click a server to migrate it to a new dply-managed server — we move code, env, databases, crons, and SSL.') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if ($hasCredentials)
                <label class="inline-flex items-center gap-2 text-xs text-brand-moss">
                    <input type="checkbox" wire:model.live="showRemoved" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                    {{ __('Show removed') }}
                </label>
                <x-secondary-button wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">
                    <x-heroicon-o-arrow-path class="mr-1.5 h-4 w-4" wire:loading.class="animate-spin" wire:target="refresh" />
                    <span wire:loading.remove wire:target="refresh">{{ __('Refresh from Ploi') }}</span>
                    <span wire:loading wire:target="refresh">{{ __('Refreshing…') }}</span>
                </x-secondary-button>
            @endif
            <a href="{{ route('credentials.index', ['provider' => 'ploi']) }}" wire:navigate>
                <x-secondary-button type="button">
                    <x-heroicon-o-key class="mr-1.5 h-4 w-4" />
                    {{ __('Manage credentials') }}
                </x-secondary-button>
            </a>
        </div>
    </header>

    @if ($activeMigrationCount > 0)
        <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-sm text-sky-900">
            <span class="font-semibold">{{ trans_choice('{1} 1 migration in progress|[2,*] :count migrations in progress', $activeMigrationCount, ['count' => $activeMigrationCount]) }}.</span>
            {{ __('You can keep using dply while migrations run. Click View migration on a server below to inspect step-by-step progress.') }}
        </div>
    @endif

    @if (! $hasCredentials)
        <section class="dply-card overflow-hidden">
            <div class="space-y-4 p-8 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100">
                    <x-heroicon-o-arrow-down-tray class="h-6 w-6 text-amber-900" />
                </div>
                <div class="space-y-2">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Connect Ploi to see your inventory') }}</h2>
                    <p class="mx-auto max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Generate an API token on your Ploi profile and connect it here. We will then list your existing servers and sites and let you migrate each one onto a dply-managed server.') }}
                    </p>
                </div>
                <a href="{{ route('credentials.index', ['provider' => 'ploi']) }}" wire:navigate
                   class="inline-flex items-center justify-center rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-semibold text-amber-950 transition hover:bg-amber-400">
                    {{ __('Connect Ploi') }}
                </a>
            </div>
        </section>
    @elseif ($servers->isEmpty())
        <section class="dply-card overflow-hidden">
            <div class="space-y-3 p-8 text-center">
                <p class="text-sm text-brand-moss">
                    {{ __('No Ploi servers found yet. We will sync them in the background — click Refresh if the list does not appear within a minute.') }}
                </p>
            </div>
        </section>
    @else
        <div class="space-y-4">
            @foreach ($servers as $server)
                <article class="dply-card overflow-hidden">
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-5 py-4">
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-brand-ink">{{ $server->name }}</h2>
                                @if ($server->removed_from_source)
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-900 ring-1 ring-red-200">
                                        {{ __('Removed from Ploi') }}
                                    </span>
                                @endif
                                @if ($server->status)
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                        {{ $server->status }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-brand-moss">
                                @if ($server->ip_address)
                                    <span class="font-mono">{{ $server->ip_address }}</span>
                                @endif
                                @if ($server->provider_label)
                                    · {{ $server->provider_label }}
                                @endif
                                @if ($server->server_type)
                                    · {{ $server->server_type }}
                                @endif
                                · {{ trans_choice('{0} no sites|{1} 1 site|[2,*] :count sites', $server->sites->count(), ['count' => $server->sites->count()]) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            @php $active = $activeMigrations[$server->source_id] ?? null; @endphp
                            @if ($active)
                                <a href="{{ route('imports.ploi.migration.progress', $active) }}" wire:navigate>
                                    <x-secondary-button type="button">
                                        <x-heroicon-o-arrow-path class="mr-1.5 h-4 w-4" />
                                        {{ __('View migration in progress') }}
                                    </x-secondary-button>
                                </a>
                            @elseif (! $server->removed_from_source)
                                <a href="{{ url('/servers/create?from_ploi_server=' . $server->id) }}" wire:navigate>
                                    <x-primary-button type="button">
                                        {{ __('Migrate this server') }}
                                    </x-primary-button>
                                </a>
                            @endif
                        </div>
                    </div>

                    @if ($server->sites->isNotEmpty())
                        <ul class="divide-y divide-brand-ink/5">
                            @foreach ($server->sites as $site)
                                @php
                                    $eligible = $site->isMigrationEligible() && ! $site->removed_from_source;
                                    $eligibilityLabel = match (true) {
                                        $site->removed_from_source => __('Removed'),
                                        ! $site->isMigrationEligible() => __('Unsupported in v1'),
                                        default => __('Eligible'),
                                    };
                                    $eligibilityClass = match (true) {
                                        $site->removed_from_source => 'bg-red-100 text-red-900 ring-red-200',
                                        ! $site->isMigrationEligible() => 'bg-amber-100 text-amber-900 ring-amber-200',
                                        default => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/30',
                                    };
                                @endphp
                                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                    <div class="min-w-0 space-y-0.5">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="truncate font-mono text-sm text-brand-ink">{{ $site->domain }}</span>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $eligibilityClass }}">
                                                {{ $eligibilityLabel }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-brand-moss">
                                            {{ $site->site_type }}@if ($site->php_version) · PHP {{ $site->php_version }} @endif
                                            @if ($site->repository_url) · <span class="font-mono">{{ $site->repository_url }}</span>@endif
                                            @if ($site->repository_branch) · {{ $site->repository_branch }}@endif
                                        </p>
                                    </div>
                                    @if ($eligible)
                                        <a href="{{ url('/servers/create?from_ploi_site=' . $site->id) }}" wire:navigate class="text-xs font-semibold text-brand-forest underline underline-offset-2 hover:text-brand-ink">
                                            {{ __('Migrate this site') }}
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
