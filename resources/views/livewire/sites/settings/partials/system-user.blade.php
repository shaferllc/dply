<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-8">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('System user') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Linux account for this site’s files, PHP-FPM pool, and remote commands (Laravel setup, Composer, etc.). Dply runs SSH as the server deploy user, then executes work as this user when it differs. Requires passwordless sudo from the deploy user to impersonate other accounts.') }}
        </p>
    </div>

    @if (! $this->shouldShowSystemUserPanel())
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            {{ __('System user management is available for VM-backed PHP sites on this workspace. Use Runtime for container, serverless, and non-PHP targets.') }}
        </div>
    @else
        <form wire:submit="saveSystemUserSettings" class="space-y-8">
            @php
                $op = is_array($site->meta ?? null) ? ($site->meta['system_user_operation'] ?? null) : null;
            @endphp
            @if (is_array($op) && ! empty($op['message']))
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/40 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-brand-ink">{{ ($op['status'] ?? '') === 'error' ? __('System user operation failed') : __('System user operation') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">{{ $op['message'] }}</p>
                        </div>
                        <button type="button" wire:click="dismissSystemUserOperationBanner" class="text-sm font-medium text-brand-forest hover:underline">
                            {{ __('Dismiss') }}
                        </button>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Effective user') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Resolved from the override below, or the server’s deploy user when empty. This drives file ownership actions and remote command execution context.') }}</p>
                </div>
                <p class="font-mono text-sm text-brand-ink">{{ $site->effectiveSystemUser($this->server) }}</p>
            </div>

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-xl space-y-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Reset file permissions') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Reapply default ownership and chmods on this site’s repository path over SSH (effective user + web group). Use after permission mistakes or access issues.') }}</p>
                    </div>
                    <x-secondary-button type="button" wire:click="openSystemUserResetPermissionsModal">
                        {{ __('Reset permissions…') }}
                    </x-secondary-button>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <button
                    type="button"
                    wire:click="$set('system_user_panel_mode', 'create')"
                    class="rounded-xl border p-4 text-left transition {{ $system_user_panel_mode === 'create' ? 'border-brand-forest ring-2 ring-brand-forest/30' : 'border-brand-ink/10 hover:border-brand-ink/25' }}"
                >
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Create new') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Create a Linux user on the server and assign this site’s files to that account.') }}</p>
                </button>
                <button
                    type="button"
                    wire:click="$set('system_user_panel_mode', 'existing')"
                    class="rounded-xl border p-4 text-left transition {{ $system_user_panel_mode === 'existing' ? 'border-brand-forest ring-2 ring-brand-forest/30' : 'border-brand-ink/10 hover:border-brand-ink/25' }}"
                >
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Existing') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Select a user that already exists on the server.') }}</p>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-secondary-button type="button" wire:click="loadSystemUsersForPanel" wire:loading.attr="disabled" wire:target="loadSystemUsersForPanel">
                    <span wire:loading.remove wire:target="loadSystemUsersForPanel">{{ __('Load system users') }}</span>
                    <span wire:loading wire:target="loadSystemUsersForPanel" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" class="h-4 w-4" />
                        {{ __('Loading…') }}
                    </span>
                </x-secondary-button>
                @if ($system_user_list_error)
                    <span class="text-sm text-red-600">{{ $system_user_list_error }}</span>
                @endif
            </div>

            @if ($system_user_panel_mode === 'create')
                <div class="flex flex-wrap gap-2">
                    <x-primary-button type="button" wire:click="openSystemUserCreateModal">{{ __('Create system user…') }}</x-primary-button>
                </div>
            @else
                <div class="max-w-md space-y-2">
                    <x-input-label for="system_user_assign_pick" :value="__('Select system user')" />
                    <select id="system_user_assign_pick" wire:model="system_user_assign_username" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                        <option value="">{{ __('Choose a user…') }}</option>
                        @foreach ($system_user_remote_rows as $row)
                            <option value="{{ $row['username'] }}">{{ $row['username'] }} ({{ $row['site_count'] }} {{ __('sites') }})</option>
                        @endforeach
                    </select>
                    <x-primary-button type="button" class="mt-2" wire:click="openSystemUserAssignModal" :disabled="count($system_user_remote_rows) === 0">
                        {{ __('Apply selection…') }}
                    </x-primary-button>
                </div>
            @endif

            <div class="border-t border-brand-ink/10 pt-6">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Remove a user from the server') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Allowed only when no site on this server uses that account. root, dply, and the deploy user cannot be removed.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-secondary-button type="button" wire:click="openSystemUserRemoveModal" :disabled="count($system_user_remote_rows) === 0">
                        {{ __('Remove user…') }}
                    </x-secondary-button>
                </div>
            </div>

            <div class="space-y-2 border-t border-brand-ink/10 pt-6">
                <x-input-label for="system_user_php_fpm_manual" :value="__('PHP-FPM pool user (stored on site)')" />
                <x-text-input id="system_user_php_fpm_manual" wire:model="php_fpm_user" class="mt-1 block w-full max-w-md text-sm" placeholder="www-data" />
                <p class="text-xs text-brand-moss">{{ __('Optional manual override. Leave empty to use the server’s deploy user. Automated actions above set this for you.') }}</p>
                <x-input-error :messages="$errors->get('php_fpm_user')" class="mt-1" />
            </div>

            <div class="flex flex-wrap gap-3">
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    @endif
</section>
