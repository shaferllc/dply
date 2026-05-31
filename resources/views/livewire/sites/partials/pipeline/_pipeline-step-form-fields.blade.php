@php
    $isEditing = ($editing_deploy_step_id ?? null) !== null;
    $needsCommand = \App\Models\SiteDeployStep::needsCustomCommand($new_deploy_step_type ?? '');
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="new_deploy_step_type" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Step type') }}</label>
        <select id="new_deploy_step_type" wire:model.live.debounce.150ms="new_deploy_step_type" class="w-full rounded-lg border-brand-ink/15 text-sm">
            @foreach (\App\Models\SiteDeployStep::typeLabels() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="new_deploy_step_phase" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Phase') }}</label>
        <select id="new_deploy_step_phase" wire:model.live.debounce.150ms="new_deploy_step_phase" class="w-full rounded-lg border-brand-ink/15 text-sm">
            <option value="build">{{ __('Build (before activate)') }}</option>
            <option value="release">{{ __('Release (after activate)') }}</option>
        </select>
    </div>
    @if ($needsCommand)
        <div class="sm:col-span-2">
            <label for="new_deploy_step_command" class="mb-1 block text-xs font-medium text-brand-moss">
                @if ($new_deploy_step_type === 'custom')
                    {{ __('Shell command') }}
                @else
                    {{ __('npm script name') }}
                @endif
            </label>
            <textarea
                id="new_deploy_step_command"
                wire:model.live.debounce.150ms="new_deploy_step_command"
                rows="{{ $new_deploy_step_type === 'custom' ? 3 : 1 }}"
                class="w-full rounded-lg border-brand-ink/15 font-mono text-sm"
                placeholder="{{ $new_deploy_step_type === 'custom' ? 'php artisan horizon:publish' : 'build' }}"
            ></textarea>
            <x-input-error :messages="$errors->get('new_deploy_step_command')" class="mt-1" />
        </div>
    @endif
    <div>
        <label for="new_deploy_step_timeout" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Timeout (seconds)') }}</label>
            <input type="number" id="new_deploy_step_timeout" wire:model.live.debounce.150ms="new_deploy_step_timeout" min="30" max="3600" class="w-full rounded-lg border-brand-ink/15 text-sm" />
        <x-input-error :messages="$errors->get('new_deploy_step_timeout')" class="mt-1" />
    </div>
</div>

@unless ($isEditing)
    <button
        type="button"
        wire:click="openAddPipelineStepForm('custom', 'build')"
        class="text-xs font-semibold text-brand-forest hover:underline"
    >
        {{ __('Reset to custom command') }}
    </button>
@endunless
