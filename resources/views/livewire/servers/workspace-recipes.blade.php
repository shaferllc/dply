@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="recipes"
    :title="__('Recipes')"
    :description="__('Manage sites, databases, automation, and deploy tools for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $command_output ?? null, 'command_error' => $command_error ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 18])
        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recipes (saved bash)') }}</h2>
            @if ($server->recipes->isNotEmpty())
                <ul class="mt-6 space-y-2 text-sm">
                    @foreach ($server->recipes as $rec)
                        <li class="flex items-center justify-between rounded-xl border border-brand-ink/10 px-4 py-3">
                            <span class="font-medium text-brand-ink">{{ $rec->name }}</span>
                            <span class="flex gap-2">
                                <button type="button" wire:click="runRecipe({{ $rec->id }})" class="text-xs font-medium text-brand-sage hover:underline">{{ __('Run') }}</button>
                                <button type="button" wire:click="deleteRecipe({{ $rec->id }})" wire:confirm="{{ __('Delete recipe?') }}" class="text-xs font-medium text-red-600 hover:underline">{{ __('Delete') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addRecipe" class="mt-6 space-y-4">
                <x-text-input wire:model="new_recipe_name" placeholder="{{ __('Recipe name') }}" />
                <textarea wire:model="new_recipe_script" rows="6" class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs shadow-sm"></textarea>
                <x-primary-button type="submit" class="!py-2">{{ __('Save recipe') }}</x-primary-button>
            </form>
        </div>
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use this section.') }}
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
