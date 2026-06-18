{{-- At-a-glance stats (Overview only — other sections are self-contained). --}}
<div class="grid gap-4 md:grid-cols-4">
    <x-stat-card :label="__('Health')" :value="$health['status_label']" :meta="$health['servers_ready'].'/'.$health['servers_total'].' '.__('servers ready')" />
    <x-stat-card :label="__('Sites')" :value="$costSummary['sites_used']" :meta="$costSummary['sites_remaining_label']" />
    <x-stat-card :label="__('Servers')" :value="$costSummary['servers_used']" :meta="$costSummary['servers_remaining_label']" />
    <x-stat-card :label="__('Deploy runs')" :value="$costSummary['deploy_runs_count']" :meta="__('Variables: :count', ['count' => $costSummary['variables_count']])" />
</div>

@if ($health['issues'] !== [])
    <x-alert tone="warning">
        <p class="font-medium">{{ __('Needs attention') }}</p>
        <ul class="mt-2 space-y-1">
            @foreach ($health['issues'] as $issue)
                <li>{{ $issue }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

<x-section-card tone="subtle">
    <h3 class="text-base font-semibold text-brand-ink">{{ __('Projects are for day-two operations, not just grouping') }}</h3>
    <p class="mt-2 text-sm leading-6 text-brand-moss">
        {{ __('Use a project as the shared operating surface for one app, customer, or environment family. Keep runbooks, health, release context, notification routing, and shared variables here so recovery and change management do not depend on one person remembering the setup.') }}
    </p>
</x-section-card>

<x-section-card>
    <h3 class="text-base font-semibold text-brand-ink">{{ __('How to use this project') }}</h3>
    <p class="mt-2 text-sm leading-6 text-brand-moss">
        {{ __('Use this page as the control center for one logical stack, customer, or app family. A project should answer three questions quickly: what resources belong together, who can operate them, and what needs attention right now.') }}
    </p>
    <div class="mt-4 grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('1. Define the project') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Add a clear description, architecture notes, labels, and environments so other teammates know what this project exists for.') }}</p>
        </div>
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('2. Attach the right resources') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Group the servers and sites that belong together. Think of a project as a managed bundle, not just a tag.') }}</p>
        </div>
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('3. Operate from one place') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Use activity, alerts, variables, and deploy batches here before diving into individual server or site pages.') }}</p>
        </div>
    </div>
</x-section-card>

@can('update', $workspace)
    <x-section-card>
        <div class="mb-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Details and notes') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Start here when creating a new project. The description should explain the business purpose; notes should capture the operational context someone needs when they open this page later.') }}</p>
        </div>
        <form wire:submit="saveDetails" class="space-y-4">
            <div>
                <x-input-label for="edit-name" :value="__('Name')" />
                <x-text-input id="edit-name" wire:model="editName" type="text" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('editName')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="edit-desc" :value="__('Description')" />
                <x-textarea id="edit-desc" wire:model="editDescription" rows="3" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Example: Customer production stack, marketing properties, or internal staging fleet.') }}</p>
                <x-input-error :messages="$errors->get('editDescription')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="edit-notes" :value="__('Project notes')" />
                <x-textarea id="edit-notes" wire:model="editNotes" rows="6" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Use notes for architecture context, provider quirks, DNS assumptions, incident history, customer handoff details, and anything operators should know before making changes.') }}</p>
                <x-input-error :messages="$errors->get('editNotes')" class="mt-2" />
            </div>
            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
        </form>
    </x-section-card>
@endcan

<div class="grid gap-8 xl:grid-cols-2">
    <x-section-card>
        <div class="mb-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Environments') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Use environments to explain how resources are used inside the project, such as production, staging, or QA. They help teammates understand the intended lifecycle even when multiple sites or servers live in the same project.') }}</p>
        </div>
        <div class="mb-5 space-y-3">
            @foreach ($workspace->environments as $environment)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 px-3 py-3">
                    <div>
                        <p class="font-medium text-brand-ink">{{ $environment->name }}</p>
                        @if ($environment->description)
                            <p class="text-sm text-brand-moss">{{ $environment->description }}</p>
                        @endif
                    </div>
                    @can('update', $workspace)
                        <button type="button" wire:click="removeEnvironment('{{ $environment->id }}')" class="text-xs text-red-600 hover:text-red-800">{{ __('Remove') }}</button>
                    @endcan
                </div>
            @endforeach
        </div>

        @can('update', $workspace)
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <x-input-label for="environment-name" :value="__('Environment name')" />
                    <x-text-input id="environment-name" wire:model="environmentName" type="text" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="environment-description" :value="__('Description')" />
                    <x-text-input id="environment-description" wire:model="environmentDescription" type="text" class="mt-1 block w-full" />
                </div>
            </div>
            <div class="mt-3">
                <x-secondary-button type="button" wire:click="addEnvironment">{{ __('Add environment') }}</x-secondary-button>
            </div>
        @endcan
    </x-section-card>

    <x-section-card>
        <div class="mb-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Labels and organization') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Labels are best for quick filtering across many projects. Use them for customer names, app type, criticality, or ownership, while keeping the project itself as the main grouping container.') }}</p>
        </div>
        <div class="mb-5 flex flex-wrap gap-2">
            @foreach ($labels as $label)
                @php($attached = $workspace->labels->contains('id', $label->id))
                <button type="button" wire:click="toggleLabel('{{ $label->id }}')" class="rounded-full border px-3 py-1 text-xs font-semibold transition-colors {{ $attached ? 'border-brand-ink bg-brand-ink text-brand-cream' : 'border-brand-ink/15 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' }}">
                    {{ $label->name }}
                </button>
            @endforeach
        </div>

        @can('update', $workspace)
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <x-input-label for="label-name" :value="__('New label')" />
                    <x-text-input id="label-name" wire:model="labelName" type="text" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="label-color" :value="__('Color name')" />
                    <x-text-input id="label-color" wire:model="labelColor" type="text" class="mt-1 block w-full" placeholder="slate" />
                </div>
            </div>
            <div class="mt-3">
                <x-secondary-button type="button" wire:click="createLabel">{{ __('Create label') }}</x-secondary-button>
            </div>
        @endcan
    </x-section-card>
</div>

@can('delete', $workspace)
    <x-section-card class="border-rose-200">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-base font-semibold text-rose-800">{{ __('Delete project') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Servers and sites stay in your organization; only this project grouping is removed.') }}</p>
            </div>
            <button
                type="button"
                wire:click="openConfirmActionModal('destroyWorkspace', [], @js(__('Delete project')), @js(__('Delete this project? Servers and sites stay in your organization; only the group is removed.')), @js(__('Delete project')), true)"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 shadow-sm transition-colors hover:bg-rose-50"
            >
                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Delete project') }}
            </button>
        </div>
    </x-section-card>
@endcan
