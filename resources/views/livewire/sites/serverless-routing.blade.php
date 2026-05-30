<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Routing'),
        'currentIcon' => 'share',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Routing')"
                :description="__('Hostname, custom domains, redirects, headers, CORS, and invocation URLs — everything the dply edge proxy does between the public internet and your serverless function.')"
                :show-documentation="false"
                flush
                compact
            />

            @php
                $tabs = [
                    ['id' => 'hostname',   'label' => __('Hostname & DNS'), 'icon' => 'heroicon-o-globe-alt'],
                    ['id' => 'domains',    'label' => __('Custom domains'), 'icon' => 'heroicon-o-link'],
                    ['id' => 'redirects',  'label' => __('Redirects'),      'icon' => 'heroicon-o-arrow-uturn-right'],
                    ['id' => 'headers',    'label' => __('Headers & CORS'), 'icon' => 'heroicon-o-shield-check'],
                    ['id' => 'invocation', 'label' => __('Invocation URLs'),'icon' => 'heroicon-o-bolt'],
                ];
            @endphp

            <x-server-workspace-tablist :aria-label="__('Routing sections')">
                @foreach ($tabs as $entry)
                    <x-server-workspace-tab
                        id="routing-tab-{{ $entry['id'] }}"
                        :active="$tab === $entry['id']"
                        :icon="$entry['icon']"
                        wire:click="$set('tab', '{{ $entry['id'] }}')"
                    >{{ $entry['label'] }}</x-server-workspace-tab>
                @endforeach
            </x-server-workspace-tablist>

            <div wire:key="routing-tab-{{ $tab }}">
                @includeWhen($tab === 'hostname',   'livewire.sites.serverless-routing.partials.hostname')
                @includeWhen($tab === 'domains',    'livewire.sites.serverless-routing.partials.custom-domains')
                @includeWhen($tab === 'redirects',  'livewire.sites.serverless-routing.partials.redirects')
                @includeWhen($tab === 'headers',    'livewire.sites.serverless-routing.partials.headers')
                @includeWhen($tab === 'invocation', 'livewire.sites.serverless-routing.partials.invocation')
            </div>
        </main>
    </div>
</div>
