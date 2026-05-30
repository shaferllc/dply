@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];

    $input = 'block w-full rounded-lg border border-brand-ink/20 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-2 focus:ring-brand-forest/30';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60';
    $btnOutline = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50';
    $btnDanger = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-100';

    $statusChip = fn (string $status): string => match ($status) {
        'completed' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'failed' => 'border-rose-200 bg-rose-50 text-rose-700',
        'pending', 'running' => 'border-amber-200 bg-amber-50 text-amber-800',
        default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
    };

    $formatBytes = function (?int $bytes): string {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return number_format($value, $i === 0 ? 0 : 1).' '.$units[$i];
    };

    $activeScheduleCount = $schedules->where('is_active', true)->count();

    // The per-site Backups page renders inside the site workspace wrapper
    // (left sidebar + breadcrumb) to match the other site sub-pages.
    $backupsContextSite = $contextSite ?? null;
@endphp

@if ($backupsContextSite)
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
                <li class="font-medium text-brand-ink">{{ __('Backups') }}</li>
            </ol>
        </nav>

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <x-page-header
                    :eyebrow="__('Background')"
                    :title="__('Backups')"
                    :description="__('Database and site-files backup runs for this site, plus recurring schedules. Backups write to the destination configured in Settings → Backup configurations.')"
                    doc-route="docs.index"
                    flush
                    compact
                />

                @include('livewire.servers.partials.backups._workspace-content')
            </main>
        </div>
    </div>
@else
    <x-server-workspace-layout
        :server="$server"
        active="backups"
        :title="__('Backups')"
        :description="__('Recent database and site-files backup runs for this server, plus recurring schedules. Backups write to the destination configured in your account Settings → Backup configurations.')"
    >
        @include('livewire.servers.partials.backups._workspace-content')
    </x-server-workspace-layout>
@endif

@include('livewire.partials.confirm-action-modal')
