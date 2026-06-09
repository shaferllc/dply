@include('livewire.servers.partials.cache-engine-info-card', [
    'info' => $dbEngineInfoForTab,
    'row' => $engineRow,
    'card' => $card,
])

@if (\App\Support\Servers\DatabaseWorkspaceEngines::isMysqlFamily($engine))
    @php
        $manageDbHasCreds = ! empty($server->meta['manage_internal_db_password']);
        $processlistAction = $serviceActions['mysql_processlist'] ?? null;
    @endphp

    @if ($manageActionRun)
        @include('livewire.partials.console-action-banner-static', [
            'run' => $manageActionRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])
    @endif

    <div class="{{ $card }} p-6 sm:p-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Live processlist') }}</h3>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Runs SHOW PROCESSLIST against the engine over SSH. Output streams into the banner above.') }}</p>
                @if (! $manageDbHasCreds)
                    <p class="mt-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-xs text-brand-moss">
                        {{ __('Add a manage password below to unlock the processlist when root requires auth.') }}
                    </p>
                @endif
            </div>
            @if ($processlistAction && $opsReady && ! $isDeployer)
                <button
                    type="button"
                    wire:click="openConfirmActionModal('runAllowlistedManageAction', ['mysql_processlist'], @js($processlistAction['label']), @js($processlistAction['confirm']), @js($processlistAction['label']), false)"
                    class="inline-flex shrink-0 items-center gap-2 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                    {{ $processlistAction['label'] }}
                </button>
            @endif
        </div>
    </div>

    <div id="db-manage-hints-{{ $engine }}" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Database connection hints') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Optional values for Dply features such as backups, and to authenticate the processlist action above.') }}
        </p>
        <form wire:submit="saveManageDbHints" class="mt-6 max-w-xl space-y-4">
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
                <x-password-field
                    id="manage_db_password"
                    :label="__('Internal database password')"
                    wire:model="manage_db_password"
                    :placeholder="__('Leave blank to keep current')"
                    mono
                    class="mt-2 block w-full font-mono text-sm focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                    @disabled($isDeployer)
                />
                <p class="mt-1 text-xs text-brand-mist">{{ __('Used to authenticate the processlist action. Stored in server metadata; treat as sensitive.') }}</p>
            </div>
            <div>
                <x-primary-button type="submit" class="!py-2.5" :disabled="$isDeployer">{{ __('Save connection hints') }}</x-primary-button>
            </div>
        </form>
    </div>
@endif
