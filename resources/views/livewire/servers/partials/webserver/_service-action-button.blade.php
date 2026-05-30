@php
    /** @var string $actionKey */
    /** @var bool $dangerous */
    /** @var array{label: string, confirm?: string, description?: string} $action */
    /** @var bool $actionInFlight */
    /** @var string $variant lifecycle|tools */
    $variant = $variant ?? 'lifecycle';
    $activeToolActionOps = is_array($activeToolActionOps ?? null) ? $activeToolActionOps : [];
    $pendingToolActionKey = is_string($pendingToolActionKey ?? null) ? $pendingToolActionKey : null;
    $actionIsActive = $pendingToolActionKey === $actionKey
        || (is_array($activeToolActionOps[$actionKey] ?? null)
            && in_array($activeToolActionOps[$actionKey]['status'] ?? '', ['queued', 'running'], true));
    $loadingTargets = $dangerous
        ? "openConfirmActionModal,confirmActionModal,runAllowlistedAction('{$actionKey}')"
        : "runAllowlistedAction('{$actionKey}')";
    $busyMessage = $activeToolActionOps[$actionKey]['message'] ?? __('Running…');
    $buttonClass = match (true) {
        $variant === 'tools' => 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-white disabled:cursor-not-allowed disabled:opacity-60',
        $dangerous => 'inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50/30 px-3 py-1.5 text-xs font-medium text-rose-800 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60',
        default => 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60',
    };
    $busyClass = match ($variant) {
        'tools' => 'inline-flex items-center gap-1.5 rounded-lg border border-brand-sage/35 bg-brand-sage/12 px-3 py-1.5 text-xs font-medium text-brand-forest',
        default => 'inline-flex items-center gap-1.5 rounded-lg border border-brand-sage/35 bg-brand-sage/12 px-3 py-1.5 text-xs font-medium text-brand-forest shadow-sm',
    };
@endphp

@if ($actionIsActive)
    <span class="{{ $busyClass }}" wire:key="webserver-action-busy-{{ $actionKey }}">
        <x-spinner variant="forest" size="sm" />
        {{ $busyMessage }}
    </span>
@else
    <button
        type="button"
        wire:key="webserver-action-{{ $actionKey }}"
        @if ($dangerous) wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm'] ?? ''), @js($action['label']), true)" @else wire:click="runAllowlistedAction('{{ $actionKey }}')" @endif
        wire:loading.attr="disabled"
        wire:target="{{ $loadingTargets }}"
        @disabled($actionInFlight)
        title="{{ $actionInFlight ? __('Another action is running — wait for it to finish.') : ($action['description'] ?? '') }}"
        class="{{ $buttonClass }}"
    >
        <span class="inline-flex items-center gap-1.5" wire:loading.remove wire:target="{{ $loadingTargets }}">
            <x-dynamic-component :component="$iconForAction($actionKey)" @class([
                'h-3.5 w-3.5',
                'opacity-80' => $variant !== 'tools',
                'text-brand-moss' => $variant === 'tools',
            ]) aria-hidden="true" />
            {{ $action['label'] }}
        </span>
        <span class="inline-flex items-center gap-1.5" wire:loading wire:target="{{ $loadingTargets }}">
            <x-spinner variant="forest" size="sm" />
            {{ __('Running…') }}
        </span>
    </button>
@endif
