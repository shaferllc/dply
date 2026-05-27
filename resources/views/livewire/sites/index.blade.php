<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <x-dashboard-breadcrumb :current="__('Sites')" current-icon="globe-alt" />

        <x-page-header
            :title="__('Sites')"
            :description="__('Every hostname routes through a server—pick a site below or add one from its server page.')"
            doc-route="docs.index"
            flush
            compact
            toolbar
        >
            <x-slot name="leading">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <x-heroicon-o-globe-alt class="h-7 w-7 text-brand-ink" aria-hidden="true" />
                </span>
            </x-slot>
            <x-slot name="actions">
                <a
                    href="{{ route('servers.index') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-server class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Servers') }}
                    <span aria-hidden="true">→</span>
                </a>
            </x-slot>
        </x-page-header>

        @if ($sites->isEmpty())
            <section class="rounded-[2rem] border-2 border-brand-sage/35 bg-brand-cream shadow-lg shadow-brand-ink/10 ring-1 ring-brand-ink/[0.07]" aria-labelledby="sites-empty-heading">
                <div class="px-6 py-12 text-center sm:px-10 sm:py-14">
                    <div class="mx-auto flex max-w-xl flex-col items-center">
                        <span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-sand/55 text-brand-forest ring-1 ring-brand-ink/10">
                            <x-heroicon-o-globe-alt class="h-9 w-9" aria-hidden="true" />
                        </span>
                        <h2 id="sites-empty-heading" class="mt-6 text-2xl font-semibold tracking-tight text-brand-ink">
                            {{ __('No sites yet') }}
                        </h2>
                        <p class="mt-3 text-base leading-relaxed text-brand-moss">
                            {{ __('Sites belong to servers. Open a server, then add the hostnames that should route through it.') }}
                        </p>
                        <ul class="mt-8 w-full space-y-3 text-left text-sm leading-snug text-brand-moss">
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-server class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Pick a server') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Every site lives on a host. Choose where this one belongs.') }}
                                </span>
                            </li>
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-plus-circle class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Add a site') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Inside the server workspace, open Sites → New site to wire up a hostname.') }}
                                </span>
                            </li>
                            <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <x-heroicon-o-cog-6-tooth class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span>
                                    <span class="font-semibold text-brand-ink">{{ __('Configure runtime') }}</span>
                                    <span class="text-brand-mist"> — </span>
                                    {{ __('Pick PHP, Node, or static. TLS, deploys, and env vars come with it.') }}
                                </span>
                            </li>
                            @if (multi_surface_active())
                                <li class="flex gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                    <x-heroicon-o-rectangle-group class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <span>
                                        <span class="font-semibold text-brand-ink">{{ __('Browse infrastructure') }}</span>
                                        <span class="text-brand-mist"> — </span>
                                        {{ __('See servers, cloud apps, and serverless in one place.') }}
                                    </span>
                                </li>
                            @endif
                        </ul>
                        <div class="mt-10 flex w-full flex-wrap items-center justify-center gap-3">
                            <a
                                href="{{ route('servers.index') }}"
                                wire:navigate
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-3 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition hover:bg-brand-forest"
                            >
                                <x-heroicon-o-server class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Go to servers') }}
                            </a>
                            @can('create', App\Models\Server::class)
                                <a
                                    href="{{ route('servers.create') }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-5 py-3 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Create a server') }}
                                </a>
                            @endcan
                            @if (multi_surface_active())
                                <a
                                    href="{{ route('infrastructure.index') }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-5 py-3 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-rectangle-group class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    {{ __('Browse infrastructure') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        @else
            <x-section-card padding="none">
                <ul class="divide-y divide-brand-ink/10 overflow-hidden">
                    @foreach ($sites as $site)
                        <li class="flex flex-wrap items-center justify-between gap-4 p-4 transition-colors hover:bg-brand-sand/20">
                            <div>
                                <a href="{{ route('sites.show', [$site->server, $site]) }}" class="font-medium text-brand-ink hover:text-brand-sage">{{ $site->name }}</a>
                                <p class="text-sm text-brand-moss">
                                    Server: {{ $site->server->name }}
                                    @php $d = $site->domains->firstWhere('is_primary') ?? $site->domains->first(); @endphp
                                    @if ($d)
                                        · {{ $d->hostname }}
                                    @endif
                                    · {{ $site->type->label() }}
                                    @if ($site->workspace)
                                        @feature('surface.projects')
                                            · {{ __('Project:') }}
                                            <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                                {{ $site->workspace->name }}
                                            </a>
                                        @endfeature
                                    @endif
                                </p>
                                @if ($site->isProvisioning())
                                    <p class="mt-1 text-xs text-brand-moss">
                                        {{ __('Provisioning step: :step', ['step' => str_replace('_', ' ', $site->provisioningState() ?? 'queued')]) }}
                                    </p>
                                @elseif ($site->provisioningState() === 'failed')
                                    <p class="mt-1 text-xs text-red-700">
                                        {{ $site->provisioningError() ?: __('Provisioning failed.') }}
                                    </p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-sm text-brand-moss">
                                <x-badge size="sm">{{ $site->statusLabel() }}</x-badge>
                                @if ($site->isProvisioning())
                                    <x-badge size="sm" tone="accent">{{ __('Provisioning') }}</x-badge>
                                @endif
                                @if ($site->ssl_status !== 'none')
                                    <x-badge size="sm" tone="accent">{{ __('SSL: :status', ['status' => $site->ssl_status]) }}</x-badge>
                                @endif
                                @if ($site->visitUrl())
                                    <a href="{{ $site->visitUrl() }}" target="_blank" rel="noreferrer" class="inline-flex items-center gap-1 text-xs font-medium text-brand-ink hover:underline">
                                        {{ __('Visit') }}
                                    </a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-section-card>
        @endif
    </div>
</div>
