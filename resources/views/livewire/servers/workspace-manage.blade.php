@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="manage"
    :title="__('Manage')"
    :description="__('Configuration previews, service actions, and Dply-side metadata for backups and future automation.')"
>
    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $remote_output ?? null, 'command_error' => $remote_error ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 18])

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project operations context') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('Manage actions on this server can affect the rest of the :project project. Use the project operations page for runbooks, activity review, and alert routing before making broader stack changes.', ['project' => $server->workspace->name]) }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project resources') }}</a>
            </div>
        </div>
    @endif

    @if ($isDeployer)
        <div class="rounded-2xl border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
            {{ __('Deployers can view this page but cannot run SSH actions or change manage settings.') }}
        </div>
    @endif

    @if (! $opsReady)
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before previews and service actions work.') }}
        </div>
    @endif

    <div class="space-y-8">
        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Configuration files') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('Read-only previews of allowlisted paths (first portion of the file over SSH). Editing is not available yet.') }}
            </p>
            <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($configPreviews as $previewKey => $preview)
                    <button
                        type="button"
                        wire:click="previewConfig('{{ $previewKey }}')"
                        @disabled(! $opsReady || $isDeployer)
                        class="{{ $btnPrimary }} w-full sm:w-auto"
                    >
                        <x-heroicon-o-document-magnifying-glass class="h-4 w-4 shrink-0 opacity-90" />
                        {{ $preview['label'] ?? $previewKey }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Service actions') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('Runs fixed scripts as your server SSH user. Many actions need passwordless sudo on the guest.') }}
            </p>
            <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($serviceActions as $actionKey => $action)
                    <button
                        type="button"
                        wire:click="runAllowlistedAction('{{ $actionKey }}')"
                        wire:confirm="{{ $action['confirm'] ?? __('Run this action?') }}"
                        @disabled(! $opsReady || $isDeployer)
                        class="{{ $btnPrimary }} w-full sm:w-auto"
                    >
                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 opacity-90" />
                        {{ $action['label'] ?? $actionKey }}
                    </button>
                @endforeach
            </div>
        </div>

        @if (count($dangerousActions) > 0)
            <div class="{{ $card }} p-6 sm:p-8 border-red-200/50">
                <h2 class="text-lg font-semibold text-red-900">{{ __('Danger zone') }}</h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                    {{ __('These actions can disrupt production traffic or drop your SSH session.') }}
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    @foreach ($dangerousActions as $actionKey => $action)
                        <button
                            type="button"
                            wire:click="runAllowlistedAction('{{ $actionKey }}')"
                            wire:confirm="{{ $action['confirm'] ?? __('Are you sure?') }}"
                            @disabled(! $opsReady || $isDeployer)
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-red-900 hover:bg-red-100 transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" />
                            {{ $action['label'] ?? $actionKey }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('More tools') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('Clone server, one-click apps, and system users are not available in Dply yet. Use Recipes, Sites, and SSH for similar workflows.') }}
            </p>
        </div>

        <div class="{{ $card }} p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Automatic update notifications') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('Routing maintenance events to notification channels will ship in a later release. Use organization notification settings for deploy and health events today.') }}
            </p>
        </div>

        <div id="manage-preferences" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Preferences') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('Automatic update cadence and database hints are stored in Dply for future automation. They are not applied to the server by this screen.') }}
            </p>
            <form wire:submit="saveManageMetadata" class="mt-6 max-w-xl space-y-8">
                <div id="manage-os-updates" class="scroll-mt-24">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Automatic updates') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss leading-relaxed">
                        {{ __('Preferred cadence is saved for future use. Use “Refresh package lists” under Service actions for apt-get update today.') }}
                    </p>
                    <div class="mt-4">
                        <label for="manage_auto_updates_interval" class="block text-sm font-medium text-brand-ink">{{ __('Preferred cadence') }}</label>
                        <select
                            id="manage_auto_updates_interval"
                            wire:model="manage_auto_updates_interval"
                            @disabled($isDeployer)
                            class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                        >
                            @foreach ($autoUpdateIntervals as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('manage_auto_updates_interval')
                            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <div class="border-t border-brand-ink/10 pt-8">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Database connection hints') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss leading-relaxed">
                        {{ __('Optional values for Dply features such as backups.') }}
                    </p>
                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="manage_db_bind_host" class="block text-sm font-medium text-brand-ink">{{ __('Database bind address') }}</label>
                            <input
                                id="manage_db_bind_host"
                                type="text"
                                wire:model="manage_db_bind_host"
                                placeholder="127.0.0.1"
                                autocomplete="off"
                                @disabled($isDeployer)
                                class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                            />
                            @error('manage_db_bind_host')
                                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="manage_db_port" class="block text-sm font-medium text-brand-ink">{{ __('Database port') }}</label>
                            <input
                                id="manage_db_port"
                                type="number"
                                wire:model="manage_db_port"
                                placeholder="3306"
                                min="1"
                                max="65535"
                                autocomplete="off"
                                @disabled($isDeployer)
                                class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                            />
                            @error('manage_db_port')
                                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="manage_db_password" class="block text-sm font-medium text-brand-ink">{{ __('Internal database password') }}</label>
                            <input
                                id="manage_db_password"
                                type="password"
                                wire:model="manage_db_password"
                                placeholder="{{ __('Leave blank to keep current') }}"
                                autocomplete="new-password"
                                @disabled($isDeployer)
                                class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                            />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Stored in server metadata for Dply-only use. Treat as sensitive.') }}</p>
                        </div>
                    </div>
                </div>
                <div>
                    <x-primary-button type="submit" class="!py-2.5" :disabled="$isDeployer">{{ __('Save preferences') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
