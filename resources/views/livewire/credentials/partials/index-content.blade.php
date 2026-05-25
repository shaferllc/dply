<x-livewire-validation-errors />

@if ($organization)
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
        ['label' => __('Provider credentials'), 'icon' => 'server'],
    ]" />
@else
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
        ['label' => __('Provider credentials'), 'icon' => 'server'],
    ]" />
@endif

<x-page-header
    :title="__('Providers')"
    :description="__('Store encrypted API keys for the cloud providers your organization uses. Tokens are validated when possible.')"
    toolbar
>
    <x-slot name="actions">
        <x-docs-link doc-route="docs.connect-provider">
            <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            {{ __('Provider setup guide') }}
        </x-docs-link>
    </x-slot>
</x-page-header>

<div class="space-y-6">
    {{-- Mobile: capability filter + jump to provider --}}
    <div class="lg:hidden space-y-3">
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Capability tabs') }}">
            @foreach ([
                ['id' => 'all', 'label' => __('All')],
                ['id' => 'server', 'label' => __('Server')],
                ['id' => 'dns', 'label' => __('DNS')],
                ['id' => 'cdn', 'label' => __('CDN')],
            ] as $tabItem)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $tabItem['id'] }}')"
                    @class([
                        'inline-flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm font-medium transition',
                        'bg-brand-ink text-brand-cream shadow-sm shadow-brand-ink/10' => $tab === $tabItem['id'],
                        'border border-brand-ink/15 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $tab !== $tabItem['id'],
                    ])
                >
                    {{ $tabItem['label'] }}
                </button>
            @endforeach
        </nav>
        <div>
            <x-input-label for="credentials_provider_picker" :value="__('Provider')" />
            <x-select id="credentials_provider_picker" wire:model.live="active_provider">
                @foreach ($providerNav as $group)
                    <optgroup label="{{ $group['label'] }}">
                        @foreach ($group['items'] as $item)
                            <option value="{{ $item['id'] }}">{{ $item['label'] }}@if (! empty($item['comingSoon'])) — {{ __('coming soon') }}@endif</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </x-select>
        </div>
    </div>

    <div class="lg:grid lg:grid-cols-12 lg:gap-6 items-start">
        <aside class="hidden lg:block lg:col-span-4">
            <div class="sticky top-24 dply-card overflow-hidden max-h-[calc(100vh-8rem)] flex flex-col">
                {{-- Capability filter lives in the sidebar header because it filters this list.
                     Dual-capability providers (DigitalOcean, AWS) appear in both Server and DNS. --}}
                <div class="border-b border-brand-ink/10 bg-brand-sand/30 px-3 py-2.5">
                    <div role="tablist" aria-label="{{ __('Capability filter') }}" class="inline-flex w-full gap-1 rounded-lg bg-white/70 p-1 ring-1 ring-brand-ink/10">
                        @foreach ([
                            ['id' => 'all', 'label' => __('All')],
                            ['id' => 'server', 'label' => __('Server')],
                            ['id' => 'dns', 'label' => __('DNS')],
                            ['id' => 'cdn', 'label' => __('CDN')],
                        ] as $tabItem)
                            <button
                                type="button"
                                role="tab"
                                aria-selected="{{ $tab === $tabItem['id'] ? 'true' : 'false' }}"
                                wire:click="$set('tab', '{{ $tabItem['id'] }}')"
                                @class([
                                    'flex-1 rounded-md px-2 py-1 text-xs font-semibold transition',
                                    'bg-brand-ink text-brand-cream shadow-sm' => $tab === $tabItem['id'],
                                    'text-brand-moss hover:bg-brand-sand/60 hover:text-brand-ink' => $tab !== $tabItem['id'],
                                ])
                            >
                                {{ $tabItem['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <nav class="overflow-y-auto px-2 py-2 space-y-1 text-sm" aria-label="{{ __('Cloud providers') }}">
                    @foreach ($providerNav as $group)
                        <div class="{{ ! $loop->first ? 'pt-3' : '' }}">
                            <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-brand-mist">{{ $group['label'] }}</p>
                            <ul class="space-y-0.5">
                                @foreach ($group['items'] as $item)
                                    @php
                                        $count = $this->credentialCountFor($item['id']);
                                        $isComing = ! empty($item['comingSoon']);
                                    @endphp
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="$set('active_provider', '{{ $item['id'] }}')"
                                            title="{{ $item['label'] }}"
                                            @class([
                                                'w-full text-left rounded-lg px-3 py-1.5 transition-colors flex items-center justify-between gap-2',
                                                'bg-brand-sand/60 text-brand-ink font-medium' => $active_provider === $item['id'],
                                                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active_provider !== $item['id'] && ! $isComing,
                                                'text-brand-mist hover:bg-brand-sand/40 hover:text-brand-moss' => $active_provider !== $item['id'] && $isComing,
                                            ])
                                        >
                                            <span class="flex min-w-0 flex-1 items-center gap-2">
                                                <x-credentials-provider-icon :provider="$item['id']" />
                                                <span class="truncate">{{ $item['label'] }}</span>
                                            </span>
                                            @if ($isComing)
                                                <span class="shrink-0 rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist ring-1 ring-brand-ink/10">{{ __('soon') }}</span>
                                            @elseif ($count > 0)
                                                <span class="shrink-0 text-[10px] font-semibold tabular-nums rounded-full bg-brand-sage/25 text-brand-ink px-1.5 py-0.5">{{ $count }}</span>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </nav>
            </div>
        </aside>

        <div class="lg:col-span-8 min-w-0 space-y-6">
            <h2 class="inline-flex items-center gap-2 text-lg font-semibold text-brand-ink">
                <x-credentials-provider-icon :provider="$active_provider" class="h-5 w-5 opacity-95" />
                {{ $activeProviderLabel }}
            </h2>

            @include('livewire.credentials.panel', [
                'credentials' => $credentials,
                'digitalOceanOAuthConfigured' => filled(config('services.digitalocean_oauth.client_id')) && filled(config('services.digitalocean_oauth.client_secret')),
            ])
        </div>
    </div>
</div>
