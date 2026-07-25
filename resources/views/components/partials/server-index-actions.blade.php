@props([
    /** @var \App\Support\Servers\ServerIndexRow $server */
    'server',
    'showMutations' => false,
    'compact' => false,
    'responsive' => false,
])

@php
    $btnPad = $compact ? 'px-2.5 py-1.5 sm:px-3' : 'px-3.5 py-2';
@endphp

@if ($showMutations && $server->deployable)
    <button
        type="button"
        wire:click="openServerDeploy('{{ $server->id }}')"
        wire:loading.attr="disabled"
        wire:target="openServerDeploy('{{ $server->id }}')"
        title="{{ __('Deploy this host') }}"
        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white {{ $btnPad }} text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
    >
        <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" wire:loading.remove wire:target="openServerDeploy('{{ $server->id }}')" aria-hidden="true" />
        <span wire:loading wire:target="openServerDeploy('{{ $server->id }}')" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>
        <span @class(['hidden sm:inline' => $responsive])>{{ __('Deploy') }}</span>
    </button>
@elseif (! $showMutations && $server->deployable)
    <a
        href="{{ $server->manageHref }}"
        target="_blank"
        rel="noopener noreferrer"
        title="{{ __('Deploy on production') }}"
        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white {{ $btnPad }} text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
    >
        <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" aria-hidden="true" />
        <span @class(['hidden sm:inline' => $responsive])>{{ __('Deploy') }}</span>
    </a>
@endif

<a href="{{ $server->manageHref }}" @if ($server->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-ink {{ $btnPad }} text-xs font-semibold text-brand-cream transition hover:bg-brand-forest">
    <x-heroicon-m-cog-6-tooth class="h-4 w-4 shrink-0" aria-hidden="true" />
    {{ __('Manage') }}
</a>

@if ($showMutations && ($server->canDelete || $server->scheduledDeletionAt !== null))
    <x-dropdown align="right" width="w-56">
        <x-slot name="trigger">
            <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-brand-ink/15 bg-white text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink sm:h-9 sm:w-9" title="{{ __('More actions') }}">
                <span class="sr-only">{{ __('More actions') }}</span>
                <x-heroicon-o-ellipsis-vertical class="h-4 w-4" aria-hidden="true" />
            </button>
        </x-slot>
        <x-slot name="content">
            <a href="{{ $server->manageHref }}" wire:navigate class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                {{ __('Open workspace') }}
            </a>
            @if ($server->scheduledDeletionAt !== null)
                <button type="button" wire:click="cancelScheduledServerRemoval(@js($server->id))" class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                    <x-heroicon-o-arrow-uturn-left class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                    {{ __('Cancel scheduled removal') }}
                </button>
            @endif
            @if ($server->canDelete)
                <button type="button" wire:click="openRemoveServerModal(@js($server->id))" class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-red-600 transition hover:bg-red-50">
                    <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Remove server') }}
                </button>
            @endif
        </x-slot>
    </x-dropdown>
@elseif ($server->manageExternal)
    <x-dropdown align="right" width="w-56">
        <x-slot name="trigger">
            <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-brand-ink/15 bg-white text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink sm:h-9 sm:w-9" title="{{ __('More actions') }}">
                <span class="sr-only">{{ __('More actions') }}</span>
                <x-heroicon-o-ellipsis-vertical class="h-4 w-4" aria-hidden="true" />
            </button>
        </x-slot>
        <x-slot name="content">
            <a href="{{ $server->manageHref }}" target="_blank" rel="noopener noreferrer" class="flex w-full items-center gap-2.5 px-4 py-2 text-start text-sm font-medium text-brand-ink transition hover:bg-brand-sand/40">
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                {{ __('Open on production') }}
            </a>
        </x-slot>
    </x-dropdown>
@endif
