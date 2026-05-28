@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];

    $daemonsRoute = ($contextSiteModel ?? null) !== null
        ? route('sites.daemons', ['server' => $server, 'site' => $contextSiteModel])
        : route('servers.daemons', $server);
    $daemonsLabel = ($contextSiteModel ?? null) !== null ? __('Open site Daemons') : __('Open Daemons');
    $description = ($contextSiteModel ?? null) !== null
        ? __('Supervisor programs scoped to this site that run queue / background workers. The full daemon CRUD lives on the Daemons page — adding from here pre-fills the directory and system user from the site context.')
        : __('A focused view of the Supervisor programs on this server that run queue / background workers. The full daemon CRUD lives on the Daemons page — this page lists what\'s here and helps you add common worker presets.');

    // The per-site Queue workers page renders inside the site workspace wrapper
    // (left sidebar + breadcrumb) to match the other site sub-pages.
    $queueContextSite = $contextSiteModel ?? null;
@endphp

@if ($queueContextSite)
    @php
        $site = $queueContextSite;
        $runtimeMode = $site->runtimeTargetMode();
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
        $section = 'queue-workers';
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
                <li class="font-medium text-brand-ink">{{ __('Queue workers') }}</li>
            </ol>
        </nav>

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <x-page-header
                    :eyebrow="__('Background')"
                    :title="__('Queue workers')"
                    :description="$description"
                    doc-route="docs.index"
                    flush
                    compact
                />

                @include('livewire.servers.partials.queue-workers._workspace-content')
            </main>
        </div>
    </div>
@else
    <x-server-workspace-layout
        :server="$server"
        active="queue-workers"
        :title="__('Queue workers')"
        :description="$description"
        :context-site="null"
    >
        @include('livewire.servers.partials.queue-workers._workspace-content')
    </x-server-workspace-layout>
@endif
