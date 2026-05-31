@php
    // Site-context schedule (reached from a site's sidebar with ?site=)
    // renders inside the site workspace wrapper to match the other site
    // sub-pages. The server-level Schedule keeps the server shell.
    $scheduleContextSite = $contextSite ?? null;
@endphp

@if ($scheduleContextSite)
    @php
        $site = $scheduleContextSite;
        $runtimeMode = $site->runtimeTargetMode();
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
        $section = 'schedule';
        $routingTab = 'domains';
        $laravel_tab = 'commands';
    @endphp

    <div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
        @include('livewire.sites.partials.workspace-breadcrumb-bar', [
            'server' => $server,
            'site' => $site,
            'currentLabel' => __('Schedule'),
            'currentIcon' => 'calendar-days',
            'contextualDocSlug' => app(\App\Support\Docs\ContextualDocResolver::class)->resolveForSiteSection($site, 'schedule'),
        ])

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <x-page-header
                    :eyebrow="__('Background')"
                    :title="__('Schedule')"
                    :description="__('Framework schedulers for this site (schedule:run tick health, cadence, run-now).')"
                    :show-documentation="false"
                    flush
                    compact
                />

                @include('livewire.servers.partials.schedule._workspace-content')
            </main>
        </div>

        @include('livewire.servers.partials.schedule._workspace-modals')
    </div>
@else
    <x-server-workspace-layout
        :server="$server"
        active="schedule"
        :title="__('Schedule')"
        :description="__('Framework schedulers running on this server. Tracks tick health for each scheduler; nudges you when one stops firing.')"
    >
        @include('livewire.servers.partials.schedule._workspace-content')

        <x-slot name="modals">
            @include('livewire.servers.partials.schedule._workspace-modals')
        </x-slot>
    </x-server-workspace-layout>
@endif
