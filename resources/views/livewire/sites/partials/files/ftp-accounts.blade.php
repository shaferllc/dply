{{--
    FTP (SFTP) accounts for this site.

    "FTP" is the word customers search for; SFTP is what actually runs. Accounts
    are Linux users in group dply-sftp — which the sshd Match block pins to
    ForceCommand internal-sftp — granted this site's tree by POSIX ACL.

    dply never stores the password: it is shown once here, and otherwise reset.
--}}
@php
    $ftpHost = $server->ip_address ?: $server->name;
    $ftpPending = $ftpAccounts->contains(fn ($a) => $a->status === \App\Models\SftpAccount::STATUS_PENDING);

    // Built in PHP, never interpolated in the template: `@` immediately before
    // `{{` is Blade's own escape directive, so `{{ $user }}@{{ $host }}` emits
    // the literal braces instead of the host.
    $ftpUri = static fn (string $user): string => 'sftp://'.$user.'@'.$ftpHost.':22';
@endphp

<section class="dply-card mt-6 min-w-0 overflow-hidden p-0" id="ftp-accounts">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-arrow-up-tray"
        :title="__('FTP accounts')"
        :note="__('File-transfer logins scoped to this site. SFTP over port 22 — FileZilla, Cyberduck, Transmit. No shell access.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-spinner-button
                    size="xs"
                    variant="secondary"
                    type="button"
                    :icon="$ftpEnabled ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle'"
                    target="toggleSiteFtp"
                    wire:click="toggleSiteFtp"
                >{{ $ftpEnabled ? __('Disable FTP') : __('Enable FTP') }}</x-spinner-button>
                <x-spinner-button
                    size="xs"
                    variant="secondary"
                    type="button"
                    icon="heroicon-o-user-plus"
                    target="openFtpAdoptModal"
                    wire:click="openFtpAdoptModal"
                >{{ __('Use existing account') }}</x-spinner-button>
                <x-spinner-button
                    size="xs"
                    variant="primary"
                    type="button"
                    icon="heroicon-o-plus"
                    target="openFtpCreateModal"
                    wire:click="openFtpCreateModal"
                >{{ __('New account') }}</x-spinner-button>
            </div>
        </x-slot:actions>
    </x-workspace-panel-head>

    {{-- Shared console-action banner: live status, the steps the job streams,
         and the wire:poll that re-renders this component until the run goes
         terminal — which is what flips a row from "Provisioning…" to "Active". --}}
    @if ($ftpConsoleRun)
        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            @include('livewire.partials.console-action-banner-static', [
                'run' => $ftpConsoleRun,
                'kindLabels' => (array) config('console_actions.kinds', []),
            ])
        </div>
    @endif

    {{-- Reveal-once. Held in a protected property, so it is gone on the next
         interaction and never reaches the DOM snapshot or the database. --}}
    @if ($ftpRevealedPassword)
        <div class="border-b border-emerald-200/70 bg-emerald-50/50 px-5 py-5 sm:px-6">
            <div class="flex items-start gap-3">
                <x-icon-badge tone="emerald">
                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-emerald-900">{{ __('Copy this password now') }}</p>
                    <p class="mt-0.5 text-sm text-emerald-800/80">
                        {{ __('It is shown once and never stored. If it is lost, reset it — there is nothing to look up.') }}
                    </p>

                    <div class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                        <x-copy-value plain :label="__('Host')" :value="$ftpHost" />
                        <x-copy-value plain :label="__('Username')" :value="$ftpRevealedUsername" />
                        <x-copy-value plain :label="__('Password')" :value="$ftpRevealedPassword" />
                        <x-copy-value plain :label="__('Port')" value="22" />
                    </div>

                    {{-- The whole thing in one string: most clients accept a
                         pasted sftp:// URI and fill the fields themselves. --}}
                    <x-copy-value
                        plain
                        class="mt-3 max-w-xl"
                        :label="__('Connection string')"
                        :value="$ftpUri($ftpRevealedUsername)"
                    />
                </div>
            </div>
        </div>
    @endif

    {{-- Kill switch is on. The accounts below still exist — they are out of the
         group and their passwords are locked — so the list stays visible rather
         than implying the accounts were deleted. --}}
    @if (! $ftpEnabled)
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/30 px-5 py-4 sm:px-6">
            <x-icon-badge tone="brand">
                <x-heroicon-o-pause-circle class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 text-sm text-brand-moss">
                <p class="font-semibold text-brand-ink">{{ __('FTP is disabled for this site') }}</p>
                <p class="mt-0.5">
                    {{ __('The accounts below are kept along with their file access, but cannot log in. Re-enabling restores them without recreating anything.') }}
                </p>
            </div>
        </div>
    @endif

    {{-- Q7: under atomic deploys the live tree is a release directory that the
         next deploy replaces wholesale. Silent data loss unless we say so. --}}
    @if ($isAtomic)
        <div class="flex items-start gap-3 border-b border-amber-200/70 bg-amber-50/50 px-5 py-4 sm:px-6">
            <x-icon-badge tone="amber">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 text-sm text-brand-moss">
                <p class="font-semibold text-brand-ink">{{ __('This site uses atomic deploys') }}</p>
                <p class="mt-0.5">
                    {{ __('Anything uploaded under current/ lives in a release directory the next deploy replaces. Files that must survive a deploy belong in shared/.') }}
                </p>
            </div>
        </div>
    @endif

    {{-- Q11: sync-group sites are N Site rows on N boxes; SFTP writes to one. --}}
    @if ($ftpSyncGroupWarning)
        <div class="flex items-start gap-3 border-b border-amber-200/70 bg-amber-50/50 px-5 py-4 sm:px-6">
            <x-icon-badge tone="amber">
                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 text-sm text-brand-moss">
                <p class="font-semibold text-brand-ink">{{ __('This site is part of a sync group') }}</p>
                <p class="mt-0.5">
                    {{ __('Files uploaded here land on this server only. Deploy is how content reaches the other members.') }}
                </p>
            </div>
        </div>
    @endif

    {{-- Accounts --}}
    @if ($ftpAccounts->isEmpty())
        <div class="px-5 py-10 text-center sm:px-6">
            <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-brand-sand/50">
                <x-heroicon-o-arrow-up-tray class="h-5 w-5 text-brand-moss" aria-hidden="true" />
            </div>
            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No FTP accounts yet') }}</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-brand-moss">
                {{ __('Create a new file-transfer login, or give one of this server\'s existing Linux accounts access to this site.') }}
            </p>
        </div>
    @else
        {{-- One extra poll while anything is still provisioning, so a row
             reaches Active even if the console banner was dismissed. --}}
        @if ($ftpPending)
            <div wire:poll.5s="" class="hidden" aria-hidden="true"></div>
        @endif

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($ftpAccounts as $account)
                @php
                    $accountKeys = collect($ftpAccountKeys[$account->username] ?? []);
                    $tone = match ($account->status) {
                        \App\Models\SftpAccount::STATUS_ACTIVE => ['bg-emerald-50 text-emerald-800 ring-emerald-200', __('Active')],
                        \App\Models\SftpAccount::STATUS_ERROR => ['bg-rose-50 text-rose-800 ring-rose-200', __('Failed')],
                        default => ['bg-sky-50 text-sky-800 ring-sky-200', __('Provisioning')],
                    };
                @endphp
                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6" wire:key="ftp-{{ $account->id }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $account->username }}</p>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 {{ $tone[0] }}">
                                {{ $tone[1] }}
                            </span>
                            @if ($account->isAdopted())
                                <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ __('existing account') }}
                                </span>
                            @endif
                        </div>
                        @if ($account->status === \App\Models\SftpAccount::STATUS_ERROR)
                            <p class="mt-1 font-mono text-xs text-rose-700">{{ $account->last_error }}</p>
                        @else
                            <x-sftp-connection class="mt-1" :user="$account->username" :host="$ftpHost" />
                        @endif

                        {{-- Keys live in server_authorized_keys, keyed by
                             target_linux_user — so FTP accounts reuse the
                             synchronizer's fingerprint reconcile rather than
                             storing keys of their own. --}}
                        @if ($accountKeys->isNotEmpty())
                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($accountKeys as $key)
                                    <li class="inline-flex items-center gap-1.5 rounded-full bg-white px-2 py-0.5 text-2xs text-brand-moss ring-1 ring-brand-ink/10">
                                        <x-heroicon-m-key class="h-3 w-3" aria-hidden="true" />
                                        <span class="max-w-[16rem] truncate">{{ $key->name }}</span>
                                        <button type="button" class="font-semibold text-rose-600 hover:text-rose-800" wire:click="removeFtpKey('{{ $key->id }}')" title="{{ __('Remove this key') }}">&times;</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <x-spinner-button
                            size="xs"
                            variant="secondary"
                            type="button"
                            icon="heroicon-o-key"
                            target="openFtpKeyModal"
                            wire:click="openFtpKeyModal('{{ $account->id }}')"
                        >{{ __('SSH keys') }}@if ($accountKeys->isNotEmpty()) <span class="ml-1 rounded-full bg-brand-sand/60 px-1.5 text-2xs">{{ $accountKeys->count() }}</span>@endif</x-spinner-button>
                        <x-spinner-button
                            size="xs"
                            variant="secondary"
                            type="button"
                            target="resetFtpPassword"
                            wire:click="resetFtpPassword('{{ $account->id }}')"
                        >{{ __('Reset password') }}</x-spinner-button>
                        <x-spinner-button
                            size="xs"
                            variant="danger"
                            type="button"
                            target="confirmDeleteFtpAccount"
                            wire:click="confirmDeleteFtpAccount('{{ $account->id }}')"
                        >{{ __('Remove') }}</x-spinner-button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- The deploy user can never join dply-sftp: the Match block would force
         every dply SSH command into an SFTP session and break deploys. It
         already speaks SFTP over its SSH key, so we show that instead of a
         button we would have to refuse. --}}
    <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
        <div class="flex items-start gap-3">
            <x-icon-badge tone="brand">
                <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 text-sm text-brand-moss">
                <p class="font-semibold text-brand-ink">{{ __('Uploading as the deploy user') }}</p>
                <p class="mt-0.5">
                    {{ __('The :user account already supports SFTP using this server\'s SSH key — point your client at it with key auth, no password needed.', ['user' => $ftpDeployUser]) }}
                </p>
                <x-sftp-connection class="mt-2" :user="$ftpDeployUser" :host="$ftpHost" />

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($ftpDeployUserHasPassword)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                            {{ __('password login on') }}
                        </span>
                        <x-spinner-button size="xs" variant="secondary" type="button" target="setDeployUserFtpPassword" wire:click="setDeployUserFtpPassword">{{ __('Reset password') }}</x-spinner-button>
                        <x-spinner-button size="xs" variant="danger" type="button" target="clearDeployUserFtpPassword" wire:click="clearDeployUserFtpPassword">{{ __('Remove password login') }}</x-spinner-button>
                    @else
                        <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-key" target="setDeployUserFtpPassword" wire:click="setDeployUserFtpPassword">{{ __('Set a password for this account') }}</x-spinner-button>
                    @endif
                </div>

                <p class="mt-2 text-xs">
                    {{ __('A password here enables password login only — :user keeps its shell, so deploys are unaffected. It cannot be turned into a file-transfer-only account: that would force every dply command into an SFTP session.', ['user' => $ftpDeployUser]) }}
                </p>
                <p class="mt-1 text-xs">
                    {{ __('Weigh it up: this account can sudo, and a password makes it reachable by guessing rather than by key alone. Key auth needs no password at all.') }}
                </p>
            </div>
        </div>
    </div>
