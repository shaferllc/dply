<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Projects') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Group related servers and sites, lock down access, and operate each project from one place.') }}</p>
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
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <h3 class="font-medium text-slate-900">{{ __('Your projects') }}</h3>
                        @if ($views->isNotEmpty())
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs uppercase tracking-wide text-slate-500">{{ __('Saved views') }}</span>
                                @foreach ($views as $view)
                                    <button type="button" wire:click="applySavedView('{{ $view->id }}')" class="rounded-full border border-slate-300 px-3 py-1 text-xs text-slate-700 hover:bg-slate-50">
                                        {{ $view->name }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="bg-white border border-slate-200 rounded-lg p-4 mb-4 grid gap-3 md:grid-cols-4">
                        <div class="md:col-span-2">
                            <x-input-label for="project-search" :value="__('Search')" />
                            <x-text-input id="project-search" wire:model.live="search" type="text" class="mt-1 block w-full" placeholder="{{ __('Search projects, notes, or descriptions') }}" />
                        </div>
                        <div>
                            <x-input-label for="project-label-filter" :value="__('Label')" />
                            <select id="project-label-filter" wire:model.live="labelFilter" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm">
                                <option value="">{{ __('All labels') }}</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}">{{ $label->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="project-role-filter" :value="__('My role')" />
                            <select id="project-role-filter" wire:model.live="roleFilter" class="mt-1 block w-full border-slate-300 rounded-md shadow-sm text-sm">
                                <option value="">{{ __('Any role') }}</option>
                                @foreach ($workspaceRoles as $role)
                                    <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <x-input-label for="saved-view-name" :value="__('Save current filters')" />
                            <x-text-input id="saved-view-name" wire:model="savedViewName" type="text" class="mt-1 block w-full" placeholder="{{ __('Production projects') }}" />
                        </div>
                        <div class="flex items-end gap-2">
                            <x-secondary-button type="button" wire:click="saveView">{{ __('Save view') }}</x-secondary-button>
                            <button type="button" wire:click="clearFilters" class="text-sm text-slate-600 hover:text-slate-900">{{ __('Clear') }}</button>
                        </div>
                    </div>

                    @if ($workspaces->isEmpty())
                        <div class="bg-white border border-slate-200 rounded-lg p-8 text-center text-slate-500 text-sm">
                            {{ __('No projects match these filters yet. Create one to assign servers and sites.') }}
                        </div>
                    @else
                        <ul class="bg-white border border-slate-200 rounded-lg divide-y divide-slate-200">
                            @foreach ($workspaces as $w)
                                <li class="flex flex-wrap items-center justify-between gap-4 px-4 py-4 hover:bg-slate-50">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('projects.show', $w) }}" class="font-medium text-slate-900 hover:text-slate-700">{{ $w->name }}</a>
                                            @php($membership = $w->members->firstWhere('user_id', auth()->id()))
                                            @if ($membership)
                                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">{{ ucfirst($membership->role) }}</span>
                                            @endif
                                            @foreach ($w->labels as $label)
                                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">{{ $label->name }}</span>
                                            @endforeach
                                        </div>
                                        @if ($w->description)
                                            <p class="text-sm text-slate-500 mt-0.5">{{ $w->description }}</p>
                                        @endif
                                        <p class="text-xs text-slate-400 mt-1">
                                            {{ trans_choice(':count server|:count servers', $w->servers_count, ['count' => $w->servers_count]) }}
                                            ·
                                            {{ trans_choice(':count site|:count sites', $w->sites_count, ['count' => $w->sites_count]) }}
                                            ·
                                            {{ trans_choice(':count member|:count members', $w->members->count(), ['count' => $w->members->count()]) }}
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
