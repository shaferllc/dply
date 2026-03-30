<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Projects') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Group servers and sites the way you work—similar to other hosting panels.') }}</p>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
            @if (session('success'))
                <div class="p-4 rounded-md bg-green-50 text-green-800 text-sm">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="p-4 rounded-md bg-red-50 text-red-800 text-sm">{{ session('error') }}</div>
            @endif

            @if (! $hasOrganization)
                <div class="bg-white border border-slate-200 rounded-lg p-6 text-slate-600 text-sm">
                    {{ __('Select an organization from the header to manage projects.') }}
                </div>
            @else
                @can('create', App\Models\Workspace::class)
                    <div class="bg-white border border-slate-200 shadow-sm rounded-lg p-6">
                        <h3 class="font-medium text-slate-900 mb-4">{{ __('New project') }}</h3>
                        <form wire:submit="createProject" class="space-y-4 max-w-xl">
                            <div>
                                <x-input-label for="proj-name" :value="__('Name')" />
                                <x-text-input id="proj-name" wire:model="name" type="text" class="mt-1 block w-full" required />
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="proj-desc" :value="__('Description (optional)')" />
                                <textarea id="proj-desc" wire:model="description" rows="3" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm focus:border-slate-500 focus:ring-slate-500 text-sm"></textarea>
                                <x-input-error :messages="$errors->get('description')" class="mt-2" />
                            </div>
                            <div>
                                <x-primary-button type="submit">{{ __('Create project') }}</x-primary-button>
                            </div>
                        </form>
                    </div>
                @endcan

                <div>
                    <h3 class="font-medium text-slate-900 mb-3">{{ __('Your projects') }}</h3>
                    @if ($workspaces->isEmpty())
                        <div class="bg-white border border-slate-200 rounded-lg p-8 text-center text-slate-500 text-sm">
                            {{ __('No projects yet. Create one to assign servers and sites.') }}
                        </div>
                    @else
                        <ul class="bg-white border border-slate-200 rounded-lg divide-y divide-slate-200">
                            @foreach ($workspaces as $w)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 hover:bg-slate-50">
                                    <div>
                                        <a href="{{ route('projects.show', $w) }}" class="font-medium text-slate-900 hover:text-slate-700">{{ $w->name }}</a>
                                        @if ($w->description)
                                            <p class="text-sm text-slate-500 mt-0.5">{{ $w->description }}</p>
                                        @endif
                                        <p class="text-xs text-slate-400 mt-1">
                                            {{ trans_choice(':count server|:count servers', $w->servers_count, ['count' => $w->servers_count]) }}
                                            ·
                                            {{ trans_choice(':count site|:count sites', $w->sites_count, ['count' => $w->sites_count]) }}
                                        </p>
                                    </div>
                                    <a href="{{ route('projects.show', $w) }}" class="text-sm font-medium text-slate-700 hover:text-slate-900">{{ __('Manage') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
