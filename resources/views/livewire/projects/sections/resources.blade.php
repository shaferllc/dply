<div class="grid gap-8 lg:grid-cols-2">
    <x-section-card>
        <div class="mb-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Servers in this project') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Attach infrastructure that is primarily operated as part of this project. This makes it easier to review health, ownership, and related sites together.') }}</p>
        </div>
        @if ($workspace->servers->isEmpty())
            <p class="mb-4 text-sm text-brand-moss">{{ __('No servers yet.') }}</p>
        @else
            <ul class="mb-4 divide-y divide-brand-ink/10">
                @foreach ($workspace->servers as $server)
                    <li class="flex flex-wrap items-start justify-between gap-3 py-3">
                        <div>
                            <a href="{{ route('servers.show', $server) }}" class="font-medium text-brand-ink hover:underline">{{ $server->name }}</a>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-brand-moss">
                                <a href="{{ route('servers.show', $server) }}" class="hover:text-brand-ink">{{ __('Overview') }}</a>
                                <a href="{{ route('servers.logs', $server) }}" wire:navigate class="hover:text-brand-ink">{{ __('Logs') }}</a>
                                <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="hover:text-brand-ink">{{ __('Metrics') }}</a>
                                <a href="{{ route('servers.services', $server) }}" wire:navigate class="hover:text-brand-ink">{{ __('Services') }}</a>
                                <a href="{{ route('servers.manage', $server) }}" wire:navigate class="hover:text-brand-ink">{{ __('Manage') }}</a>
                            </div>
                        </div>
                        @can('update', $server)
                            <button type="button" wire:click="detachServer({{ $server->id }})" class="text-xs text-brand-moss hover:text-red-600">{{ __('Remove') }}</button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @can('update', $workspace)
            @if ($availableServers->isNotEmpty())
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <x-input-label for="server-pick" :value="__('Add server')" />
                        <x-select id="server-pick" wire:model="serverToAttach">
                            <option value="">{{ __('Choose...') }}</option>
                            @foreach ($availableServers as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-secondary-button type="button" wire:click="attachServer">{{ __('Add') }}</x-secondary-button>
                </div>
            @else
                <p class="text-xs text-brand-mist">{{ __('All servers in this organization are already in this project, or you have no servers yet.') }}</p>
            @endif
        @endcan
    </x-section-card>

    <x-section-card>
        <div class="mb-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Sites in this project') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Attach sites that should deploy, alert, and be reviewed alongside this project. This is useful for multi-site apps, customer estates, and grouped environments.') }}</p>
            <p class="mt-2 text-sm text-brand-moss">{{ __("You can manage project membership here or from each site's settings page.") }}</p>
        </div>
        @if ($workspace->sites->isEmpty())
            <p class="mb-4 text-sm text-brand-moss">{{ __('No sites yet.') }}</p>
        @else
            <ul class="mb-4 divide-y divide-brand-ink/10">
                @foreach ($workspace->sites as $site)
                    <li class="flex flex-wrap items-start justify-between gap-3 py-3">
                        <div>
                            <a href="{{ route('sites.show', [$site->server, $site]) }}" class="font-medium text-brand-ink hover:underline">{{ $site->name }}</a>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-brand-moss">
                                <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="hover:text-brand-ink">{{ __('General') }}</a>
                                <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="hover:text-brand-ink">{{ __('Deploy') }}</a>
                                <a href="{{ route('sites.insights', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-ink">{{ __('Insights') }}</a>
                            </div>
                        </div>
                        @can('update', $site)
                            <button type="button" wire:click="detachSite({{ $site->id }})" class="text-xs text-brand-moss hover:text-red-600">{{ __('Remove') }}</button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @can('update', $workspace)
            @if ($availableSites->isNotEmpty())
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <x-input-label for="site-pick" :value="__('Add site')" />
                        <x-select id="site-pick" wire:model="siteToAttach">
                            <option value="">{{ __('Choose...') }}</option>
                            @foreach ($availableSites as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-secondary-button type="button" wire:click="attachSite">{{ __('Add') }}</x-secondary-button>
                </div>
            @else
                <p class="text-xs text-brand-mist">{{ __('All sites are already in this project, or you have no sites yet.') }}</p>
            @endif
        @endcan
    </x-section-card>
</div>
