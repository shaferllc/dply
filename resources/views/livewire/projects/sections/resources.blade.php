{{-- Merged chrome, matching the server workspace: one card per resource kind,
     dense head over hairline rows, and the attach control as a sand footer
     strip. The prose stacks these panels opened with (two to three paragraphs
     each) cost more height than the rows they introduced — they're the head
     note now. --}}
<div class="grid gap-4 lg:grid-cols-2 lg:items-start">
    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-server-stack"
            :title="__('Servers in this project')"
            :count="$workspace->servers->isNotEmpty() ? $workspace->servers->count() : null"
            :note="__('Infrastructure primarily operated as part of this project — review health, ownership, and related sites together.')"
            class="border-b border-brand-ink/10"
        />

        @if ($workspace->servers->isEmpty())
            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-brand-ink/10 px-4 py-2.5 text-xs text-brand-moss sm:px-5">
                <x-heroicon-m-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                {{ __('No servers yet.') }}
            </p>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($workspace->servers as $server)
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 sm:px-5" wire:key="project-server-{{ $server->id }}">
                        <a href="{{ route('servers.show', $server) }}" class="shrink-0 text-xs font-semibold text-brand-ink hover:underline">{{ $server->name }}</a>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                        <nav class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-mist" aria-label="{{ __('Server shortcuts') }}">
                            <a href="{{ route('servers.show', $server) }}" class="hover:text-brand-ink">{{ __('Overview') }}</a>
                            <a href="{{ route('servers.logs', $server) }}" wire:navigate class="hover:text-brand-ink">{{ __('Logs') }}</a>
                            <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="hover:text-brand-ink">{{ __('Metrics') }}</a>
                            <a href="{{ route('servers.services', $server) }}" wire:navigate class="hover:text-brand-ink">{{ __('Services') }}</a>
                            <a href="{{ route('servers.manage', $server) }}" wire:navigate class="hover:text-brand-ink">{{ __('Manage') }}</a>
                        </nav>
                        @can('update', $server)
                            <button type="button" wire:click="detachServer({{ $server->id }})" class="ml-auto shrink-0 text-xs font-semibold text-brand-moss hover:text-red-600">{{ __('Remove') }}</button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @can('update', $workspace)
            <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-4 py-2 sm:px-5">
                @if ($availableServers->isNotEmpty())
                    <label for="server-pick" class="shrink-0 text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add server') }}</label>
                    <select
                        id="server-pick"
                        wire:model="serverToAttach"
                        class="min-w-0 flex-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 sm:max-w-xs"
                    >
                        <option value="">{{ __('Choose...') }}</option>
                        @foreach ($availableServers as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <button
                        type="button"
                        wire:click="attachServer"
                        class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ __('Add') }}
                    </button>
                @else
                    <p class="text-xs text-brand-mist">{{ __('All servers in this organization are already in this project, or you have no servers yet.') }}</p>
                @endif
            </div>
        @endcan
    </section>

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-globe-alt"
            :title="__('Sites in this project')"
            :count="$workspace->sites->isNotEmpty() ? $workspace->sites->count() : null"
            :note="__('Sites that deploy, alert, and get reviewed alongside this project — multi-site apps, customer estates, grouped environments. Membership can also be managed from each site’s settings.')"
            class="border-b border-brand-ink/10"
        />

        @if ($workspace->sites->isEmpty())
            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-brand-ink/10 px-4 py-2.5 text-xs text-brand-moss sm:px-5">
                <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                {{ __('No sites yet.') }}
            </p>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($workspace->sites as $site)
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 sm:px-5" wire:key="project-site-{{ $site->id }}">
                        <a href="{{ route('sites.show', [$site->server, $site]) }}" class="shrink-0 text-xs font-semibold text-brand-ink hover:underline">{{ $site->name }}</a>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                        <nav class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-mist" aria-label="{{ __('Site shortcuts') }}">
                            <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="hover:text-brand-ink">{{ __('General') }}</a>
                            <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="hover:text-brand-ink">{{ __('Deploy') }}</a>
                            <a href="{{ route('sites.insights', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-ink">{{ __('Insights') }}</a>
                        </nav>
                        @can('update', $site)
                            <button type="button" wire:click="detachSite({{ $site->id }})" class="ml-auto shrink-0 text-xs font-semibold text-brand-moss hover:text-red-600">{{ __('Remove') }}</button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @can('update', $workspace)
            <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-4 py-2 sm:px-5">
                @if ($availableSites->isNotEmpty())
                    <label for="site-pick" class="shrink-0 text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add site') }}</label>
                    <select
                        id="site-pick"
                        wire:model="siteToAttach"
                        class="min-w-0 flex-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 sm:max-w-xs"
                    >
                        <option value="">{{ __('Choose...') }}</option>
                        @foreach ($availableSites as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <button
                        type="button"
                        wire:click="attachSite"
                        class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ __('Add') }}
                    </button>
                @else
                    <p class="text-xs text-brand-mist">{{ __('All sites are already in this project, or you have no sites yet.') }}</p>
                @endif
            </div>
        @endcan
    </section>
</div>
