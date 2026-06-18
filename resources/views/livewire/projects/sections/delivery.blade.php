@livewire(\App\Livewire\Notifications\ResourceSummary::class, [
    'resource' => $workspace,
    'heading' => __('Project notifications'),
    'manageUrl' => route('profile.notification-channels'),
], key('resource-summary-project-'.$workspace->id))

<x-section-card tone="subtle">
    <h3 class="text-base font-semibold text-brand-ink">{{ __('Recovery and migration checklist') }}</h3>
    <div class="mt-4 grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('1. Shared config is ready') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Keep the variables and secrets this project needs in one place before rebuilding a server or moving traffic.') }}</p>
        </div>
        <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('2. Recovery steps are written down') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Tie rollback notes, backup destinations, import commands, and cache-clear steps to project runbooks so another operator can take over.') }}</p>
        </div>
        <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('3. Releases can move together') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('When one deploy spans several sites, queue it here so release timing and follow-up checks stay grouped.') }}</p>
        </div>
    </div>
</x-section-card>

<div class="grid gap-8 xl:grid-cols-2">
    <x-section-card>
        <div class="mb-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Project variables and secrets') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Store values here when several sites in the project need the same configuration. Use secrets for credentials or tokens, and non-secret values for shared flags or endpoints.') }}</p>
        </div>
        <div class="mb-5 space-y-3">
            @forelse ($workspace->variables as $variable)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 px-3 py-3">
                    <div>
                        <p class="font-medium text-brand-ink">{{ $variable->env_key }}</p>
                        <p class="text-sm text-brand-moss">{{ $variable->is_secret ? __('Secret value') : ($variable->env_value ?? __('Empty')) }}</p>
                    </div>
                    @can('update', $workspace)
                        <button type="button" wire:click="deleteVariable('{{ $variable->id }}')" class="text-xs text-red-600 hover:text-red-800">{{ __('Remove') }}</button>
                    @endcan
                </div>
            @empty
                <p class="text-sm text-brand-moss">{{ __('No shared variables yet.') }}</p>
            @endforelse
        </div>

        @can('update', $workspace)
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <x-input-label for="variable-key" :value="__('Key')" />
                    <x-text-input id="variable-key" wire:model="variableKey" type="text" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="variable-value" :value="__('Value')" />
                    <x-text-input id="variable-value" wire:model="variableValue" type="text" class="mt-1 block w-full" />
                </div>
            </div>
            <label class="mt-3 inline-flex items-center gap-2 text-sm text-brand-moss">
                <input type="checkbox" wire:model="variableIsSecret" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage/40">
                <span>{{ __('Treat as secret') }}</span>
            </label>
            <div class="mt-3">
                <x-secondary-button type="button" wire:click="saveVariable">{{ __('Save variable') }}</x-secondary-button>
            </div>
        @endcan
    </x-section-card>

    <x-section-card>
        <div class="mb-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Coordinated deploys') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Select the sites that should deploy together, then queue a project deploy. This is useful when one release spans multiple apps, services, or frontends in the same project.') }}</p>
        </div>
        <div class="mb-4 space-y-2">
            @forelse ($workspace->sites as $site)
                <div class="rounded-xl border border-brand-ink/10 px-3 py-3">
                    <label class="flex items-center gap-3 text-sm">
                        <input type="checkbox" wire:model="selectedDeploySiteIds" value="{{ $site->id }}" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage/40">
                        <span class="font-medium text-brand-ink">{{ $site->name }}</span>
                    </label>
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 ps-6 text-xs text-brand-moss">
                        <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-ink">{{ __('Open site') }}</a>
                        <a href="{{ route('sites.insights', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-ink">{{ __('Insights') }}</a>
                    </div>
                </div>
            @empty
                <p class="text-sm text-brand-moss">{{ __('No sites in this project yet.') }}</p>
            @endforelse
        </div>
        @if ($workspace->userCanDeploy(auth()->user()))
            <div class="mb-5">
                <x-primary-button type="button" wire:click="queueWorkspaceDeploy">{{ __('Queue project deploy') }}</x-primary-button>
            </div>
        @endif

        <div class="space-y-3">
            @forelse ($workspace->deployRuns->take(5) as $run)
                <div class="rounded-xl border border-brand-ink/10 px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-medium text-brand-ink">{{ ucfirst($run->status) }}</p>
                        <p class="text-xs text-brand-moss">{{ $run->created_at?->diffForHumans() }}</p>
                    </div>
                    @if ($run->result_summary)
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('Success: :success, skipped: :skipped, failed: :failed', ['success' => $run->result_summary['successful'] ?? 0, 'skipped' => $run->result_summary['skipped'] ?? 0, 'failed' => $run->result_summary['failed'] ?? 0]) }}
                        </p>
                    @endif
                    @if ($run->output)
                        <pre class="mt-2 whitespace-pre-wrap text-xs text-brand-moss">{{ $run->output }}</pre>
                    @endif
                </div>
            @empty
                <p class="text-sm text-brand-moss">{{ __('No project deploy runs yet.') }}</p>
            @endforelse
        </div>
    </x-section-card>
</div>
