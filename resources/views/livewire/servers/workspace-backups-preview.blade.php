@php
    $backupsContextSite = $contextSite ?? null;
@endphp

@if ($backupsContextSite && ($siteDedicatedContext ?? false))
    @php
        $site = $backupsContextSite;
        $runtimeMode = $site->runtimeTargetMode();
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
        $section = 'backups';
        $routingTab = 'domains';
        $laravel_tab = 'commands';
    @endphp

    <div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
        @include('livewire.sites.partials.workspace-breadcrumb-bar', [
            'server' => $server,
            'site' => $site,
            'currentLabel' => __('Backups'),
            'currentIcon' => 'archive-box',
            'contextualDocSlug' => app(\App\Support\Docs\ContextualDocResolver::class)->resolveForSiteSection($site, 'backups'),
        ])

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <x-page-header
                    :eyebrow="__('Background')"
                    :title="__('Backups')"
                    :description="__('Database and site-files backup runs for this site, plus recurring schedules — preview what is shipping next.')"
                    :show-documentation="false"
                    flush
                    compact
                />

                <x-backups-preview-panel :server="$server" />
            </main>
        </div>
    </div>
@else
    <x-server-workspace-layout
        :server="$server"
        active="backups"
        :title="__('Backups')"
        :description="__('Database and site-files backup runs and schedules — preview what is shipping next.')"
    >
        <x-backups-preview-panel :server="$server" />
    </x-server-workspace-layout>
@endif
