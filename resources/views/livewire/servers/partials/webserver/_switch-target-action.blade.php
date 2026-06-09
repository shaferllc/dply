{{--
    Switch CTA for a single non-active webserver target in the overview picker.
    Expects: $key, $info, $inflightSwitch, $isBlocked, $isDeployer, $opsReady,
    and optional $actionInFlight (defaults false).
--}}
@php
    $actionInFlight = $actionInFlight ?? false;
    $isComingSoon = ! empty($info['coming_soon']);
    $switchActionTarget = "openSwitchWebserver('{$key}')";
@endphp

@if ($inflightSwitch)
    <div class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-brand-sand/40 px-3 py-1.5 text-xs font-semibold text-brand-mist">
        <x-spinner variant="forest" size="sm" />
        <span>{{ __('Switching in progress…') }}</span>
    </div>
@elseif ($isComingSoon)
    <div class="mt-3 flex flex-col gap-2">
        <div class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-brand-ink/10 bg-brand-sand/35 px-3 py-1.5 text-xs font-semibold text-brand-moss">
            <x-heroicon-o-clock class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{{ __('Coming soon') }}</span>
        </div>
        <button
            type="button"
            wire:click="setWorkspaceTab('{{ $key }}')"
            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40"
        >
            <x-heroicon-o-eye class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ __('Preview') }}
        </button>
    </div>
@else
    <button
        type="button"
        wire:click="openSwitchWebserver('{{ $key }}')"
        wire:loading.attr="disabled"
        wire:target="{{ $switchActionTarget }}"
        @disabled($isDeployer || ! $opsReady || $isBlocked || $actionInFlight)
        @class([
            'mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60',
            'bg-brand-forest text-brand-cream shadow-sm shadow-brand-forest/20 hover:bg-brand-forest/90' => ! $isBlocked,
            'cursor-not-allowed bg-brand-sand/40 text-brand-mist' => $isBlocked,
        ])
        title="{{ $isBlocked ? __('Unavailable — see preflight blocker') : '' }}"
    >
        <span wire:loading.remove wire:target="{{ $switchActionTarget }}" class="inline-flex">
            @if ($isBlocked)
                <x-heroicon-o-no-symbol class="h-4 w-4" />
            @else
                <x-heroicon-o-arrow-path class="h-4 w-4" />
            @endif
        </span>
        <span wire:loading wire:target="{{ $switchActionTarget }}" class="inline-flex">
            <x-spinner variant="cream" size="sm" />
        </span>
        <span wire:loading.remove wire:target="{{ $switchActionTarget }}">
            @if ($isBlocked)
                {{ __('Unavailable') }}
            @else
                {{ __('Switch to :name', ['name' => $info['label']]) }}
            @endif
        </span>
        <span wire:loading wire:target="{{ $switchActionTarget }}">
            {{ __('Opening…') }}
        </span>
    </button>
@endif
