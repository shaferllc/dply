<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sage/15 text-brand-forest ring-brand-sage/25">
            <x-heroicon-o-user-circle class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Ownership') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('System user') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                {{ __('Linux account that owns this site\'s files and runs its PHP-FPM pool. Pick from accounts that already exist on the server; create new accounts on the server\'s System users page.') }}
            </p>
        </div>
    </div>

    <div class="space-y-8 p-6 sm:p-8">
    @if (! $this->shouldShowSystemUserPanel())
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
            {{ __('System user management is available for VM-backed PHP sites on this workspace. Use Runtime for container, serverless, and non-PHP targets.') }}
        </div>
    @else
        @php
            $op = is_array($site->meta ?? null) ? ($site->meta['system_user_operation'] ?? null) : null;
        @endphp
        @if (is_array($op) && ! empty($op['message']))
            @php $opFailed = ($op['status'] ?? '') === 'error'; @endphp
            <div class="rounded-xl border p-4 {{ $opFailed ? 'border-rose-200 bg-rose-50' : 'border-brand-ink/10 bg-brand-sand/15' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 {{ $opFailed ? 'bg-rose-100 text-rose-700 ring-rose-200' : 'bg-brand-sand/40 text-brand-forest ring-brand-ink/10' }}">
                            <x-dynamic-component :component="$opFailed ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-information-circle'" class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold {{ $opFailed ? 'text-rose-900' : 'text-brand-ink' }}">{{ $opFailed ? __('System user operation failed') : __('System user operation') }}</p>
                            <p class="mt-1 text-sm {{ $opFailed ? 'text-rose-800' : 'text-brand-moss' }}">{{ $op['message'] }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="dismissSystemUserOperationBanner" class="shrink-0 text-sm font-semibold text-brand-forest hover:text-brand-sage hover:underline">
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

        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:p-5">
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
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 p-4 text-sm text-brand-moss">
                    {{ __('No regular Linux users on this server yet. Create one on the server\'s System users page, then come back to assign it here.') }}
                </div>
            @else
                <div class="max-w-md space-y-2">
                    <x-input-label for="system_user_assign_pick" :value="__('Select system user')" />
                    <select id="system_user_assign_pick" wire:model="system_user_assign_username" class="mt-1 block w-full rounded-md border-brand-ink/15 shadow-sm text-sm">
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
    </div>

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
        <x-cli-snippet tone="stub" />
    </div>
</section>
