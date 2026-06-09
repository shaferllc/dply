@php
    $isClone = $editing_pipeline_anchor === 'clone';
    $hint = $isClone ? ($pipelineAnchorDefaultCloneHint ?? '') : ($pipelineAnchorDefaultActivateHint ?? '');
    $model = $isClone ? 'pipeline_clone_script' : 'pipeline_activate_script';
@endphp

<div>
    <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Shell script') }}</label>
    <textarea
        @if ($isClone)
            wire:model.live="pipeline_clone_script"
        @else
            wire:model.live="pipeline_activate_script"
        @endif
        rows="8"
        class="w-full rounded-lg border-brand-ink/15 font-mono text-xs"
        placeholder="{{ $hint }}"
    ></textarea>
    <x-input-error :messages="$errors->get($model)" class="mt-1" />
    <p class="mt-2 text-[11px] text-brand-mist">
        {{ __('Uses deploy script variables plus') }}
        <span class="font-mono text-brand-moss">{RELEASE_DIR}</span>,
        <span class="font-mono text-brand-moss">{REPO_URL}</span>,
        <span class="font-mono text-brand-moss">{GIT_SSH_PREFIX}</span>,
        <span class="font-mono text-brand-moss">{BASE_DIR}</span>.
        <a href="{{ route('sites.pipeline', [$server, $site]) }}?tab=reference" class="font-semibold text-brand-sage hover:underline">{{ __('See Reference tab') }}</a>
    </p>
</div>
