@props([
    'server',
    'active',
    'title',
    'description' => null,
    'showNavigation' => null,
    /** @var \App\Models\Site|null Optional site context (site-scoped cron/daemons routes). */
    'contextSite' => null,
])

<x-server-workspace-shell :server="$server" :active="$active" :show-navigation="$showNavigation">
    @if (($showNavigation ?? ($server->status === \App\Models\Server::STATUS_READY && $server->setup_status === \App\Models\Server::SETUP_STATUS_DONE)) === true)
        @include('livewire.servers.partials.workspace-mobile-nav', ['server' => $server, 'active' => $active])
    @endif

    <nav class="text-sm text-brand-moss mb-6" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            @if ($server->workspace)
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li><a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ $server->workspace->name }}</a></li>
            @endif
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium truncate max-w-[12rem] sm:max-w-none" title="{{ $server->name }}">{{ $server->name }}</li>
            @if ($contextSite)
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li>
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $contextSite]) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem] sm:max-w-none font-normal" title="{{ $contextSite->name }}">{{ $contextSite->name }}</a>
                </li>
            @endif
        </ol>
    </nav>

    <x-page-header :title="$contextSite ? $title.' — '.$contextSite->name : $title" :description="$description" flush>
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
