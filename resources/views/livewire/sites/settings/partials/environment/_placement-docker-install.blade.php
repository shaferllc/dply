{{-- Docker placement follow-up: install Engine in-place instead of sending
     the operator to Server → Manage → Tools. $p is a databasePlacements() row. --}}
@if (! empty($p['installing']))
    <span class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-amber-800">
        <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
        {{ __('Installing Docker Engine…') }}
    </span>
@elseif (! empty($p['install_action']))
    <button
        type="button"
        wire:click.stop="confirmInstallDockerOnServer"
        wire:loading.attr="disabled"
        wire:target="confirmInstallDockerOnServer,installDockerOnServer,syncDockerInstallProgress"
        class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
    >
        <x-heroicon-o-cube-transparent class="h-4 w-4" aria-hidden="true" />
        {{ __('Install Docker') }}
    </button>
@elseif ($p['note'])
    <span class="mt-0.5 block text-xs font-medium text-amber-700">{{ $p['note'] }}</span>
@endif