</section>

@if ($showFtpCreateModal)
    <x-modal name="site-ftp-create" :show="true" wire:model="showFtpCreateModal" max-width="lg">
        <div class="space-y-4 p-6">
            <div>
                <p class="text-sm font-semibold text-brand-ink">{{ __('New FTP account') }}</p>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('Creates a Linux account that can transfer files for this site only — no shell, no port forwarding. dply generates the password and shows it once.') }}
                </p>
            </div>

            <div>
                <x-input-label for="ftp_username" :value="__('Username')" />
                <x-text-input id="ftp_username" class="mt-1 block w-full font-mono" type="text" wire:model="ftp_username" autocomplete="off" placeholder="designer" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Lowercase letters, digits, underscore and dash. Must not already exist on this server.') }}</p>
            </div>

            @if ($ftp_error)
                <p class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200">{{ $ftp_error }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <x-secondary-button size="xs" type="button" wire:click="closeFtpCreateModal">{{ __('Cancel') }}</x-secondary-button>
                <x-spinner-button size="xs" variant="primary" type="button" target="createFtpAccount" wire:click="createFtpAccount">{{ __('Create account') }}</x-spinner-button>
            </div>
        </div>
    </x-modal>
@endif

@if ($showFtpAdoptModal)
    <x-modal name="site-ftp-adopt" :show="true" wire:model="showFtpAdoptModal" max-width="lg">
        <div class="space-y-4 p-6">
            <div>
                <p class="text-sm font-semibold text-brand-ink">{{ __('Give an existing account FTP access') }}</p>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('Adds the account to the file-transfer group and grants it this site\'s files. Removing access later leaves the account in place — only the grant is undone.') }}
                </p>
            </div>

            @if (empty($ftpAdoptable))
                <div class="rounded-md bg-brand-sand/40 px-3 py-3 text-sm text-brand-moss">
                    <p>{{ __('No eligible accounts found on this server. Every account is either already granted, or is the deploy user, which cannot be used.') }}</p>
                    <div class="mt-2">
                        <x-spinner-button
                            size="xs"
                            variant="secondary"
                            type="button"
                            icon="heroicon-o-arrow-path"
                            target="loadFtpAdoptableAccounts"
                            wire:click="loadFtpAdoptableAccounts"
                        >{{ __('Re-scan the server') }}</x-spinner-button>
                    </div>
                </div>
            @else
                <div>
                    <x-input-label for="ftp_adopt_username" :value="__('Account')" />
                    <select id="ftp_adopt_username" wire:model="ftp_adopt_username" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                        <option value="">{{ __('Select an account…') }}</option>
                        @foreach ($ftpAdoptable as $candidate)
                            <option value="{{ $candidate }}">{{ $candidate }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('The deploy user is not listed: forcing it into file-transfer-only sessions would break deploys.') }}
                    </p>
                </div>

                <p class="rounded-md bg-amber-50/70 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-200">
                    {{ __('This sets a new password on that Linux account, replacing any existing one.') }}
                </p>
            @endif

            @if ($ftp_error)
                <p class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200">{{ $ftp_error }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <x-secondary-button size="xs" type="button" wire:click="closeFtpAdoptModal">{{ __('Cancel') }}</x-secondary-button>
                <x-spinner-button size="xs" variant="primary" type="button" target="adoptFtpAccount" wire:click="adoptFtpAccount" :disabled="empty($ftpAdoptable)">{{ __('Grant access') }}</x-spinner-button>
            </div>
        </div>
    </x-modal>
