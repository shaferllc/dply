{{-- Serverless proxy routing — merged chrome (matches Platform / Workers density). --}}
@php
    $tabs = [
        ['id' => 'domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
        ['id' => 'redirects', 'label' => __('Redirects'), 'icon' => 'heroicon-o-arrow-uturn-right'],
        ['id' => 'headers', 'label' => __('Headers & CORS'), 'icon' => 'heroicon-o-shield-check'],
        ['id' => 'invocation', 'label' => __('Invocation URLs'), 'icon' => 'heroicon-o-bolt'],
    ];
    $tabNotes = [
        'domains' => __('Edge hostname, DNS, and custom domains that CNAME to this function.'),
        'redirects' => __('Path redirects applied by the edge proxy before upstream.'),
        'headers' => __('Static response headers and CORS policy on proxied responses.'),
        'invocation' => __('Public addresses this function answers on.'),
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Routing'),
        'currentIcon' => 'share',
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 lg:col-span-9">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-share"
                    :title="__('Routing')"
                    :note="$tabNotes[$tab] ?? $tabNotes['domains']"
                />

                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                    <x-server-workspace-tablist :aria-label="__('Routing sections')" scroll bare class="!mb-0 w-full">
                        @foreach ($tabs as $entry)
                            <x-server-workspace-tab
                                id="routing-tab-{{ $entry['id'] }}"
                                :active="$tab === $entry['id']"
                                :icon="$entry['icon']"
                                wire:click="setTab('{{ $entry['id'] }}')"
                            >{{ $entry['label'] }}</x-server-workspace-tab>
                        @endforeach
                    </x-server-workspace-tablist>
                </div>

                <div wire:key="routing-tab-{{ $tab }}">
                    <div class="hidden" wire:loading.class.remove="hidden" wire:target="setTab">
                        @include('livewire.sites.partials._panel-skeleton')
                    </div>
                    <div wire:loading.class="hidden" wire:target="setTab">
                        @includeWhen($tab === 'domains', 'livewire.sites.serverless-routing.partials.custom-domains')
                        @includeWhen($tab === 'redirects', 'livewire.sites.serverless-routing.partials.redirects')
                        @includeWhen($tab === 'headers', 'livewire.sites.serverless-routing.partials.headers')
                        @includeWhen($tab === 'invocation', 'livewire.sites.serverless-routing.partials.invocation')
                    </div>
                </div>
            </section>
        </main>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
