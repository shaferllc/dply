@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="firewall"
    :title="__('Firewall')"
    :description="__('Manage sites, databases, automation, and deploy tools for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Firewall (UFW allow)') }}</h2>
            <p class="mt-2 text-sm text-brand-copper leading-relaxed">{{ __('Runs ufw allow for each rule. Confirm SSH access before relying on UFW.') }}</p>
            @if ($server->firewallRules->isNotEmpty())
                <ul class="mt-6 space-y-2 text-sm">
                    @foreach ($server->firewallRules as $fr)
                        <li class="flex justify-between rounded-lg border border-brand-ink/10 px-3 py-2">
                            <span>{{ __('Allow') }} {{ $fr->port }}/{{ $fr->protocol }}</span>
                            <button type="button" wire:click="deleteFirewallRule({{ $fr->id }})" class="text-xs font-medium text-red-600 hover:underline">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addFirewallRule" class="mt-6 flex flex-wrap items-end gap-2">
                <x-text-input type="number" wire:model="new_fw_port" class="w-24" />
                <select wire:model="new_fw_protocol" class="rounded-lg border-brand-ink/15 text-sm">
                    <option value="tcp">tcp</option>
                    <option value="udp">udp</option>
                </select>
                <x-primary-button type="submit" class="!py-2">{{ __('Add rule') }}</x-primary-button>
                <button type="button" wire:click="applyFirewall" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm">{{ __('Apply UFW rules') }}</button>
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
