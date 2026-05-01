@props([
    'server',
    'active',
    'title',
    'description' => null,
    'showNavigation' => null,
    /** @var \App\Models\Site|null Optional site context (site-scoped cron/daemons routes). */
    'contextSite' => null,
    'docRoute' => null,
    'docSlug' => null,
    'docLabel' => null,
    /** Match fleet-style headers (Servers / Sites): icon + title left, docs + actions right on large screens. */
    'pageHeaderToolbar' => false,
    'pageHeaderCompact' => false,
])

<x-server-workspace-shell :server="$server" :active="$active" :show-navigation="$showNavigation">
    @if (($showNavigation ?? ($server->status === \App\Models\Server::STATUS_READY && $server->setup_status === \App\Models\Server::SETUP_STATUS_DONE)) === true)
        @include('livewire.servers.partials.workspace-mobile-nav', ['server' => $server, 'active' => $active])
    @endif

    @php
        $workspaceBreadcrumbs = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'],
        ];
        if ($server->workspace) {
            $workspaceBreadcrumbs[] = [
                'label' => $server->workspace->name,
                'href' => route('projects.resources', $server->workspace),
                'icon' => 'rectangle-group',
            ];
        }
        if ($contextSite) {
            $workspaceBreadcrumbs[] = [
                'label' => $server->name,
                'href' => route('servers.overview', $server),
                'icon' => 'server-stack',
            ];
            $workspaceBreadcrumbs[] = [
                'label' => $contextSite->name,
                'href' => route('sites.show', ['server' => $server, 'site' => $contextSite]),
                'icon' => 'globe-alt',
            ];
        } else {
            $workspaceBreadcrumbs[] = [
                'label' => $server->name,
                'icon' => 'server-stack',
            ];
        }
    @endphp
    <x-breadcrumb-trail :items="$workspaceBreadcrumbs" />

    <x-page-header
        :title="$contextSite ? $title.' — '.$contextSite->name : $title"
        :description="$description"
        :doc-route="$docRoute"
        :doc-slug="$docSlug"
        :doc-label="$docLabel"
        :toolbar="(bool) $pageHeaderToolbar"
        :compact="(bool) $pageHeaderCompact"
        flush
    >
        @isset($headerLeading)
            <x-slot name="leading">
                {{ $headerLeading }}
            </x-slot>
        @endisset
        @if ($server->workspace)
            <x-slot name="actions">
                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                    {{ __('Open project workspace') }}
                </a>
            </x-slot>
        @endif
    </x-page-header>

    <div class="space-y-8">
        {{ $slot }}
    </div>

    {{ $modals ?? '' }}
</x-server-workspace-shell>