@endif

@if ($ftp_key_account_id)
    <x-modal name="site-ftp-key" :show="true" max-width="lg">
        <div class="space-y-4 p-6">
            <div>
                <p class="text-sm font-semibold text-brand-ink">{{ __('Add an SSH key') }}</p>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('Lets this account connect with a key instead of the password. Both keep working — a client set up with a key simply never uses the password.') }}
                </p>
            </div>

            {{-- Your own saved keys first: picking one is the common case, and
                 pasting means a detour through a terminal. --}}
            @if ($ftpProfileKeys->isNotEmpty())
                <div>
                    <x-input-label for="ftp_key_profile_id" :value="__('Use one of your saved keys')" />
                    <select id="ftp_key_profile_id" wire:model.live="ftp_key_profile_id" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                        <option value="">{{ __('Paste a different key…') }}</option>
                        @foreach ($ftpProfileKeys as $profileKey)
                            <option value="{{ $profileKey->id }}">{{ $profileKey->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <x-input-label for="ftp_key_name" :value="__('Label')" />
                <x-text-input id="ftp_key_name" class="mt-1 block w-full" type="text" wire:model="ftp_key_name" autocomplete="off" placeholder="{{ __('Designer laptop') }}" />
                @if ($ftp_key_profile_id !== '')
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Optional — defaults to the saved key\'s own name.') }}</p>
                @endif
            </div>

            {{-- Hidden while a saved key is selected: two sources for one key is
                 how you end up granting the wrong one. --}}
            @if ($ftp_key_profile_id === '')
                <div>
                    <x-input-label for="ftp_key_public" :value="__('Public key')" />
                    <textarea id="ftp_key_public" rows="4" wire:model="ftp_key_public" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest" placeholder="ssh-ed25519 AAAAC3Nza..."></textarea>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('The public half only — never paste a private key.') }}</p>
                </div>
            @endif

            @if ($ftp_error)
                <p class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200">{{ $ftp_error }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <x-secondary-button size="xs" type="button" wire:click="closeFtpKeyModal">{{ __('Cancel') }}</x-secondary-button>
                <x-spinner-button size="xs" variant="primary" type="button" target="addFtpKey" wire:click="addFtpKey">{{ __('Add key') }}</x-spinner-button>
            </div>
        </div>
    </x-modal>
@endif

@if ($ftp_pending_delete_id)
    <x-modal name="site-ftp-delete" :show="true" max-width="lg">
        <div class="space-y-4 p-6">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Remove FTP access?') }}</p>
            <p class="text-sm text-brand-moss">
                {{ __('Access grants are removed from the site tree. Accounts dply created are deleted; accounts that already existed keep their Linux user and only lose file-transfer access. Site files are not touched.') }}
            </p>
            <div class="flex justify-end gap-2">
                <x-secondary-button size="xs" type="button" wire:click="cancelDeleteFtpAccount">{{ __('Cancel') }}</x-secondary-button>
                <x-spinner-button size="xs" variant="danger" type="button" target="deleteFtpAccount" wire:click="deleteFtpAccount">{{ __('Remove access') }}</x-spinner-button>
            </div>
        </div>
    </x-modal>
@endif
