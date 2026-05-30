@php
    use App\Services\Servers\SchedulerHealthEvaluator;

    $card = 'dply-card overflow-hidden';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $input = 'block w-full rounded-lg border border-brand-ink/20 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-2 focus:ring-brand-forest/30';

    /**
     * Health-state → visual chip mapping. Centralised here so the per-card
     * loop and the summary strip stay visually consistent.
     */
    $chipForHealth = static function (?string $health): array {
        return match ($health) {
            SchedulerHealthEvaluator::STATE_HEALTHY => [
                'label' => __('Healthy'),
                'classes' => 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200',
                'dot' => 'bg-emerald-500',
            ],
            SchedulerHealthEvaluator::STATE_WAITING => [
                'label' => __('Waiting for first tick'),
                'classes' => 'bg-sky-50 text-sky-800 ring-1 ring-sky-200',
                'dot' => 'bg-sky-500',
            ],
            SchedulerHealthEvaluator::STATE_AMBER => [
                'label' => __('Behind schedule'),
                'classes' => 'bg-amber-50 text-amber-900 ring-1 ring-amber-200',
                'dot' => 'bg-amber-500',
            ],
            SchedulerHealthEvaluator::STATE_RED => [
                'label' => __('Not ticking'),
                'classes' => 'bg-red-50 text-red-800 ring-1 ring-red-200',
                'dot' => 'bg-red-500',
            ],
            SchedulerHealthEvaluator::STATE_PAUSED => [
                'label' => __('Paused'),
                'classes' => 'bg-brand-sand/50 text-brand-mist ring-1 ring-brand-ink/10',
                'dot' => 'bg-brand-mist',
            ],
            default => [
                'label' => __('Unknown'),
                'classes' => 'bg-brand-sand/50 text-brand-mist ring-1 ring-brand-ink/10',
                'dot' => 'bg-brand-mist',
            ],
        };
    };
@endphp

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
                    :description="__('Framework schedulers for this site. Tracks tick health and nudges you when one stops firing.')"
                    :show-documentation="false"
                    flush
                    compact
                />

                @include('livewire.servers.partials.schedule._workspace-content')
            </main>
        </div>
    </div>
@else
    <x-server-workspace-layout
        :server="$server"
        active="schedule"
        :title="__('Schedule')"
        :description="__('Framework schedulers running on this server. Tracks tick health for each scheduler; nudges you when one stops firing.')"
    >
        @include('livewire.servers.partials.schedule._workspace-content')
    </x-server-workspace-layout>
@endif
