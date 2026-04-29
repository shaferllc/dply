<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <x-page-header
            :title="__('Projects')"
            :description="__('Group related servers and sites, lock down access, and operate each project from one place.')"
            doc-route="docs.index"
            flush
        />

            @if (session('success'))
                <x-alert tone="success">{{ session('success') }}</x-alert>
            @endif
            @if (session('error'))
                <x-alert tone="error">{{ session('error') }}</x-alert>
            @endif

            @if (! $hasOrganization)
                <x-empty-state
                    :title="__('Select an organization from the header to manage projects.')"
                    :description="null"
                    :dashed="false"
                />
            @else
                @can('create', App\Models\Workspace::class)
                    <x-section-card>
                        <x-slot name="header">
                        <h3 class="font-medium text-slate-900 mb-4">{{ __('New project') }}</h3>
                        </x-slot>
                        <form wire:submit="createProject" class="space-y-4 max-w-xl">
                            <div>
                                <x-input-label for="proj-name" :value="__('Name')" />
                                <x-text-input id="proj-name" wire:model="name" type="text" class="mt-1 block w-full" required />
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="proj-desc" :value="__('Description (optional)')" />
                                <x-textarea id="proj-desc" wire:model="description" rows="3" />
                                <x-input-error :messages="$errors->get('description')" class="mt-2" />
                            </div>
                            <div>
                                <x-primary-button type="submit">{{ __('Create project') }}</x-primary-button>
                            </div>
                        </form>
                    </x-section-card>
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

                    <x-section-card class="mb-4" padding="sm">
                        <div class="grid gap-3 md:grid-cols-4">
                        <div class="md:col-span-2">
                            <x-input-label for="project-search" :value="__('Search')" />
                            <x-text-input id="project-search" wire:model.live="search" type="text" class="mt-1 block w-full" placeholder="{{ __('Search projects, notes, or descriptions') }}" />
                        </div>
                        <div>
                            <x-input-label for="project-label-filter" :value="__('Label')" />
                            <x-select id="project-label-filter" wire:model.live="labelFilter">
                                <option value="">{{ __('All labels') }}</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}">{{ $label->name }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-input-label for="project-role-filter" :value="__('My role')" />
                            <x-select id="project-role-filter" wire:model.live="roleFilter">
                                <option value="">{{ __('Any role') }}</option>
                                @foreach ($workspaceRoles as $role)
                                    <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="md:col-span-3">
                            <x-input-label for="saved-view-name" :value="__('Save current filters')" />
                            <x-text-input id="saved-view-name" wire:model="savedViewName" type="text" class="mt-1 block w-full" placeholder="{{ __('Production projects') }}" />
                        </div>
                        <div class="flex items-end gap-2">
                            <x-secondary-button type="button" wire:click="saveView">{{ __('Save view') }}</x-secondary-button>
                            <button type="button" wire:click="clearFilters" class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Clear') }}</button>
                        </div>
                        </div>
                    </x-section-card>

                    @if ($workspaces->isEmpty())
                        <x-empty-state
                            :title="__('No projects match these filters yet.')"
                            :description="__('Create one to assign servers and sites.')"
                            :dashed="false"
                        />
                    @else
                        <x-section-card padding="none">
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($workspaces as $w)
                                <li class="flex flex-wrap items-center justify-between gap-4 px-4 py-4 transition-colors hover:bg-brand-sand/15">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('projects.show', $w) }}" class="font-medium text-brand-ink hover:text-brand-sage">{{ $w->name }}</a>
                                            @php($membership = $w->members->firstWhere('user_id', auth()->id()))
                                            @if ($membership)
                                                <x-badge size="sm">{{ ucfirst($membership->role) }}</x-badge>
                                            @endif
                                            @foreach ($w->labels as $label)
                                                <x-badge size="sm">{{ $label->name }}</x-badge>
                                            @endforeach
                                        </div>
                                        @if ($w->description)
                                            <p class="text-sm text-brand-moss mt-0.5">{{ $w->description }}</p>
                                        @endif
                                        <p class="text-xs text-brand-mist mt-1">
                                            {{ trans_choice(':count server|:count servers', $w->servers_count, ['count' => $w->servers_count]) }}
                                            ·
                                            {{ trans_choice(':count site|:count sites', $w->sites_count, ['count' => $w->sites_count]) }}
                                            ·
                                            {{ trans_choice(':count member|:count members', $w->members->count(), ['count' => $w->members->count()]) }}
                                        </p>
                                    </div>
                                    <a href="{{ route('projects.show', $w) }}" class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Manage') }}</a>
                                </li>
                            @endforeach
                        </ul>
                        </x-section-card>
                    @endif
                </div>
            @endif
    </div>
</div>
