@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && filled($server->ssh_private_key);
    $consoleActionRun = \App\Models\ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->where('kind', 'system_user')
        ->whereNull('dismissed_at')
        ->orderByDesc('created_at')
        ->first();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="system-users"
    :title="__('System users')"
    :description="__('Linux accounts on this server. Sites pick from these for their file owner / PHP-FPM pool user; create the account here, then assign it to a site from the site\'s System user section.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4" tone="info">
        <p>{{ __('Each row is a Linux user the server already has in /etc/passwd. The site count shows how many Dply-managed sites are currently set to run as that user — those sites must be reassigned before you can remove the account.') }}</p>
        <p>{{ __('root, dply, and the configured deploy user are protected — Dply refuses to remove them. UID below 1000 (system accounts) is also blocked.') }}</p>
    </x-explainer>

    @if (! $opsReady)
        <div class="rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-900">
            {{ __('System users management requires an SSH-ready server. Finish provisioning before managing accounts.') }}
        </div>
    @else
        <div class="space-y-6">
            {{-- Server-scoped console-actions banner. Surfaces the in-flight + most-recent
                 system_user run (create, remove) for this server. --}}
            @include('livewire.partials.console-action-banner-static', [
                'run' => $consoleActionRun,
                'kindLabels' => (array) config('console_actions.kinds', []),
            ])

            <section class="{{ $card }}">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Accounts on this server') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('Loaded from /etc/passwd over SSH. The site counts come from this organization\'s site records.') }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <x-secondary-button type="button" wire:click="loadUsers" wire:loading.attr="disabled" wire:target="loadUsers">
                            <span wire:loading.remove wire:target="loadUsers">{{ __('Refresh list') }}</span>
                            <span wire:loading wire:target="loadUsers" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" size="sm" />
                                {{ __('Loading…') }}
                            </span>
                        </x-secondary-button>
                        <x-primary-button type="button" wire:click="openCreateModal">
                            <x-heroicon-o-plus class="mr-1 h-4 w-4" />
                            {{ __('Create user…') }}
                        </x-primary-button>
                    </div>
                </div>

                @if ($list_error)
                    <div class="border-b border-amber-200 bg-amber-50/70 px-6 py-3 text-sm text-amber-900 sm:px-8">
                        {{ $list_error }}
                    </div>
                @endif

                @if ($remote_rows === [])
                    <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
                        {{ __('No users loaded yet — click "Refresh list" to read /etc/passwd over SSH.') }}
                    </p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($remote_rows as $row)
                            <li class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 sm:px-8">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest sm:inline-flex">
                                        <x-heroicon-o-user-circle class="h-5 w-5" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['username'] }}</p>
                                        <p class="mt-0.5 text-xs text-brand-mist">
                                            {{ trans_choice('{0} no sites|{1} :count site|[2,*] :count sites', $row['site_count'], ['count' => $row['site_count']]) }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="openRemoveModal('{{ $row['username'] }}')"
                                        @disabled($row['site_count'] > 0)
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3 text-xs font-semibold text-red-800 shadow-sm hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40"
                                        title="{{ $row['site_count'] > 0 ? __('Reassign all sites first') : __('Remove this account') }}"
                                    >
                                        <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                        {{ __('Remove') }}
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        {{-- Create modal --}}
        <x-modal
            name="server-system-user-create-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('System user') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Create system user') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Creates a Linux account on this server. It won\'t be assigned to any site — pick it from the site\'s System user section once it exists.') }}
                </p>
            </div>

            <div class="space-y-4 px-6 py-6">
                <div>
                    <x-input-label for="server_system_user_new_username" :value="__('System user name')" />
                    <x-text-input id="server_system_user_new_username" wire:model="new_username" class="mt-1 block w-full font-mono text-sm" placeholder="app-user" autocomplete="off" />
                    <x-input-error :messages="$errors->get('new_username')" class="mt-1" />
                </div>
                <label class="flex items-center gap-2 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="new_sudo" class="rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest">
                    {{ __('Sudo access') }}
                </label>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeCreateModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="queueCreate" wire:loading.attr="disabled" wire:target="queueCreate">
                    <span wire:loading.remove wire:target="queueCreate">{{ __('Create') }}</span>
                    <span wire:loading wire:target="queueCreate" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Queueing…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>

        {{-- Remove modal --}}
        <x-modal
            name="server-system-user-remove-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('System user') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Remove user from server') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Deletes the Linux account from the host when policy allows. root, dply, and the deploy user cannot be removed. Type the username to confirm.') }}
                </p>
            </div>

            <div class="space-y-4 px-6 py-6">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Removing') }}</p>
                    <p class="mt-1 break-all rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5 font-mono text-sm text-brand-ink">{{ $remove_username }}</p>
                </div>
                <div>
                    <x-input-label for="server_system_user_remove_confirm" :value="__('Type the username to confirm')" />
                    <x-text-input id="server_system_user_remove_confirm" wire:model="remove_confirm" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                    <x-input-error :messages="$errors->get('remove_confirm')" class="mt-1" />
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeRemoveModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="queueRemove" wire:loading.attr="disabled" wire:target="queueRemove">
                    <span wire:loading.remove wire:target="queueRemove">{{ __('Remove user') }}</span>
                    <span wire:loading wire:target="queueRemove" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Queueing…') }}
                    </span>
                </x-danger-button>
            </div>
        </x-modal>
    @endif
</x-server-workspace-layout>
