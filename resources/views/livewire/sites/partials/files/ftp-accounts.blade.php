{{--
    FTP (SFTP) accounts for this site.

    "FTP" is the word customers search for; SFTP is what actually runs. Accounts
    are shell-less Linux users in group dply-sftp, granted this site's tree by
    POSIX ACL. dply never stores the password — it is shown once here and then
    only resettable.
--}}
<section class="dply-card min-w-0 overflow-hidden p-0" id="ftp-accounts">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-arrow-up-tray"
        :title="__('FTP accounts')"
        :note="__('File-transfer logins scoped to this site. SFTP on port 22 — works with FileZilla, Cyberduck and Transmit. No shell access.')"
        class="border-b border-brand-ink/10"
    />

    {{-- Reveal-once. Held in a protected property, so it is gone on the next
         interaction and never reaches the DOM snapshot or the database. --}}
    @if ($ftpRevealedPassword)
        <div class="border-b border-emerald-200/80 bg-emerald-50/60 px-5 py-4 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Copy this now') }}</p>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('This password is shown once and is not stored. If it is lost, reset it.') }}
            </p>
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-brand-moss">{{ __('Host') }}</dt>
                    <dd class="font-mono text-brand-ink">{{ $server->ip_address ?: $server->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-brand-moss">{{ __('Username') }}</dt>
                    <dd class="font-mono text-brand-ink">{{ $ftpRevealedUsername }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-brand-moss">{{ __('Password') }}</dt>
                    <dd class="font-mono break-all text-brand-ink">{{ $ftpRevealedPassword }}</dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-brand-moss">{{ __('Protocol: SFTP · Port 22') }}</p>
        </div>
    @endif

    {{-- Q7: under atomic deploys the live tree is a release directory that the
         next deploy replaces wholesale. Silent data loss unless we say so. --}}
    @if ($isAtomic)
        <div class="flex items-start gap-3 border-b border-amber-200/80 bg-amber-50/60 px-5 py-4 sm:px-6">
            <x-icon-badge tone="amber">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 text-sm text-brand-moss">
                <p class="font-semibold text-brand-ink">{{ __('This site uses atomic deploys') }}</p>
                <p class="mt-1">
                    {{ __('Anything uploaded under current/ lives in a release directory that the next deploy replaces. Files that must survive a deploy belong in shared/.') }}
                </p>
            </div>
        </div>
    @endif

    {{-- Q11: sync-group sites are N Site rows on N boxes; SFTP writes to one. --}}
    @if ($ftpSyncGroupWarning)
        <div class="flex items-start gap-3 border-b border-amber-200/80 bg-amber-50/60 px-5 py-4 sm:px-6">
            <x-icon-badge tone="amber">
                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 text-sm text-brand-moss">
                <p class="font-semibold text-brand-ink">{{ __('This site is part of a sync group') }}</p>
                <p class="mt-1">
                    {{ __('Files uploaded here land on this server only. Deploy is how content reaches the other members.') }}
                </p>
            </div>
        </div>
    @endif

    <div class="px-5 py-4 sm:px-6">
        @if ($ftpAccounts->isEmpty())
            <p class="text-sm text-brand-moss">{{ __('No FTP accounts for this site yet.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($ftpAccounts as $account)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-brand-ink">{{ $account->username }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                @if ($account->status === \App\Models\SftpAccount::STATUS_ACTIVE)
                                    {{ __('Active · sftp to :host', ['host' => $server->ip_address ?: $server->name]) }}
                                @elseif ($account->status === \App\Models\SftpAccount::STATUS_ERROR)
                                    <span class="text-rose-700">{{ $account->last_error ?: __('Failed') }}</span>
                                @else
                                    {{ __('Provisioning…') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
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

        <div class="mt-4">
            <x-spinner-button
                size="xs"
                variant="primary"
                type="button"
                icon="heroicon-o-plus"
                target="openFtpCreateModal"
                wire:click="openFtpCreateModal"
            >{{ __('Add FTP account') }}</x-spinner-button>
        </div>
    </div>
</section>

@if ($showFtpCreateModal)
    <x-modal name="site-ftp-create" :show="true" wire:model="showFtpCreateModal" max-width="lg">
        <div class="space-y-4 p-6">
            <div>
                <p class="text-sm font-semibold text-brand-ink">{{ __('Add FTP account') }}</p>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('Creates a Linux account that can transfer files for this site only. No shell, no port forwarding. dply generates the password and shows it once.') }}
                </p>
            </div>

            <div>
                <x-input-label for="ftp_username" :value="__('Username')" />
                <x-text-input id="ftp_username" class="mt-1 block w-full font-mono" type="text" wire:model="ftp_username" autocomplete="off" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Lowercase letters, digits, underscore and dash. Must not already exist on this server.') }}</p>
            </div>

            @if ($ftp_error)
                <p class="text-sm text-rose-700">{{ $ftp_error }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <x-secondary-button size="xs" type="button" wire:click="closeFtpCreateModal">{{ __('Cancel') }}</x-secondary-button>
                <x-spinner-button size="xs" variant="primary" type="button" target="createFtpAccount" wire:click="createFtpAccount">{{ __('Create account') }}</x-spinner-button>
            </div>
        </div>
    </x-modal>
@endif

@if ($ftp_pending_delete_id)
    <x-modal name="site-ftp-delete" :show="true" max-width="lg">
        <div class="space-y-4 p-6">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Remove FTP account?') }}</p>
            <p class="text-sm text-brand-moss">
                {{ __('The Linux account, its access grants and its home directory are removed from the server. Site files are not touched.') }}
            </p>
            <div class="flex justify-end gap-2">
                <x-secondary-button size="xs" type="button" wire:click="cancelDeleteFtpAccount">{{ __('Cancel') }}</x-secondary-button>
                <x-spinner-button size="xs" variant="danger" type="button" target="deleteFtpAccount" wire:click="deleteFtpAccount">{{ __('Remove account') }}</x-spinner-button>
            </div>
        </div>
    </x-modal>
@endif
