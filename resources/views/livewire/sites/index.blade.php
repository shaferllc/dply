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
            <div class="rounded-2xl border border-dashed border-brand-mist/80 bg-brand-sand/10 px-6 py-12 text-center">
                <p class="font-medium text-brand-ink">{{ __('No sites yet.') }}</p>
                <p class="mt-2 text-sm text-brand-moss">{{ __("Provision a server, then add a site from that server's workspace.") }}</p>
                <div class="mt-6">
                    <a
                        href="{{ route('servers.index') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-server class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Go to servers') }}
                    </a>
                </div>
            </div>
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
                                        · {{ __('Project:') }}
                                        <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                            {{ $site->workspace->name }}
                                        </a>
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
