@php
    /**
     * Parse an OpenSSH one-line public key into {type, body, comment}.
     * Returns null when the line doesn't look like an OpenSSH key.
     */
    $parsePub = function (?string $pub): ?array {
        if (! is_string($pub) || trim($pub) === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim($pub), 3);
        if (! is_array($parts) || count($parts) < 2) {
            return null;
        }

        return [
            'type' => $parts[0],
            'body' => $parts[1],
            'comment' => $parts[2] ?? null,
        ];
    };

    /** SHA-256 fingerprint in the OpenSSH `SHA256:base64url-no-padding` form. */
    $fingerprint = function (?string $pub) use ($parsePub): ?string {
        $parsed = $parsePub($pub);
        if ($parsed === null) {
            return null;
        }
        $bin = base64_decode($parsed['body'], true);
        if ($bin === false) {
            return null;
        }

        return 'SHA256:'.rtrim(strtr(base64_encode(hash('sha256', $bin, true)), '+/', '-_'), '=');
    };

    $typeLabels = [
        'ssh-ed25519' => __('Ed25519'),
        'ssh-rsa' => __('RSA'),
        'ecdsa-sha2-nistp256' => __('ECDSA P-256'),
        'ecdsa-sha2-nistp384' => __('ECDSA P-384'),
        'ecdsa-sha2-nistp521' => __('ECDSA P-521'),
        'sk-ssh-ed25519@openssh.com' => __('Ed25519 (security key)'),
    ];

    $serverPubInfo = $parsePub($serverPub ?? null);
    $serverPubFingerprint = $fingerprint($serverPub ?? null);

    $operationalPub = $server->openSshPublicKeyFromOperationalPrivate();
    $recoveryPub = $server->openSshPublicKeyFromRecoveryPrivate();
    $operationalFp = $fingerprint($operationalPub);
    $recoveryFp = $fingerprint($recoveryPub);

    $authorizedKeysCount = $server->authorizedKeys?->count() ?? 0;
    $repairAvailable = $server->isReady()
        && filled($server->ip_address)
        && $server->recoverySshPrivateKey() !== null
        && ($server->ssh_user ?? 'root') !== 'root';

    $gitHostLinks = [
        ['label' => 'GitHub', 'url' => 'https://github.com/settings/ssh/new'],
        ['label' => 'GitLab', 'url' => 'https://gitlab.com/-/user_settings/ssh_keys'],
        ['label' => 'Bitbucket', 'url' => 'https://bitbucket.org/account/settings/ssh-keys/'],
    ];
@endphp

