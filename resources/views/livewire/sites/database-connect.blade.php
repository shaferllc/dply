@php
    $modalName = 'database-connect-'.$bindingId;
    $labelCls = 'text-2xs font-semibold uppercase tracking-wide text-brand-moss';
    $valueCls = 'mt-0.5 truncate font-mono text-xs text-brand-ink';
@endphp

<div>
    <x-secondary-button size="xs" type="button" wire:click="openConnect">
        <x-heroicon-o-bolt class="h-4 w-4" />
        {{ __('Connect') }}
    </x-secondary-button>

    <x-modal :name="$modalName" max-width="2xl" focusable>
        <div class="p-6">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Connect to this database') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __('Use these details with TablePlus, DBeaver, DataGrip, psql — any client.') }}
                    </p>
                </div>
                <button type="button" wire:click="closeConnect" x-on:click="$dispatch('close-modal', '{{ $modalName }}')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            @if (! $target)
                <div class="mt-4 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
                    {{ __('This database has no connection details yet. If it is still provisioning, they appear once the provider reports a host.') }}
                </div>
            @else
                {{-- Connection facts. No password: it only ever travels through the one-time credential link. --}}
                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-4 sm:grid-cols-3">
                    <div class="min-w-0 col-span-2 sm:col-span-3">
                        <dt class="{{ $labelCls }}">{{ __('Host') }}</dt>
                        <dd class="{{ $valueCls }}">{{ $target->host }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $labelCls }}">{{ __('Port') }}</dt>
                        <dd class="{{ $valueCls }}">{{ $target->port }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $labelCls }}">{{ __('Database') }}</dt>
                        <dd class="{{ $valueCls }}">{{ $target->database ?: '—' }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="{{ $labelCls }}">{{ __('User') }}</dt>
                        <dd class="{{ $valueCls }}">{{ $target->username ?: '—' }}</dd>
                    </div>
                    <div class="min-w-0 col-span-2 sm:col-span-3">
                        <dt class="{{ $labelCls }}">{{ __('TLS') }}</dt>
                        <dd class="{{ $valueCls }}">{{ $target->sslMode ?? __('not enforced') }}</dd>
                    </div>
                </dl>

                @if ($isProduction)
                    <div class="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2.5 text-xs text-amber-900">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            {{ __('You are connecting to production as :user, which can DROP and TRUNCATE. Changes are immediate.', ['user' => $target->username ?: __('the admin user')]) }}
                            @if ($target->kind === \App\Support\Servers\DatabaseConnectionTarget::KIND_MANAGED)
                                {{ __('Your provider takes automated backups of this cluster; restores are performed from their console.') }}
                            @endif
                        </span>
                    </div>
                @endif

                {{-- Tunnel --}}
                <div class="mt-5 border-t border-brand-ink/10 pt-4">
                    <h4 class="text-sm font-semibold text-brand-ink">{{ __('Connect over an SSH tunnel') }}</h4>

                    @if ($unavailableReason)
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            @switch($unavailableReason)
                                @case(\App\Support\Servers\DatabaseConnectionTargetResolver::REASON_PROVIDER_PUBLIC)
                                    {{ __('This provider exposes the database over the public internet with TLS, so no tunnel is needed — connect directly with the details above.') }}
                                    @break
                                @case(\App\Support\Servers\DatabaseConnectionTargetResolver::REASON_SERVER_NOT_SSHABLE)
                                    {{ __('This site has no dply server to tunnel through, so a direct connection is required.') }}
                                    @break
                                @case(\App\Support\Servers\DatabaseConnectionTargetResolver::REASON_SERVER_NOT_READY)
                                    {{ __('The site’s server is not ready for SSH yet, so a tunnel cannot be built.') }}
                                    @break
                                @default
                                    {{ __('A tunnel is not available for this database.') }}
                            @endswitch
                        </p>
                    @else
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('The site’s server is already trusted by this database, so forwarding through it needs no firewall change. Leave the tunnel running while you work.') }}
                        </p>

                        <div class="mt-3 flex items-center gap-2">
                            <label for="connect-local-port-{{ $bindingId }}" class="{{ $labelCls }}">{{ __('Local port') }}</label>
                            <input
                                id="connect-local-port-{{ $bindingId }}"
                                type="number"
                                min="1024"
                                max="65535"
                                wire:model.live.debounce.400ms="localPort"
                                class="w-28 rounded-lg border-brand-ink/15 bg-white font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage"
                            />
                            <span class="text-xs text-brand-moss">{{ __('change if something already uses it') }}</span>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <label for="connect-ssh-key-{{ $bindingId }}" class="{{ $labelCls }}">{{ __('SSH key') }}</label>
                            <input
                                id="connect-ssh-key-{{ $bindingId }}"
                                type="text"
                                wire:model.live.debounce.400ms="sshKeyPath"
                                class="w-64 rounded-lg border-brand-ink/15 bg-white font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage"
                            />
                            <span class="text-xs text-brand-moss">{{ __('pinned with -i so ssh does not exhaust MaxAuthTries') }}</span>
                        </div>

                        @if ($tunnelInstallCommand)
                            <div class="mt-3 rounded-lg border border-brand-sage/30 bg-brand-sage/5 p-3">
                                <p class="{{ $labelCls }}">{{ __('Recommended · set up once') }}</p>
                                <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                    {{ __('Installs a key that can only forward to this database — it cannot open a shell — plus an SSH config entry, so you never need to name a key file again.') }}
                                </p>
                                <div class="mt-2">
                                    <x-cli-snippet :command="$tunnelInstallCommand" :summary="__('Run once:')" />
                                </div>
                                @if ($tunnelAlias)
                                    <p class="mt-2 text-xs text-brand-moss">
                                        {{ __('Already installed. Open the tunnel with:') }}
                                    </p>
                                    <div class="mt-1">
                                        <x-cli-snippet :command="'ssh -f -N -L '.$localPort.':'.$target->host.':'.$target->port.' '.$tunnelAlias" :summary="__('Run:')" />
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($launchCommand)
                            <div class="mt-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3">
                                <p class="{{ $labelCls }}">{{ __('Or use your own key') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('One command: backgrounds the tunnel, then opens the connection.') }}</p>
                                <div class="mt-2">
                                    <x-cli-snippet :command="$launchCommand" :summary="__('Run:')" />
                                </div>
                            </div>
                        @endif

                        @if ($tunnelLink)
                            <p class="mt-3 text-xs text-brand-moss">
                                {{ __('Only once the tunnel above is running:') }}
                                <a href="{{ $tunnelLink }}" target="_blank" rel="noopener" class="font-semibold text-brand-ink underline decoration-brand-sage underline-offset-2 hover:text-brand-forest">
                                    {{ __('Open in TablePlus') }}
                                </a>
                            </p>
                        @endif

                        <div class="mt-3">
                            <x-cli-snippet :commands="[
                                ['label' => __('Tunnel only'), 'command' => $tunnel['tunnel']],
                                ['label' => __('Connection URI'), 'command' => $tunnel['uri']],
                                ['label' => __('Terminal client'), 'command' => $tunnel['connect']],
                            ]" :summary="__('Step by step')" />
                        </div>
                    @endif
                </div>

                @if ($directLink)
                    <div class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-brand-sage/30 bg-brand-sage/5 px-4 py-3">
                        <a href="{{ $directLink }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-forest/90">
                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                            {{ __('Open in TablePlus') }}
                        </a>
                        <span class="text-xs text-brand-moss">{{ __('Opens with credentials filled in. Any client registered for this database type will answer.') }}</span>
                    </div>
                @endif

                {{-- Direct access --}}
                @if ($canAllowIp || $allowance)
                    <div class="mt-5 border-t border-brand-ink/10 pt-4">
                        <h4 class="text-sm font-semibold text-brand-ink">{{ __('Direct access') }}</h4>

                        @if ($allowance)
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5">
                                <p class="text-xs text-brand-moss">
                                    <span class="font-mono text-brand-ink">{{ $allowance->ip_address }}</span>
                                    {{ __('allowed · expires :when', ['when' => $allowance->expires_at->diffForHumans()]) }}
                                </p>
                                <x-secondary-button size="xs" type="button" wire:click="revokeMyIp" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="revokeMyIp">{{ __('Revoke now') }}</span>
                                    <span wire:loading wire:target="revokeMyIp">{{ __('Revoking…') }}</span>
                                </x-secondary-button>
                            </div>
                        @elseif ($canAllowIp)
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Temporarily add your address to this cluster’s allowlist, then connect straight to it — no tunnel and no terminal. It expires on its own, and existing rules are left untouched.') }}
                            </p>
                            @if ($allowAndOpenLink)
                                <div class="mt-2">
                                    <a
                                        href="{{ $allowAndOpenLink }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-forest/90"
                                    >
                                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                        {{ __('Allow my IP and open TablePlus') }}
                                    </a>
                                </div>
                            @endif
                            <p class="mt-2 {{ $labelCls }}">{{ __('or grant access only') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    wire:model.live.debounce.500ms="allowIp"
                                    placeholder="203.0.113.7"
                                    aria-label="{{ __('Public IP address to allow') }}"
                                    class="w-44 rounded-lg border-brand-ink/15 bg-white font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage"
                                />
                                <x-secondary-button size="xs" type="button" wire:click="allowMyIp" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="allowMyIp">{{ __('Allow this IP') }}</span>
                                    <span wire:loading wire:target="allowMyIp">{{ __('Granting…') }}</span>
                                </x-secondary-button>
                            </div>
                            @unless ($operatorIp)
                                <p class="mt-1.5 text-xs text-brand-moss">
                                    {{ __('Your address could not be detected from this session — this is normal in local development or behind a proxy. Paste the public IP you connect from.') }}
                                </p>
                            @endunless
                        @endif
                    </div>
                @elseif ($unavailableReason === \App\Support\Servers\DatabaseConnectionTargetResolver::REASON_SERVER_NOT_SSHABLE)
                    <div class="mt-4 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">
                        {{ __('Ask an organization admin to grant temporary access to your address (:ip).', ['ip' => $operatorIp ?? __('unknown')]) }}
                    </div>
                @endif

                <div class="mt-5 flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">
                    <x-heroicon-o-key class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ __('The password is not shown here. Use the credentials your app already holds, or rotate it from Configure.') }}</span>
                </div>
            @endif

            <div class="mt-5 flex justify-end">
                <x-primary-button size="sm" type="button" x-on:click="$dispatch('close-modal', '{{ $modalName }}')">
                    {{ __('Done') }}
                </x-primary-button>
            </div>
        </div>
    </x-modal>
</div>
