<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-medium text-brand-ink">{{ __('Routing') }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Routing')"
                :description="__('Hostname, custom domains, redirects, headers, CORS, and invocation URLs — everything the dply edge proxy does between the public internet and your serverless function.')"
                doc-route="docs.index"
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

            <x-server-workspace-tablist :aria-label="__('Routing sections')" class="!mb-0">
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
