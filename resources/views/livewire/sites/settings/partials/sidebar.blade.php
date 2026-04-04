@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $navLink = 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors';
@endphp

<aside class="lg:col-span-3 mb-8 lg:mb-0">
    <h2 class="text-xs font-semibold uppercase tracking-wider text-brand-mist mb-3">{{ __('Site') }}</h2>
    <div class="{{ $card }}">
        <div class="border-b border-brand-ink/10 p-4 sm:p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-base font-semibold text-brand-ink">{{ optional($site->primaryDomain())->hostname ?? $site->name }}</p>
                    @if ($server->workspace)
                        <p class="mt-2 text-xs text-brand-moss">
                            {{ __('Project:') }}
                            <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">
                                {{ $server->workspace->name }}
                            </a>
                        </p>
                    @endif
                    <p class="mt-2 text-xs text-brand-moss">
                        @if (($runtimePublication['hostname'] ?? null) || ($runtimePublication['container_ip'] ?? null))
                            {{ $runtimePublication['hostname'] ?? __('Hostname pending') }}
                            @if (! empty($runtimePublication['container_ip']))
                                <span class="font-mono text-brand-ink">{{ $runtimePublication['container_ip'] }}</span>
                            @endif
                        @else
                            {{ $server->ip_address ?? __('No IP recorded') }}
                        @endif
                    </p>
                </div>
                @if ($site->visitUrl())
                    <a
                        href="{{ $site->visitUrl() }}"
                        target="_blank"
                        rel="noreferrer"
                        class="shrink-0 rounded-md p-1.5 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                        title="{{ __('Open site') }}"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                    </a>
                @endif
            </div>
        </div>
        <nav id="site-settings-sidebar" class="flex flex-col gap-0.5 p-2" aria-label="{{ __($resourceNoun.' settings sections') }}">
            @foreach ($settingsSidebarItems as $item)
                <a
                    href="{{ $item['id'] === 'webserver-config'
                        ? route('sites.webserver-config', [$server, $site])
                        : ($item['id'] === 'monitor'
                            ? route('sites.monitor', [$server, $site])
                            : ($item['id'] === 'commits'
                                ? route('sites.commits', [$server, $site])
                                : route('sites.show', array_merge([
                                'server' => $server,
                                'site' => $site,
                                'section' => $item['id'],
                            ], $item['id'] === 'routing' ? ['tab' => $routingTab] : [], $item['id'] === 'laravel-stack' ? ['laravel_tab' => $laravel_tab ?? 'commands'] : [])))) }}"
                    wire:navigate
                    @class([
                        $navLink,
                        'bg-brand-sand/60 text-brand-ink' => $section === $item['id'],
                        'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $section !== $item['id'],
                    ])
                >
                    <x-dynamic-component :component="$item['icon']" class="h-5 w-5 shrink-0 opacity-90" />
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>
        <div class="border-t border-brand-ink/10 p-3">
            <a
                href="{{ route('servers.sites', $server) }}"
                wire:navigate
                class="flex items-center gap-2 text-xs font-medium text-brand-moss hover:text-brand-ink"
            >
                <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                {{ __('Back to :resources', ['resources' => $resourcePlural]) }}
            </a>
        </div>
    </div>
</aside>
