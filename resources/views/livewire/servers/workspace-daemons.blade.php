@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];

    // Site-context daemons (the per-site Daemons page) renders inside the site
    // workspace wrapper — left sidebar + breadcrumb — to match the other site
    // sub-pages. The server-level Daemons workspace keeps the server shell.
    $daemonsContextSite = $contextSiteModel ?? null;
@endphp

@if ($daemonsContextSite)
    @php
        $site = $daemonsContextSite;
        $runtimeMode = $site->runtimeTargetMode();
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
        $section = 'daemons';
        $routingTab = 'domains';
        $laravel_tab = 'commands';
    @endphp

    <div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
        <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" wire:navigate class="transition-colors hover:text-brand-ink">{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('servers.index') }}" wire:navigate class="transition-colors hover:text-brand-ink">{{ __('Servers') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="transition-colors hover:text-brand-ink truncate max-w-[10rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="transition-colors hover:text-brand-ink truncate max-w-[10rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="font-medium text-brand-ink">{{ __('Daemons') }}</li>
            </ol>
        </nav>

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <x-page-header
                    :eyebrow="__('Background')"
                    :title="__('Daemons')"
                    :description="__('Supervisor-managed worker processes for this site (queue workers, websocket servers, long-running binaries).')"
                    doc-route="docs.index"
                    flush
                    compact
                />

                @include('livewire.servers.partials.daemons._workspace-content')
            </main>
        </div>

        @include('livewire.servers.partials.daemons._workspace-modals')
    </div>
@else
    <x-server-workspace-layout
        :server="$server"
        active="daemons"
        :title="__('Daemons')"
        :description="__('Supervisor is installed during server provisioning by default. If it is missing on this machine, install it here, then Dply can write configs under /etc/supervisor/conf.d and run supervisorctl reread/update.')"
        :context-site="null"
    >
        @include('livewire.servers.partials.daemons._workspace-content')

        <x-slot name="modals">
            @include('livewire.servers.partials.daemons._workspace-modals')
        </x-slot>
    </x-server-workspace-layout>
@endif
