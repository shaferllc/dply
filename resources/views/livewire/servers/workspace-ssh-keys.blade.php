@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="ssh"
    :title="__('SSH keys')"
    :description="__('Manage sites, databases, automation, and deploy tools for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Extra SSH public keys') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Merged into the SSH user’s authorized_keys.') }}</p>
            @if ($server->authorizedKeys->isNotEmpty())
                <ul class="mt-6 space-y-2 text-sm">
                    @foreach ($server->authorizedKeys as $ak)
                        <li class="flex justify-between gap-2 rounded-lg border border-brand-ink/10 px-3 py-2">
                            <span class="text-brand-ink">{{ $ak->name }}</span>
                            <button type="button" wire:click="deleteAuthorizedKey({{ $ak->id }})" class="text-xs font-medium text-red-600 hover:underline">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addAuthorizedKey" class="mt-6 space-y-4">
                <x-text-input wire:model="new_auth_name" placeholder="{{ __('Label (e.g. Alice laptop)') }}" />
                <textarea wire:model="new_auth_key" rows="3" class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="ssh-ed25519 AAAA…"></textarea>
                <div class="flex flex-wrap gap-2">
                    <x-primary-button type="submit" class="!py-2">{{ __('Save key') }}</x-primary-button>
                    <button type="button" wire:click="syncAuthorizedKeys" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm">{{ __('Sync authorized_keys') }}</button>
                </div>
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
