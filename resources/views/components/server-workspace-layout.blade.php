@props([
    'server',
    'active',
    'title',
    'description' => null,
])

<x-server-workspace-shell :server="$server" :active="$active">
    @include('livewire.servers.partials.workspace-mobile-nav', ['server' => $server, 'active' => $active])

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
        </ol>
    </nav>

    <header class="mb-8 pb-6 border-b border-brand-ink/10">
        <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1 text-sm text-brand-moss">{{ $description }}</p>
        @endif
        @if ($server->workspace)
            <p class="mt-3 text-sm text-brand-moss">
                {{ __('Project:') }}
                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                    {{ $server->workspace->name }}
                </a>
                <span class="text-brand-mist">·</span>
                <a href="{{ route('projects.overview', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                    {{ __('Open project workspace') }}
                </a>
            </p>
        @endif
    </header>

    <div class="space-y-8">
        {{ $slot }}
    </div>

    {{ $modals ?? '' }}
</x-server-workspace-shell>
