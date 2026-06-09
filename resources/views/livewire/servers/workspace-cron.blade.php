@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];

    // Site-context cron (the per-site Cron jobs page) renders inside the site
    // workspace wrapper — left sidebar + breadcrumb — to match the other site
    // sub-pages. The server-level Cron workspace keeps the server shell.
    $cronContextSite = $contextSiteModel ?? null;
@endphp

{{-- Single stable root: a Livewire component must morph against ONE consistent
     root element. This page renders two structurally different layouts (site
     context vs server context); without a shared wrapper the root element
     changes shape and Livewire's morph / wire:navigate cycle leaves an
     orphaned, snapshot-less root ("Snapshot missing on Livewire component").
     `display:contents` keeps the wrapper layout-neutral. --}}
<div class="contents">
@if ($cronContextSite)
    @php
        $site = $cronContextSite;
        $runtimeMode = $site->runtimeTargetMode();
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
        $section = 'cron';
        $routingTab = 'domains';
        $laravel_tab = 'commands';
    @endphp

    <div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
        @include('livewire.sites.partials.workspace-breadcrumb-bar', [
            'server' => $server,
            'site' => $site,
            'currentLabel' => __('Cron jobs'),
            'currentIcon' => 'clock',
            'contextualDocSlug' => app(\App\Support\Docs\ContextualDocResolver::class)->resolveForSiteSection($site, 'cron'),
        ])

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <x-page-header
                    :eyebrow="__('Background')"
                    :title="__('Cron jobs')"
                    :description="__('Schedule commands for this site in the Dply-managed crontab block on the server.')"
                    :show-documentation="false"
                    flush
                    compact
                />

                @include('livewire.servers.partials.cron._workspace-content')
            </main>
        </div>

        @include('livewire.servers.partials.cron._workspace-modals')
    </div>
@else
    <x-server-workspace-layout
        :server="$server"
        active="cron"
        :title="__('Cron jobs')"
        :description="__('Schedule commands in the Dply-managed crontab block for this server.')"
        :context-site="null"
    >
        @include('livewire.servers.partials.cron._workspace-content')

        <x-slot name="modals">
            @include('livewire.servers.partials.cron._workspace-modals')
        </x-slot>
    </x-server-workspace-layout>
@endif
</div>
