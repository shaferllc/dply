{{--
    Full-page Lazy skeleton for site workspace pages that use the merged
    dply-card chrome (Deployments, Repository, …). Renders breadcrumb + sidebar
    + compact panel-head + tab stubs + panel skeleton so wire:navigate doesn't
    flash a taller header than the live page.
--}}
@php
    $title = $title ?? __('Loading…');
    $description = $description ?? '';
    $icon = $icon ?? 'heroicon-o-rectangle-stack';
    $section = $section ?? 'general';
    $tabs = $tabs ?? [];
    $activeTab = $activeTab ?? null;
    $runtimeMode = $site->runtimeTargetMode();
    $runtimeTarget = $site->runtimeTarget();
    $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
    $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
    $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
    $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
    $routingTab = 'domains';
    $laravel_tab = 'commands';
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8" aria-busy="true" aria-live="polite">
    <span class="sr-only">{{ __('Loading :title…', ['title' => $title]) }}</span>

    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => $title,
        'currentIcon' => str_starts_with($icon, 'heroicon-o-') ? \Illuminate\Support\Str::after($icon, 'heroicon-o-') : $icon,
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <section class="dply-card min-w-0 overflow-hidden p-0">
                {{-- Same compact panel-head the live page uses (Logs / Repository),
                     so the Lazy placeholder doesn't flash a taller icon-tile header. --}}
                <x-workspace-panel-head
                    class="border-b border-brand-ink/10"
                    :icon="$icon"
                    :title="$title"
                    :note="$description !== '' ? $description : null"
                />

                @if ($tabs !== [])
                    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
                        <x-server-workspace-tablist :aria-label="$title" scroll bare class="!mb-0 w-full">
                            @foreach ($tabs as $tab)
                                <x-server-workspace-tab
                                    :active="($activeTab ?? null) === ($tab['id'] ?? null)"
                                    :icon="$tab['icon'] ?? null"
                                    :variant="$tab['variant'] ?? 'default'"
                                >{{ $tab['label'] ?? '' }}</x-server-workspace-tab>
                            @endforeach
                        </x-server-workspace-tablist>
                    </div>
                @endif

                @include('livewire.sites.partials._panel-skeleton')
            </section>
        </div>
    </div>
</div>
