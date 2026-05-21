<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
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
                    ['id' => 'hostname',   'label' => __('Hostname & DNS')],
                    ['id' => 'domains',    'label' => __('Custom domains')],
                    ['id' => 'redirects',  'label' => __('Redirects')],
                    ['id' => 'headers',    'label' => __('Headers & CORS')],
                    ['id' => 'invocation', 'label' => __('Invocation URLs')],
                ];
            @endphp

            <nav class="-mb-px flex flex-wrap gap-x-6 gap-y-1 border-b border-brand-ink/10 text-sm" aria-label="{{ __('Routing tabs') }}">
                @foreach ($tabs as $entry)
                    <button
                        type="button"
                        wire:click="$set('tab', '{{ $entry['id'] }}')"
                        @class([
                            'whitespace-nowrap border-b-2 px-1 py-2 font-medium transition-colors',
                            'border-brand-ink text-brand-ink' => $tab === $entry['id'],
                            'border-transparent text-brand-moss hover:border-brand-mist hover:text-brand-ink' => $tab !== $entry['id'],
                        ])
                    >{{ $entry['label'] }}</button>
                @endforeach
            </nav>

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
