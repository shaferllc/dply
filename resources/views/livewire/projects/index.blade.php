<div class="contents">
    <x-workspace-nav surface="local" />

    <x-projects-index-page
        :rows="$rows"
        :summary="$summary"
        :has-projects-in-scope="$hasProjectsInScope"
        :has-organization="$hasOrganization"
        :labels="$labels"
        :workspace-roles="$workspaceRoles"
        :search="$search"
        :label-filter="$labelFilter"
        :role-filter="$roleFilter"
        :show-filters="true"
        :show-create-action="$canCreateProject"
        empty-state="local"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Projects'), 'icon' => 'rectangle-group'],
        ]"
    >
        @if ($hasOrganization && $canCreateProject)
            <x-slot:modals>
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
            </x-slot:modals>
        @endif
    </x-projects-index-page>
</div>
