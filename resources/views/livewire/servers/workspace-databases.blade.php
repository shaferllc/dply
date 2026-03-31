@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="databases"
    :title="__('Databases')"
    :description="__('Manage databases, automation, and deploy tools for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Databases') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Create a database and user on the server via SSH. Dply stores credentials for your records and automation.') }}</p>
            @if ($server->serverDatabases->isNotEmpty())
                <ul class="mt-6 space-y-2 text-sm">
                    @foreach ($server->serverDatabases as $db)
                        <li class="flex items-center justify-between rounded-xl border border-brand-ink/10 px-4 py-3">
                            <span><span class="font-mono font-medium text-brand-ink">{{ $db->name }}</span> <span class="text-brand-moss">({{ $db->engine }})</span></span>
                            <button type="button" wire:click="deleteDatabase({{ $db->id }})" wire:confirm="{{ __('Remove this entry from Dply?') }}" class="text-xs font-medium text-red-600 hover:underline">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="createDatabase" class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="new_db_name" value="{{ __('Database name') }}" />
                    <x-text-input id="new_db_name" wire:model="new_db_name" class="mt-1 block w-full font-mono text-sm" />
                    <x-input-error :messages="$errors->get('new_db_name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="new_db_engine" value="{{ __('Engine') }}" />
                    <select id="new_db_engine" wire:model="new_db_engine" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        <option value="mysql">{{ __('MySQL / MariaDB') }}</option>
                        <option value="postgres">{{ __('PostgreSQL') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="new_db_username" value="{{ __('Username') }}" />
                    <x-text-input id="new_db_username" wire:model="new_db_username" autocomplete="username" class="mt-1 block w-full font-mono text-sm" />
                    <x-input-error :messages="$errors->get('new_db_username')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="new_db_password" value="{{ __('Password') }}" />
                    <x-text-input id="new_db_password" type="password" wire:model="new_db_password" autocomplete="new-password" class="mt-1 block w-full text-sm" />
                    <x-input-error :messages="$errors->get('new_db_password')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="new_db_host" value="{{ __('Host (metadata)') }}" />
                    <x-text-input id="new_db_host" wire:model="new_db_host" class="mt-1 block w-full font-mono text-sm" />
                </div>
                <div class="sm:col-span-2">
                    <x-primary-button type="submit">{{ __('Create on server') }}</x-primary-button>
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
