<div>
    <x-livewire-validation-errors />

    <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('profile.edit') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Profile') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('SSH keys') }}</li>
        </ol>
    </nav>

    <header class="mb-8">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('SSH keys') }}</h1>
        <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
            {{ __('Save public keys on your account, optionally add them automatically to new servers, and deploy them to existing servers when you need access.') }}
        </p>
    </header>

    @if ($flash_success)
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ $flash_success }}</div>
    @endif
    @if ($flash_error)
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $flash_error }}</div>
    @endif

    <div class="space-y-10">
        {{-- New key --}}
        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('New SSH key') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Paste an OpenSSH public key. Private keys are never stored here.') }}
                    </p>
                </div>
                <div class="lg:col-span-8 space-y-5">
                    <div>
                        <x-input-label for="ssh_new_name" :value="__('Name')" />
                        <x-text-input id="ssh_new_name" wire:model="new_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Work laptop') }}" autocomplete="off" />
                        <x-input-error :messages="$errors->get('new_name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ssh_new_pub" :value="__('Public key')" />
                        <textarea id="ssh_new_pub" wire:model="new_public_key" rows="5" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage" placeholder="ssh-ed25519 AAAA…"></textarea>
                        <x-input-error :messages="$errors->get('new_public_key')" class="mt-2" />
                    </div>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model.boolean="new_provision_on_new_servers" class="mt-1 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage" />
                        <span class="text-sm text-brand-moss leading-relaxed">{{ __('Always provision to new servers') }} <span class="text-brand-mist">({{ __('uses servers you create while this is enabled') }})</span></span>
                    </label>

                    @if ($servers->isNotEmpty())
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <x-input-label :value="__('Select servers to push this key to (optional)')" />
                                <button
                                    type="button"
                                    wire:click="$set('new_server_ids', {{ json_encode($servers->pluck('id')->values()->all()) }})"
                                    class="text-xs font-medium text-brand-sage hover:text-brand-ink"
                                >{{ __('Select all') }}</button>
                            </div>
                            <div class="max-h-48 overflow-y-auto rounded-xl border border-brand-ink/10 divide-y divide-brand-ink/10">
                                @foreach ($servers as $server)
                                    <label class="flex items-center gap-3 px-3 py-2.5 hover:bg-brand-sand/30 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            wire:model.live="new_server_ids"
                                            value="{{ $server->id }}"
                                            class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage"
                                        />
                                        <span class="text-sm text-brand-ink font-medium">{{ $server->name }}</span>
                                        @if ($server->ip_address)
                                            <span class="text-xs text-brand-mist font-mono">{{ $server->ip_address }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('new_server_ids')" class="mt-2" />
                        </div>
                    @else
                        @if ($currentOrganization)
                            <p class="text-sm text-brand-moss">{{ __('No servers in :org yet. Add a server under Servers to push this key during setup or from the list below after you create the key.', ['org' => $currentOrganization->name]) }}</p>
                        @else
                            <p class="text-sm text-brand-moss">{{ __('Create or join an organization, then add servers to push keys during setup. You can still save keys to your account below.') }}</p>
                        @endif
                    @endif

                    <div>
                        <x-primary-button type="button" wire:click="createKey" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="createKey">{{ __('Create') }}</span>
                            <span wire:loading wire:target="createKey">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </section>

        {{-- List --}}
        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-brand-ink/10 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your keys') }}</h2>
            </div>
            @if ($sshKeys->isEmpty())
                <p class="px-6 py-10 text-sm text-brand-moss text-center">{{ __('No SSH keys yet. Add one above.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10 text-left text-brand-mist">
                                <th class="px-6 py-3 font-medium">{{ __('Name') }}</th>
                                <th class="px-6 py-3 font-medium">{{ __('On new servers') }}</th>
                                <th class="px-6 py-3 font-medium text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sshKeys as $key)
                                <tr wire:key="ssh-key-{{ $key->id }}" class="border-b border-brand-ink/5">
                                    <td class="px-6 py-3 text-brand-ink font-medium">{{ $key->name }}</td>
                                    <td class="px-6 py-3 text-brand-moss">{{ $key->provision_on_new_servers ? __('Yes') : __('No') }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap space-x-2">
                                        @if ($servers->isNotEmpty())
                                            <button type="button" wire:click="startDeploy({{ $key->id }})" class="text-brand-sage font-medium hover:text-brand-ink text-xs">{{ __('Deploy on servers') }}</button>
                                        @endif
                                        <button type="button" wire:click="startEdit({{ $key->id }})" class="text-brand-sage font-medium hover:text-brand-ink text-xs">{{ __('Edit') }}</button>
                                        <button type="button" wire:click="deleteKey({{ $key->id }})" wire:confirm="{{ __('Remove this key from your account? Linked copies on servers will be removed on the next sync.') }}" class="text-red-600 font-medium hover:underline text-xs">{{ __('Delete') }}</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    {{-- Edit panel --}}
    @if ($editing_id)
        <div class="fixed inset-0 z-40 flex items-end sm:items-center justify-center p-4 bg-brand-ink/40" role="dialog" aria-modal="true">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl border border-brand-ink/10 p-6 max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-semibold text-brand-ink mb-4">{{ __('Edit SSH key') }}</h3>
                <div class="space-y-4">
                    <div>
                        <x-input-label for="ssh_edit_name" :value="__('Name')" />
                        <x-text-input id="ssh_edit_name" wire:model="edit_name" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('edit_name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ssh_edit_pub" :value="__('Public key')" />
                        <textarea id="ssh_edit_pub" wire:model="edit_public_key" rows="5" class="mt-1 block w-full rounded-xl border border-brand-ink/15 font-mono text-sm"></textarea>
                        <x-input-error :messages="$errors->get('edit_public_key')" class="mt-2" />
                    </div>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model.boolean="edit_provision_on_new_servers" class="mt-1 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage" />
                        <span class="text-sm text-brand-moss">{{ __('Always provision to new servers') }}</span>
                    </label>
                </div>
                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="cancelEdit" class="px-4 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <x-primary-button type="button" wire:click="saveEdit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveEdit">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveEdit">{{ __('Saving…') }}</span>
                    </x-primary-button>
                </div>
            </div>
        </div>
    @endif

    {{-- Deploy modal --}}
    @if ($deploying_id)
        <div class="fixed inset-0 z-40 flex items-end sm:items-center justify-center p-4 bg-brand-ink/40" role="dialog" aria-modal="true">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl border border-brand-ink/10 p-6 max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-semibold text-brand-ink mb-2">{{ __('Deploy on servers') }}</h3>
                <p class="text-sm text-brand-moss mb-4">{{ __('Choose servers to add or update this key in Dply, then sync authorized_keys over SSH.') }}</p>
                <div class="flex justify-end mb-2">
                    <button type="button" wire:click="$set('deploy_server_ids', {{ json_encode($servers->pluck('id')->values()->all()) }})" class="text-xs font-medium text-brand-sage hover:text-brand-ink">{{ __('Select all') }}</button>
                </div>
                <div class="max-h-48 overflow-y-auto rounded-xl border border-brand-ink/10 divide-y divide-brand-ink/10 mb-4">
                    @foreach ($servers as $server)
                        <label class="flex items-center gap-3 px-3 py-2.5 hover:bg-brand-sand/30 cursor-pointer">
                            <input type="checkbox" wire:model.live="deploy_server_ids" value="{{ $server->id }}" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage" />
                            <span class="text-sm text-brand-ink font-medium">{{ $server->name }}</span>
                            @if ($server->ip_address)
                                <span class="text-xs text-brand-mist font-mono">{{ $server->ip_address }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('deploy_server_ids')" class="mb-2" />
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="cancelDeploy" class="px-4 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <x-primary-button type="button" wire:click="confirmDeploy" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="confirmDeploy">{{ __('Deploy') }}</span>
                        <span wire:loading wire:target="confirmDeploy">{{ __('Deploying…') }}</span>
                    </x-primary-button>
                </div>
            </div>
        </div>
    @endif
</div>