<section id="settings-group-keys" class="space-y-6" aria-labelledby="settings-group-keys-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-keys-title',
        'kicker' => __('Access'),
        'title' => __('SSH keys for this server'),
        'description' => __('Two separate concerns: the public key this server uses for outbound connections (Git pulls, scripts), and the private keys Dply uses to SSH in. Private keys are encrypted at rest and never displayed.'),
    ])

    {{-- Outbound: provisioned key for Git/scripts --}}
    <div id="settings-keys-outbound" class="{{ $card }} scroll-mt-24 p-6 sm:p-8" x-data="{ copied: false, copiedFp: false }">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Outbound key (Git & scripts)') }}</h3>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                    {{ __('Add this public key on Git hosts or other services that should trust outbound connections from this server. The matching private key never leaves Dply in plain form.') }}
                </p>
            </div>
            @if ($serverPubInfo)
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <span class="inline-flex items-center rounded-full border border-brand-ink/15 bg-white px-2 py-0.5 font-medium text-brand-ink">
                        {{ $typeLabels[$serverPubInfo['type']] ?? $serverPubInfo['type'] }}
                    </span>
                    @if ($serverPubInfo['comment'])
                        <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/30 px-2 py-0.5 font-mono text-[11px] text-brand-moss">
                            {{ $serverPubInfo['comment'] }}
                        </span>
                    @endif
                </div>
            @endif
        </div>

        @if ($serverPub)
            <div class="mt-6 space-y-4">
                <div>
                    <x-input-label value="{{ __('Public key (OpenSSH)') }}" />
                    <div class="mt-1 flex gap-2">
                        <textarea
                            readonly
                            rows="3"
                            aria-label="{{ __('Public key') }}"
                            class="min-h-[5rem] flex-1 resize-y rounded-lg border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink"
                        >{{ $serverPub }}</textarea>
                        <button
                            type="button"
                            class="h-10 shrink-0 rounded-lg border border-brand-ink/15 bg-white px-3 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                            x-on:click="navigator.clipboard.writeText(@js($serverPub)); copied = true; setTimeout(() => copied = false, 2000)"
                        >
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </button>
                    </div>
                </div>

                @if ($serverPubFingerprint)
                    <div>
                        <x-input-label value="{{ __('Fingerprint (SHA-256)') }}" />
                        <div class="mt-1 flex items-center gap-2">
                            <code class="flex-1 truncate rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 font-mono text-xs text-brand-ink">{{ $serverPubFingerprint }}</code>
                            <button
                                type="button"
                                class="h-10 shrink-0 rounded-lg border border-brand-ink/15 bg-white px-3 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                x-on:click="navigator.clipboard.writeText(@js($serverPubFingerprint)); copiedFp = true; setTimeout(() => copiedFp = false, 2000)"
                            >
                                <span x-show="!copiedFp">{{ __('Copy') }}</span>
                                <span x-show="copiedFp" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Compare with the key shown when you authorize the server on a Git host.') }}</p>
                    </div>
                @endif

                <div>
                    <x-input-label value="{{ __('Add to a Git host') }}" />
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ($gitHostLinks as $link)
                            <a
                                href="{{ $link['url'] }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ $link['label'] }}
                                <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3 opacity-70">
                                    <path fill-rule="evenodd" d="M5.22 14.78a.75.75 0 0 0 1.06 0l7.22-7.22v3.69a.75.75 0 0 0 1.5 0v-5.5a.75.75 0 0 0-.75-.75h-5.5a.75.75 0 0 0 0 1.5h3.69l-7.22 7.22a.75.75 0 0 0 0 1.06Z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Opens the host’s SSH-key settings page in a new tab. Paste the public key above.') }}</p>
                </div>
            </div>
        @else
            <p class="mt-6 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm text-brand-moss">
                {{ __('No provisioned key is available yet — SSH may still be provisioning. Refresh this page after the server is ready.') }}
            </p>
        @endif
    </div>

    {{-- Inbound: how Dply connects in --}}
    <div id="settings-keys-inbound" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('How Dply connects in') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Dply stores two encrypted private keys for this server. Neither is downloadable. Public-key fingerprints are shown so you can verify them against your server’s authorized_keys.') }}
        </p>

        <dl class="mt-6 grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-sm font-semibold text-brand-ink">{{ __('Operational key') }}</dt>
                    @if ($operationalPub)
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[11px] font-medium text-brand-forest">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ __('Stored') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-medium text-red-800">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-red-600"></span>
                            {{ __('Missing') }}
                        </span>
                    @endif
                </div>
                <dd class="mt-1 text-xs text-brand-moss">{{ __('Used as :user for deploys and Manage actions.', ['user' => $server->ssh_user ?: 'deploy']) }}</dd>
                @if ($operationalFp)
                    <code class="mt-3 block truncate rounded-md border border-brand-ink/10 bg-brand-sand/20 px-2 py-1 font-mono text-[11px] text-brand-ink" title="{{ $operationalFp }}">{{ $operationalFp }}</code>
                @endif
            </div>

            <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-sm font-semibold text-brand-ink">{{ __('Recovery key (root)') }}</dt>
                    @if ($recoveryPub)
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[11px] font-medium text-brand-forest">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ __('Stored') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-ink/10 px-2 py-0.5 text-[11px] font-medium text-brand-moss">
                            {{ __('Not configured') }}
                        </span>
                    @endif
                </div>
                <dd class="mt-1 text-xs text-brand-moss">{{ __('Hidden break-glass key used only when the operational key fails.') }}</dd>
                @if ($recoveryFp)
                    <code class="mt-3 block truncate rounded-md border border-brand-ink/10 bg-brand-sand/20 px-2 py-1 font-mono text-[11px] text-brand-ink" title="{{ $recoveryFp }}">{{ $recoveryFp }}</code>
                @endif
            </div>
        </dl>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
            <div class="text-sm">
                <p class="font-medium text-brand-ink">
                    {{ trans_choice(':n authorized key on this server|:n authorized keys on this server', $authorizedKeysCount, ['n' => $authorizedKeysCount]) }}
                </p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Personal and team keys synced to authorized_keys. Manage who else can SSH in from the Keys page.') }}</p>
            </div>
            <a
                href="{{ route('servers.ssh-keys', $server) }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
            >
                {{ __('Manage authorized_keys') }}
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3 opacity-70">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
                </svg>
            </a>
        </div>

        @if ($repairAvailable)
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <div class="text-sm text-amber-900">
                    <p class="font-medium">{{ __('Operational key locked out?') }}</p>
                    <p class="mt-0.5 text-xs">{{ __('Reinstall the operational key for :user using the recovery root key.', ['user' => $server->ssh_user]) }}</p>
                </div>
                <button
                    type="button"
                    wire:click="repairSshAccess"
                    wire:loading.attr="disabled"
                    wire:target="repairSshAccess"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="repairSshAccess">{{ __('Repair access') }}</span>
                    <span wire:loading wire:target="repairSshAccess" class="inline-flex items-center gap-1.5">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Repairing…') }}
                    </span>
                </button>
            </div>
        @endif
    </div>
</section>
