<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-8">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('System user') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Linux account that owns this site\'s files and runs its PHP-FPM pool. Pick from accounts that already exist on the server; create new accounts on the server\'s System users page.') }}
        </p>
    </div>

    @if (! $this->shouldShowSystemUserPanel())
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            {{ __('System user management is available for VM-backed PHP sites on this workspace. Use Runtime for container, serverless, and non-PHP targets.') }}
        </div>
    @else
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
                <p class="mt-1 text-xs text-brand-moss">{{ __('Resolved from the override below, or the server\'s deploy user when empty. This drives file ownership actions and remote command execution context.') }}</p>
            </div>
            <p class="font-mono text-sm text-brand-ink">{{ $site->effectiveSystemUser($this->server) }}</p>
        </div>

        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-xl space-y-1">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Reset file permissions') }}</p>
                    <p class="text-xs text-brand-moss">{{ __('Reapply default ownership and chmods on this site\'s repository path over SSH (effective user + web group). Use after permission mistakes or access issues.') }}</p>
                </div>
                <x-secondary-button type="button" wire:click="openSystemUserResetPermissionsModal">
                    {{ __('Reset permissions…') }}
                </x-secondary-button>
            </div>
        </div>

        {{-- Picker. The list comes from `loadSystemUsersForPanel`, which uses
             ServerPasswdUserLister — already filtered server-side to UID >= 1000
             (excluding `nobody`), so distro-shipped system accounts (_apt, bin,
             daemon, mail, sshd, systemd-*, …) never reach the dropdown. When the
             filtered list is empty we hide the picker entirely and point the
             operator at the server's System users page to create one. --}}
        <div class="space-y-3 border-t border-brand-ink/10 pt-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Assign file owner') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Pick a user that already exists on the server. Need to create or remove accounts? Use the server\'s System users page.') }}</p>
                </div>
                <a href="{{ route('servers.system-users', $this->server) }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">
                    {{ __('Manage users on this server →') }}
                </a>
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

            @if (count($system_user_remote_rows) === 0)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 text-sm text-brand-moss">
                    {{ __('No regular Linux users on this server yet. Create one on the server\'s System users page, then come back to assign it here.') }}
                </div>
            @else
                <div class="max-w-md space-y-2">
                    <x-input-label for="system_user_assign_pick" :value="__('Select system user')" />
                    <select id="system_user_assign_pick" wire:model="system_user_assign_username" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                        <option value="">{{ __('Choose a user…') }}</option>
                        @foreach ($system_user_remote_rows as $row)
                            <option value="{{ $row['username'] }}">{{ $row['username'] }}</option>
                        @endforeach
                    </select>
                    <x-primary-button type="button" class="mt-2" wire:click="openSystemUserAssignModal">
                        {{ __('Apply selection…') }}
                    </x-primary-button>
                </div>
            @endif
        </div>
    @endif

    <x-cli-snippet tone="stub" />
</section>
