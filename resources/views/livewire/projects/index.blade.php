<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <x-dashboard-breadcrumb :current="__('Projects')" current-icon="rectangle-group" />

        <x-page-header
            :title="__('Projects')"
            :description="__('Group servers, sites, and member access for each initiative.')"
            doc-route="docs.index"
            flush
            compact
            toolbar
        >
            <x-slot name="leading">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <x-heroicon-o-rectangle-group class="h-7 w-7 text-brand-ink" aria-hidden="true" />
                </span>
            </x-slot>
            @if ($hasOrganization)
                @can('create', App\Models\Workspace::class)
                    <x-slot name="actions">
                        <button
                            type="button"
                            wire:click="openCreateProjectModal"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('New project') }}
                        </button>
                    </x-slot>
                @endcan
            @endif
        </x-page-header>

        @if (! $hasOrganization)
            <x-empty-state
                :title="__('Select an organization from the header to manage projects.')"
                :description="null"
                :dashed="false"
            />
        @else
            <div class="space-y-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your projects') }}</h2>
                    @if ($views->isNotEmpty())
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Saved views') }}</span>
                            @foreach ($views as $view)
                                <button
                                    type="button"
                                    wire:click="applySavedView('{{ $view->id }}')"
                                    class="rounded-full border border-brand-mist bg-white px-3 py-1 text-xs font-medium text-brand-ink shadow-sm ring-1 ring-brand-ink/5 hover:bg-brand-sand/30"
                                >
                                    {{ $view->name }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <x-section-card padding="sm">
                    <p class="mb-4 text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Filters') }}</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12 lg:items-start lg:gap-x-6 lg:gap-y-4">
                        <div class="sm:col-span-2 lg:col-span-5">
                            <x-input-label for="project-search" :value="__('Search')" />
                            <x-text-input
                                id="project-search"
                                wire:model.live="search"
                                type="search"
                                autocomplete="off"
                                class="mt-1 block w-full min-h-[2.5rem]"
                                placeholder="{{ __('Search by name, notes, or description') }}"
                            />
                        </div>
                        <div class="lg:col-span-3">
                            <x-input-label for="project-label-filter" :value="__('Label')" />
                            <x-select id="project-label-filter" wire:model.live="labelFilter" class="mt-1 block w-full min-h-[2.5rem]">
                                <option value="">{{ __('All labels') }}</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}">{{ $label->name }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="lg:col-span-4">
                            <x-input-label for="project-role-filter" :value="__('My role')" />
                            <x-select id="project-role-filter" wire:model.live="roleFilter" class="mt-1 block w-full min-h-[2.5rem]">
                                <option value="">{{ __('Any role') }}</option>
                                @foreach ($workspaceRoles as $role)
                                    <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    </div>
                    <div class="mt-6 grid grid-cols-1 gap-4 border-t border-brand-ink/10 pt-6 lg:grid-cols-12 lg:items-end lg:gap-x-6">
                        <div class="lg:col-span-8">
                            <x-input-label for="saved-view-name" :value="__('Save this filter set')" />
                            <x-text-input
                                id="saved-view-name"
                                wire:model="savedViewName"
                                type="text"
                                class="mt-1 block w-full min-h-[2.5rem]"
                                placeholder="{{ __('e.g. Production') }}"
                            />
                        </div>
                        <div class="flex flex-wrap items-center gap-2 lg:col-span-4 lg:justify-end">
                            <x-secondary-button type="button" wire:click="saveView">{{ __('Save view') }}</x-secondary-button>
                            <button type="button" wire:click="clearFilters" class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Clear') }}</button>
                        </div>
                    </div>
                </x-section-card>

                @if ($workspaces->isEmpty())
                    <div class="rounded-2xl border border-dashed border-brand-mist/80 bg-brand-sand/10 px-6 py-12 text-center">
                        <p class="font-medium text-brand-ink">{{ __('No projects match these filters yet.') }}</p>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Create a project to attach servers and sites, or adjust filters above.') }}</p>
                        @can('create', App\Models\Workspace::class)
                            <div class="mt-6">
                                <x-primary-button type="button" wire:click="openCreateProjectModal">
                                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('New project') }}
                                </x-primary-button>
                            </div>
                        @endcan
                    </div>
                @else
                    <x-section-card padding="none" class="overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-left text-sm">
                                <thead class="bg-brand-cream/50 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <tr>
                                        <th scope="col" class="px-4 py-3">{{ __('Project') }}</th>
                                        <th scope="col" class="px-4 py-3">{{ __('Your role') }}</th>
                                        <th scope="col" class="hidden lg:table-cell px-4 py-3">{{ __('Labels') }}</th>
                                        <th scope="col" class="hidden sm:table-cell px-4 py-3">{{ __('Resources') }}</th>
                                        <th scope="col" class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/10">
                                    @foreach ($workspaces as $w)
                                        @php($membership = $w->members->firstWhere('user_id', auth()->id()))
                                        <tr class="transition-colors hover:bg-brand-sand/15">
                                            <td class="px-4 py-3 align-top">
                                                <a href="{{ route('projects.show', $w) }}" class="font-medium text-brand-ink hover:text-brand-sage">{{ $w->name }}</a>
                                                @if ($w->description)
                                                    <p class="mt-1 max-w-md text-sm text-brand-moss line-clamp-2">{{ $w->description }}</p>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                                @if ($membership)
                                                    <x-badge size="sm">{{ ucfirst($membership->role) }}</x-badge>
                                                @else
                                                    <span class="text-sm text-brand-mist">—</span>
                                                @endif
                                            </td>
                                            <td class="hidden lg:table-cell px-4 py-3 align-top">
                                                @if ($w->labels->isEmpty())
                                                    <span class="text-sm text-brand-mist">—</span>
                                                @else
                                                    <div class="flex flex-wrap gap-1">
                                                        @foreach ($w->labels as $label)
                                                            <x-badge size="sm">{{ $label->name }}</x-badge>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="hidden sm:table-cell px-4 py-3 align-top text-sm text-brand-moss">
                                                {{ trans_choice(':count server|:count servers', $w->servers_count, ['count' => $w->servers_count]) }}
                                                <span class="text-brand-mist" aria-hidden="true">·</span>
                                                {{ trans_choice(':count site|:count sites', $w->sites_count, ['count' => $w->sites_count]) }}
                                                <span class="text-brand-mist" aria-hidden="true">·</span>
                                                {{ trans_choice(':count member|:count members', $w->members->count(), ['count' => $w->members->count()]) }}
                                            </td>
                                            <td class="px-4 py-3 align-top text-end whitespace-nowrap">
                                                <a href="{{ route('projects.show', $w) }}" class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Manage') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-section-card>
                @endif
            </div>
        @endif
    </div>

    @if ($hasOrganization)
        @can('create', App\Models\Workspace::class)
        <x-modal
            name="create-project-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="createProject">
                <div class="border-b border-brand-ink/10 px-6 py-5 dark:border-brand-mist/20">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New project') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Create a project') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                        {{ __('Group servers and sites, then invite members with roles that fit how your team works.') }}
                    </p>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="proj-name-modal" :value="__('Name')" />
                        <x-text-input
                            id="proj-name-modal"
                            wire:model="name"
                            type="text"
                            class="mt-2 block w-full"
                            required
                            maxlength="120"
                            autocomplete="off"
                        />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="proj-desc-modal" :value="__('Description (optional)')" />
                        <x-textarea id="proj-desc-modal" wire:model="description" rows="3" class="mt-2 block w-full" />
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4 dark:border-brand-mist/20">
                    <x-secondary-button type="button" wire:click="closeCreateProjectModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createProject">
                        <span wire:loading.remove wire:target="createProject">{{ __('Create project') }}</span>
                        <span wire:loading wire:target="createProject" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Creating…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>
        @endcan
    @endif
</div>
