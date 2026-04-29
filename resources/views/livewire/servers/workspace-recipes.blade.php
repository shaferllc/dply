@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="recipes"
    :title="__('Saved commands')"
    :description="__('Keep server-local runbooks, maintenance commands, and one-off operational scripts for this machine.')"
>
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $command_output ?? null, 'command_error' => $command_error ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        <div class="space-y-8">
            @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 18])

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
                <p class="font-semibold">{{ __('Choose the right scope') }}</p>
                <p class="mt-1 leading-relaxed text-brand-moss">
                    {{ __('Saved commands stay on this server only. Use Deploy for release commands, Scripts for organization-wide reusable automation, and Marketplace when you want a curated starter.') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-3 text-sm font-medium">
                    <a href="{{ route('servers.deploy', $server) }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Open deploy') }}</a>
                    <a href="{{ route('scripts.index') }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Open scripts') }}</a>
                    <a href="{{ route('marketplace.index') }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Browse marketplace') }}</a>
                </div>
            </div>

            <div class="grid gap-8 xl:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.95fr)]">
                <div class="{{ $card }} p-6 sm:p-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Saved commands') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Use this list for server-local runbooks, diagnostics, and maintenance commands that should not be shared across the whole organization.') }}
                            </p>
                        </div>
                    </div>

                    @if ($server->recipes->isEmpty())
                        <div class="mt-6 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-5 text-sm text-brand-moss">
                            {{ __('No saved commands yet. Add one below, copy in an organization script, or import a server recipe from the marketplace.') }}
                        </div>
                    @else
                        <ul class="mt-6 space-y-3 text-sm">
                            @foreach ($server->recipes as $rec)
                                <li class="rounded-xl border border-brand-ink/10 px-4 py-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="font-medium text-brand-ink">{{ $rec->name }}</p>
                                            <p class="mt-1 text-xs text-brand-moss">
                                                {{ __('Server-local command. You can run it here or promote it into Deploy when it becomes part of release flow.') }}
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap gap-2 text-xs font-medium">
                                            <button type="button" wire:click="runRecipe('{{ $rec->id }}')" class="text-brand-sage hover:underline">{{ __('Run') }}</button>
                                            <button type="button" wire:click="editRecipe('{{ $rec->id }}')" class="text-brand-ink hover:underline">{{ __('Edit') }}</button>
                                            <button type="button" wire:click="useRecipeAsDeployCommand('{{ $rec->id }}')" class="text-brand-ink hover:underline">{{ __('Use as deploy') }}</button>
                                            <button type="button" wire:click="openConfirmActionModal('deleteRecipe', ['{{ $rec->id }}'], @js(__('Delete saved command')), @js(__('Delete saved command?')), @js(__('Delete')), true)" class="text-red-600 hover:underline">{{ __('Delete') }}</button>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="space-y-8">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Import into this server') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('Marketplace imports create server-local saved commands here. Script presets still clone into Scripts first, then you can copy them into this server.') }}
                        </p>

                        <div class="mt-5 flex flex-wrap gap-3 text-sm font-medium">
                            <a href="{{ route('marketplace.index') }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Import from marketplace') }}</a>
                            <a href="{{ route('scripts.marketplace') }}" wire:navigate class="text-brand-ink hover:text-brand-sage">{{ __('Browse script presets') }}</a>
                        </div>

                        @if ($organizationScripts->isNotEmpty())
                            <form wire:submit="importOrganizationScript" class="mt-6 space-y-3">
                                <label for="import_script_id" class="block text-sm font-medium text-brand-ink">{{ __('Copy from organization scripts') }}</label>
                                <select id="import_script_id" wire:model="import_script_id" class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm">
                                    <option value="">{{ __('Choose a script') }}</option>
                                    @foreach ($organizationScripts as $script)
                                        <option value="{{ $script->id }}">{{ $script->name }}</option>
                                    @endforeach
                                </select>
                                @error('import_script_id')
                                    <p class="text-sm text-red-600">{{ $message }}</p>
                                @enderror
                                <x-primary-button type="submit">{{ __('Copy to this server') }}</x-primary-button>
                            </form>
                        @else
                            <div class="mt-6 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-4 text-sm text-brand-moss">
                                {{ __('No organization scripts yet. Start in Scripts or Script presets, then copy the ones that belong only on this server.') }}
                            </div>
                        @endif
                    </div>

                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">
                                    {{ $editing_recipe_id ? __('Edit saved command') : __('Add saved command') }}
                                </h2>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Store the command exactly as it should run on this server. When it becomes part of release automation, promote it into Deploy explicitly.') }}
                                </p>
                            </div>
                            @if ($editing_recipe_id)
                                <button type="button" wire:click="cancelEditingRecipe" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                                    {{ __('Cancel editing') }}
                                </button>
                            @endif
                        </div>

                        <form wire:submit="addRecipe" class="mt-6 space-y-4">
                            <x-text-input wire:model="new_recipe_name" placeholder="{{ __('Saved command name') }}" />
                            <textarea wire:model="new_recipe_script" rows="10" class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs shadow-sm"></textarea>
                            <div class="flex flex-wrap gap-3">
                                <x-primary-button type="submit" class="!py-2">
                                    {{ $editing_recipe_id ? __('Save changes') : __('Add saved command') }}
                                </x-primary-button>
                                @if ($editing_recipe_id)
                                    <button type="button" wire:click="cancelEditingRecipe" class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        {{ __('Cancel') }}
                                    </button>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use this section.') }}
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
