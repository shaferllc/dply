<div>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <x-page-header
            :title="__('Sites')"
            :description="__('Sites belong to a server. Open a server to create one, or jump from the list below.')"
            flush
        >
            <x-slot name="actions">
                <a href="{{ route('servers.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-brand-moss hover:text-brand-ink">Servers <span aria-hidden="true">→</span></a>
            </x-slot>
        </x-page-header>
            @if ($sites->isEmpty())
                <x-empty-state
                    :title="__('No sites yet.')"
                    :description="__('Provision a server, then add a site from the server page.')"
                    :dashed="false"
                >
                    <x-slot name="actions">
                        <a href="{{ route('servers.index') }}" class="text-sm font-semibold text-brand-ink hover:underline">{{ __('Go to servers') }}</a>
                    </x-slot>
                </x-empty-state>
            @else
                <x-section-card padding="none">
                <ul class="divide-y divide-brand-ink/10 overflow-hidden">
                    @foreach ($sites as $site)
                        <li class="p-4 flex flex-wrap justify-between gap-4 items-center transition-colors hover:bg-brand-sand/20">
                            <div>
                                <a href="{{ route('sites.show', [$site->server, $site]) }}" class="font-medium text-brand-ink hover:underline">{{ $site->name }}</a>
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
