<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <a href="{{ route('projects.index') }}" class="text-sm text-slate-600 hover:text-slate-900">{{ __('← Projects') }}</a>
                    <h2 class="font-semibold text-xl text-slate-800 leading-tight mt-2">{{ $workspace->name }}</h2>
                </div>
                @can('delete', $workspace)
                    <button
                        type="button"
                        wire:click="destroyWorkspace"
                        wire:confirm="{{ __('Delete this project? Servers and sites stay in your organization; only the group is removed.') }}"
                        class="text-sm text-red-600 hover:text-red-800"
                    >
                        {{ __('Delete project') }}
                    </button>
                @endcan
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            @if (session('success'))
                <div class="p-4 rounded-md bg-green-50 text-green-800 text-sm">{{ session('success') }}</div>
            @endif

            @can('update', $workspace)
                <div class="bg-white border border-slate-200 shadow-sm rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">{{ __('Details') }}</h3>
                    <form wire:submit="saveDetails" class="space-y-4 max-w-xl">
                        <div>
                            <x-input-label for="edit-name" :value="__('Name')" />
                            <x-text-input id="edit-name" wire:model="editName" type="text" class="mt-1 block w-full" required />
                            <x-input-error :messages="$errors->get('editName')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="edit-desc" :value="__('Description')" />
                            <textarea id="edit-desc" wire:model="editDescription" rows="3" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm focus:border-slate-500 focus:ring-slate-500 text-sm"></textarea>
                            <x-input-error :messages="$errors->get('editDescription')" class="mt-2" />
                        </div>
                        <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                    </form>
                </div>
            @endcan

            <div class="grid gap-8 lg:grid-cols-2">
                <div class="bg-white border border-slate-200 rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">{{ __('Servers in this project') }}</h3>
                    @if ($workspace->servers->isEmpty())
                        <p class="text-sm text-slate-500 mb-4">{{ __('No servers yet.') }}</p>
                    @else
                        <ul class="divide-y divide-slate-100 mb-4">
                            @foreach ($workspace->servers as $server)
                                <li class="py-2 flex items-center justify-between gap-2">
                                    <a href="{{ route('servers.show', $server) }}" class="text-slate-900 font-medium hover:underline">{{ $server->name }}</a>
                                    @can('update', $server)
                                        <button type="button" wire:click="detachServer({{ $server->id }})" class="text-xs text-slate-500 hover:text-red-600">{{ __('Remove') }}</button>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @can('update', $workspace)
                        @if ($availableServers->isNotEmpty())
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="flex-1 min-w-[12rem]">
                                    <x-input-label for="server-pick" :value="__('Add server')" />
                                    <select id="server-pick" wire:model="serverToAttach" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm">
                                        <option value="">{{ __('Choose…') }}</option>
                                        @foreach ($availableServers as $s)
                                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <x-secondary-button type="button" wire:click="attachServer">{{ __('Add') }}</x-secondary-button>
                            </div>
                        @else
                            <p class="text-xs text-slate-400">{{ __('All servers in this organization are already in this project, or you have no servers yet.') }}</p>
                        @endif
                    @endcan
                </div>

                <div class="bg-white border border-slate-200 rounded-lg p-6">
                    <h3 class="font-medium text-slate-900 mb-4">{{ __('Sites in this project') }}</h3>
                    @if ($workspace->sites->isEmpty())
                        <p class="text-sm text-slate-500 mb-4">{{ __('No sites yet.') }}</p>
                    @else
                        <ul class="divide-y divide-slate-100 mb-4">
                            @foreach ($workspace->sites as $site)
                                <li class="py-2 flex items-center justify-between gap-2">
                                    <a href="{{ route('sites.show', [$site->server, $site]) }}" class="text-slate-900 font-medium hover:underline">{{ $site->name }}</a>
                                    @can('update', $site)
                                        <button type="button" wire:click="detachSite({{ $site->id }})" class="text-xs text-slate-500 hover:text-red-600">{{ __('Remove') }}</button>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @can('update', $workspace)
                        @if ($availableSites->isNotEmpty())
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="flex-1 min-w-[12rem]">
                                    <x-input-label for="site-pick" :value="__('Add site')" />
                                    <select id="site-pick" wire:model="siteToAttach" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm">
                                        <option value="">{{ __('Choose…') }}</option>
                                        @foreach ($availableSites as $s)
                                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <x-secondary-button type="button" wire:click="attachSite">{{ __('Add') }}</x-secondary-button>
                            </div>
                        @else
                            <p class="text-xs text-slate-400">{{ __('All sites are already in this project, or you have no sites yet.') }}</p>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    </div>
</div>
