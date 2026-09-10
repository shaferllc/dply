{{--
    Users: find, create, edit, delete (with content reassignment), change role,
    reset passwords and sign people out. Every change runs as a queued wp-cli
    command; generated passwords travel as RemoteCli secrets, so the Console
    history and audit log only ever see `--user_pass=[redacted]`.
--}}
@php
    $roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
    $inputCls = 'mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';
    $labelCls = 'block text-xs font-medium text-brand-moss';
    $visibleUsers = $this->filteredUsers();
    $deleting = $deletingUserId !== null ? collect($users)->firstWhere('id', $deletingUserId) : null;
@endphp
<div>
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Users') }}</h3>
            <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Live list from `wp user list`. Changes queue and apply in the background — refresh to see them.') }}</p>
        </div>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            @if ($canMutate && ! $showCreateUser)
                <button type="button" wire:click="$set('showCreateUser', true)" class="inline-flex items-center gap-1.5 rounded-md bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                    <x-heroicon-o-user-plus class="h-4 w-4" aria-hidden="true" />
                    {{ __('Add user') }}
                </button>
            @endif
            @if ($usersLoaded)
                <button type="button" wire:click="loadUsers" wire:loading.attr="disabled" wire:target="loadUsers" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                    <span wire:loading.remove wire:target="loadUsers" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                        {{ __('Refresh') }}
                    </span>
                    <span wire:loading wire:target="loadUsers" class="inline-flex items-center gap-1.5">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Refreshing…') }}
                    </span>
                </button>
            @endif
        </div>
    </div>

    @error('users')
        <p class="border-b border-rose-200/70 bg-rose-50/60 px-3 py-2 text-xs text-rose-700 sm:px-4">{{ $message }}</p>
    @enderror

    @if ($this->revealedUserPassword() && $resettingPasswordLogin === null)
        <div class="border-b border-emerald-200/70 bg-emerald-50/50 px-3 py-3 sm:px-4">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-800">{{ __('Copy this now') }}</p>
            <p class="mt-0.5 text-xs text-emerald-800/80">{{ __('Shown once and never stored. It takes effect when the queued command finishes — if that fails, this password was never set.') }}</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <x-copy-value plain :label="__('Login')" :value="$this->revealedUserLogin()" />
                <x-copy-value plain :label="__('Password')" :value="$this->revealedUserPassword()" />
            </div>
        </div>
    @endif

    {{-- Create --}}
    @if ($canMutate && $showCreateUser)
        <form wire:submit="createUser" class="space-y-3 border-b border-brand-ink/10 bg-white px-3 py-3 sm:px-4">
            <p class="text-sm font-semibold text-brand-ink">{{ __('New user') }}</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="wp_new_login" class="{{ $labelCls }}">{{ __('Username') }}</label>
                    <input id="wp_new_login" type="text" wire:model="newUserLogin" autocomplete="off" class="{{ $inputCls }} font-mono" />
                </div>
                <div>
                    <label for="wp_new_email" class="{{ $labelCls }}">{{ __('Email') }}</label>
                    <input id="wp_new_email" type="email" wire:model="newUserEmail" autocomplete="off" class="{{ $inputCls }}" />
                </div>
                <div>
                    <label for="wp_new_name" class="{{ $labelCls }}">{{ __('Display name (optional)') }}</label>
                    <input id="wp_new_name" type="text" wire:model="newUserDisplayName" class="{{ $inputCls }}" />
                </div>
                <div>
                    <label for="wp_new_role" class="{{ $labelCls }}">{{ __('Role') }}</label>
                    <select id="wp_new_role" wire:model="newUserRole" class="{{ $inputCls }}">
                        @foreach ($roles as $roleOption)
                            <option value="{{ $roleOption }}">{{ ucfirst($roleOption) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <label class="inline-flex items-center gap-1.5 text-xs text-brand-ink">
                    <input type="checkbox" wire:model="newUserSendEmail" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                    {{ __('Also email them from WordPress (needs working site mail)') }}
                </label>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="$set('showCreateUser', false)" class="text-xs text-brand-moss underline hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <x-spinner-button size="xs" variant="primary" type="submit" icon="heroicon-o-user-plus" target="createUser">{{ __('Create user') }}</x-spinner-button>
                </div>
            </div>
            <p class="text-2xs text-brand-mist">{{ __('dply generates a strong password and shows it once.') }}</p>
        </form>
    @endif

    {{-- Delete: the strip is the confirmation, because it has to ask who gets the content. --}}
    @if ($deleting && $canDestroy)
        <div class="flex flex-wrap items-end gap-3 border-b border-rose-200 bg-rose-50/60 px-3 py-3 sm:px-4">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-rose-900">{{ __('Delete :login?', ['login' => $deleting['login']]) }}</p>
                <p class="mt-0.5 text-xs text-rose-800/80">{{ __('The account is removed for good. Their posts and pages are kept and handed to someone else.') }}</p>
            </div>
            <div>
                <label for="wp_reassign" class="block text-xs font-medium text-rose-900">{{ __('Give their content to') }}</label>
                <select id="wp_reassign" wire:model="deleteReassignTo" class="mt-1 rounded-md border-rose-200 py-1 text-xs shadow-sm focus:border-rose-400 focus:ring-rose-400">
                    @foreach ($users as $heir)
                        @continue($heir['id'] === $deleting['id'])
                        <option value="{{ $heir['id'] }}">{{ $heir['login'] }} ({{ $heir['roles'] ?: '—' }})</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="cancelDeleteUser" class="text-xs text-rose-800 underline">{{ __('Cancel') }}</button>
                <x-spinner-button size="xs" variant="danger" type="button" icon="heroicon-o-trash" target="deleteUser" wire:click="deleteUser">{{ __('Delete user') }}</x-spinner-button>
            </div>
        </div>
    @endif

    @if (! $usersLoaded)
        <div wire:init="loadUsers" class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Loading users…') }}
        </div>
    @elseif (empty($users))
        <p class="px-6 py-8 text-sm text-brand-moss">{{ __('No users reported.') }}</p>
    @else
        {{-- Filter the loaded list — no round trip to the server. --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <div class="relative min-w-0 flex-1 sm:max-w-xs">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                <input type="search" wire:model.live.debounce.250ms="userFilter" aria-label="{{ __('Search users') }}" placeholder="{{ __('Search name, username or email') }}" class="block w-full rounded-md border border-brand-ink/15 bg-white py-1.5 pl-8 pr-2.5 text-xs shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest" />
            </div>
            <select wire:model.live="userRoleFilter" aria-label="{{ __('Filter by role') }}" class="rounded-md border-brand-ink/15 py-1.5 text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                <option value="">{{ __('All roles') }}</option>
                @foreach ($roles as $roleOption)
                    <option value="{{ $roleOption }}">{{ ucfirst($roleOption) }}</option>
                @endforeach
            </select>
            <span class="text-2xs text-brand-mist">{{ __(':shown of :total', ['shown' => count($visibleUsers), 'total' => count($users)]) }}</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3 sm:px-6">{{ __('User') }}</th>
                        <th class="px-4 py-3">{{ __('Email') }}</th>
                        <th class="px-4 py-3">{{ __('Roles') }}</th>
                        <th class="px-4 py-3 text-right sm:px-6">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @forelse ($visibleUsers as $wpUser)
                        @if ($editingUserId === $wpUser['id'])
                            <tr wire:key="wp-user-edit-{{ $wpUser['id'] }}" class="bg-brand-sand/20">
                                <td colspan="4" class="px-4 py-3 sm:px-6">
                                    <form wire:submit="saveUser" class="flex flex-wrap items-end gap-3">
                                        <p class="w-full font-mono text-xs text-brand-ink sm:w-auto sm:self-center">{{ $wpUser['login'] }}</p>
                                        <div class="min-w-0 flex-1">
                                            <label for="wp_edit_name" class="{{ $labelCls }}">{{ __('Display name') }}</label>
                                            <input id="wp_edit_name" type="text" wire:model="editUserDisplayName" class="{{ $inputCls }}" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <label for="wp_edit_email" class="{{ $labelCls }}">{{ __('Email') }}</label>
                                            <input id="wp_edit_email" type="email" wire:model="editUserEmail" class="{{ $inputCls }}" />
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button type="button" wire:click="cancelEditUser" class="text-xs text-brand-moss underline hover:text-brand-ink">{{ __('Cancel') }}</button>
                                            <x-spinner-button size="xs" variant="primary" type="submit" target="saveUser">{{ __('Save') }}</x-spinner-button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @elseif ($resettingPasswordLogin === $wpUser['login'])
                            <tr wire:key="wp-user-pass-{{ $wpUser['id'] }}" class="bg-brand-sand/20">
                                <td colspan="4" class="px-4 py-3 sm:px-6">
                                    @if ($this->revealedUserPassword() && $this->revealedUserLogin() === $wpUser['login'])
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div class="min-w-0 flex-1">
                                                <p class="text-xs font-semibold text-emerald-800">{{ __('New password for :login — copy it now', ['login' => $wpUser['login']]) }}</p>
                                                <p class="mt-0.5 text-xs text-emerald-800/80">{{ __('Shown once and never stored. It takes effect when the queued command finishes — if that fails, this password was never set.') }}</p>
                                                <div class="mt-2">
                                                    <x-copy-value plain :label="__('Password')" :value="$this->revealedUserPassword()" />
                                                </div>
                                            </div>
                                            <button type="button" wire:click="cancelResetUserPassword" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Done') }}</button>
                                        </div>
                                    @else
                                        <form wire:submit="resetUserPassword(@js($wpUser['login']))" class="flex flex-wrap items-end gap-3">
                                            <p class="w-full font-mono text-xs text-brand-ink sm:w-auto sm:self-center">{{ $wpUser['login'] }}</p>
                                            <div class="min-w-0 flex-1">
                                                <label for="wp_reset_pass" class="{{ $labelCls }}">{{ __('New password') }}</label>
                                                <input id="wp_reset_pass" type="password" autocomplete="new-password" wire:model="resetPasswordValue" placeholder="{{ __('Leave blank to generate one') }}" class="{{ $inputCls }}" />
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button type="button" wire:click="cancelResetUserPassword" class="text-xs text-brand-moss underline hover:text-brand-ink">{{ __('Cancel') }}</button>
                                                <x-spinner-button size="xs" variant="primary" type="submit" target="resetUserPassword">{{ __('Set password') }}</x-spinner-button>
                                            </div>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @else
                            <tr wire:key="wp-user-{{ $wpUser['id'] }}">
                                <td class="px-4 py-3 sm:px-6">
                                    <p class="text-sm font-medium text-brand-ink">{{ $wpUser['name'] ?: $wpUser['login'] }}</p>
                                    <p class="font-mono text-2xs text-brand-mist">{{ $wpUser['login'] }} · #{{ $wpUser['id'] }}</p>
                                </td>
                                <td class="max-w-[14rem] truncate px-4 py-3 text-brand-moss" title="{{ $wpUser['email'] }}">{{ $wpUser['email'] ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @forelse (array_filter(array_map('trim', explode(',', $wpUser['roles']))) as $role)
                                            <span class="rounded-full bg-brand-ink/[0.05] px-2 py-0.5 text-2xs font-medium text-brand-moss">{{ $role }}</span>
                                        @empty
                                            <span class="text-brand-mist">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right sm:px-6">
                                    @if ($canMutate)
                                        <div class="inline-flex flex-wrap items-center justify-end gap-1.5" wire:loading.class="opacity-50">
                                            <select
                                                class="rounded-md border-brand-ink/15 py-1 text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                wire:change="changeUserRole(@js($wpUser['login']), $event.target.value)"
                                                aria-label="{{ __('Change role for :login', ['login' => $wpUser['login']]) }}"
                                            >
                                                <option value="">{{ __('Role…') }}</option>
                                                @foreach ($roles as $roleOption)
                                                    <option value="{{ $roleOption }}">{{ $roleOption }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="startEditUser(@js($wpUser['id']))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Edit') }}</button>
                                            <button type="button" wire:click="startResetUserPassword(@js($wpUser['login']))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Reset password') }}</button>
                                            {{-- Admin/owner: session destroy and delete run at the Destructive tier. --}}
                                            @if ($canDestroy)
                                                <button type="button" wire:click="logoutUserEverywhere(@js($wpUser['id']))" title="{{ __('Ends every login session for this user') }}" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Sign out everywhere') }}</button>
                                                <button type="button" wire:click="startDeleteUser(@js($wpUser['id']))" class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100">{{ __('Delete') }}</button>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-xs text-brand-mist">{{ __('Read-only') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-6 text-sm text-brand-moss">{{ __('No users match.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
