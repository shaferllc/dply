@php
    $modalName = 'database-connect-'.$bindingId;
    $labelCls = 'text-2xs font-semibold uppercase tracking-wide text-brand-moss';
    $valueCls = 'mt-0.5 break-all font-mono text-xs text-brand-ink';
    $inputCls = 'rounded-lg border-brand-ink/15 bg-white font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage';
@endphp

<div>
    <x-secondary-button size="xs" type="button" wire:click="openConnect">
        <x-heroicon-o-bolt class="h-4 w-4" />
        {{ __('Connect') }}
    </x-secondary-button>

    <x-modal :name="$modalName" max-width="2xl" focusable>
        <div class="max-h-[85vh] overflow-y-auto p-6">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Connect to this database') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Works with TablePlus, DBeaver, DataGrip, psql — any client.') }}</p>
                </div>
                <button type="button" wire:click="closeConnect" x-on:click="$dispatch('close-modal', '{{ $modalName }}')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            @if (! $target)
                <div class="mt-4 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
                    {{ __('No connection details yet. If this database is still provisioning, they appear once the provider reports a host.') }}
                </div>
            @else
                {{-- 1 · Connection details --}}
                <dl class="mt-4 grid min-w-0 grid-cols-2 gap-x-4 gap-y-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-4 sm:grid-cols-3">
                    <div class="col-span-2 min-w-0 sm:col-span-3">
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
                        <dt class="{{ $labelCls }}">{{ __('TLS') }}</dt>
                        <dd class="{{ $valueCls }}">{{ $target->sslMode ?? __('not enforced') }}</dd>
                    </div>
                </dl>

                {{-- 2 · Who you connect as --}}
                <div class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="{{ $labelCls }}">{{ __('Connect as') }}</p>
                        @if ($canManageUsers && ! $creatingUser)
                            <button type="button" wire:click="startCreatingUser" class="text-xs font-semibold text-brand-ink underline decoration-brand-sage underline-offset-2 hover:text-brand-forest">
                                {{ __('Create a user') }}
                            </button>
                        @endif
                    </div>

                    @if ($creatingUser)
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="text" wire:model="newUserName" placeholder="reporting" class="{{ $inputCls }} w-48" />
                            <x-secondary-button size="xs" type="button" wire:click="createUser" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="createUser">{{ __('Create') }}</span>
                                <span wire:loading wire:target="createUser">{{ __('Creating…') }}</span>
                            </x-secondary-button>
                            <button type="button" wire:click="cancelCreatingUser" class="text-xs text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                        </div>
                        <p class="mt-1.5 text-xs leading-relaxed text-brand-moss">
                            {{ __('The provider creates the account. It is not read-only on its own — narrowing its grants is a SQL step you run once connected.') }}
                        </p>
                    @else
                        <select wire:model.live="connectAs" class="{{ $inputCls }} mt-1 w-full sm:w-72">
                            <option value="">{{ __(':user · cluster admin', ['user' => $target->username]) }}</option>
                            @foreach ($databaseUsers as $dbUser)
                                @continue($dbUser['name'] === $target->username)
                                <option value="{{ $dbUser['name'] }}">{{ $dbUser['name'] }}</option>
                            @endforeach
                        </select>
                    @endif

                    @if ($isProduction && $connectAs === '')
                        <div class="mt-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">
                            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ __('The admin account can DROP and TRUNCATE, and changes to production are immediate.') }}</span>
                        </div>
                    @endif
                </div>

                {{-- 3 · Get connected --}}
                <div class="mt-5 border-t border-brand-ink/10 pt-4">
                    @if ($unavailableReason)
                        <p class="text-xs leading-relaxed text-brand-moss">
                            @switch($unavailableReason)
                                @case(\App\Support\Servers\DatabaseConnectionTargetResolver::REASON_PROVIDER_PUBLIC)
                                    {{ __('This database is reachable over the internet with TLS — connect directly.') }}
                                    @break
                                @case(\App\Support\Servers\DatabaseConnectionTargetResolver::REASON_SERVER_NOT_SSHABLE)
                                    {{ __('This site has no server to tunnel through, so allow your address below and connect directly.') }}
                                    @break
                                @default
                                    {{ __('The site’s server is not ready for SSH, so a tunnel cannot be built yet.') }}
                            @endswitch
                        </p>
                    @else
                        <p class="{{ $labelCls }}">{{ __('Step 1 · set up access (once)') }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('Installs a key that can only forward to this database — it cannot open a shell — plus an SSH config entry.') }}
                        </p>
                        @if ($tunnelInstallCommand)
                            <div class="mt-2 min-w-0">
                                <x-cli-snippet :command="$tunnelInstallCommand" :summary="__('Run once:')" />
                            </div>
                            <p class="mt-1.5 text-xs leading-relaxed text-brand-moss">
                                {{ __('The link works once and expires in 10 minutes. The key is applied to the server in the background — give it a few seconds before opening the tunnel.') }}
                            </p>
                        @else
                            <div class="mt-2">
                                <x-secondary-button size="xs" type="button" wire:click="setUpTunnelAccess" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="setUpTunnelAccess">{{ __('Generate install command') }}</span>
                                    <span wire:loading wire:target="setUpTunnelAccess">{{ __('Generating…') }}</span>
                                </x-secondary-button>
                            </div>
                        @endif

                        <p class="mt-4 {{ $labelCls }}">{{ __('Step 2 · connect') }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('Either command opens the tunnel if it is not already running, then connects. Credentials are fetched when the command runs, so they never enter your shell history.') }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <label for="connect-local-port-{{ $bindingId }}" class="text-xs text-brand-moss">{{ __('Local port') }}</label>
                            <input id="connect-local-port-{{ $bindingId }}" type="number" min="1024" max="65535" wire:model.live.debounce.400ms="localPort" class="{{ $inputCls }} w-24" />
                        </div>
                        <div class="mt-2 min-w-0 space-y-2">
                            @if ($launchCommand)
                                <x-cli-snippet :command="$launchCommand" :summary="__('…in TablePlus:')" />
                            @endif
                            @if ($terminalCommand)
                                <x-cli-snippet :command="$terminalCommand" :summary="__('…at a terminal prompt:')" />
                            @endif
                        </div>
                    @endif

                    {{-- The tunnel leads when one is available: it is the path that
                         works from anywhere. Direct only works if THIS machine's
                         address is on the allowlist, and an allowance added from a
                         proxied session is often not this machine at all — which
                         reads as TablePlus hanging on connect. --}}
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        @if ($tunnelLink)
                            <a href="{{ $tunnelLink }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-forest/90">
                                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                {{ __('Open in TablePlus') }}
                            </a>
                        @endif

                        @if ($terminalScriptLink)
                            <a href="{{ $terminalScriptLink }}" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                <x-heroicon-o-command-line class="h-4 w-4" />
                                {{ __('Open in Terminal') }}
                            </a>
                        @endif

                        @if ($directLink)
                            <a href="{{ $directLink }}" target="_blank" rel="noopener" @class([
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm transition',
                                'bg-brand-forest text-white hover:bg-brand-forest/90' => ! $tunnelLink,
                                'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => (bool) $tunnelLink,
                            ])>
                                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                {{ $tunnelLink ? __('Open directly') : __('Open in TablePlus') }}
                            </a>
                            @unless ($tunnelLink)
                                <span class="text-xs text-brand-moss">{{ __('direct to the database') }}</span>
                            @endunless
                        @elseif ($allowAndOpenLink)
                            <a href="{{ $allowAndOpenLink }}" target="_blank" rel="noopener" @class([
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm transition',
                                'bg-brand-forest text-white hover:bg-brand-forest/90' => ! $tunnelLink,
                                'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => (bool) $tunnelLink,
                            ])>
                                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                {{ __('Allow my IP and open directly') }}
                            </a>
                        @endif
                    </div>

                    @if ($terminalScriptLink)
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('“Open in Terminal” downloads a .command file — macOS blocks downloaded scripts on first run, so right-click it and choose Open the first time.') }}
                        </p>
                    @endif
                </div>

                {{-- Direct access, only where it applies --}}
                @if ($allowance || ($canAllowIp && ! $allowAndOpenLink))
                    <div class="mt-5 border-t border-brand-ink/10 pt-4">
                        <p class="{{ $labelCls }}">{{ __('Direct access') }}</p>
                        @if ($allowance)
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                                <p class="min-w-0 break-all text-xs text-brand-moss">
                                    <span class="font-mono text-brand-ink">{{ $allowance->ip_address }}</span>
                                    {{ __('allowed · expires :when', ['when' => $allowance->expires_at->diffForHumans()]) }}
                                </p>
                                <x-secondary-button size="xs" type="button" wire:click="revokeMyIp" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="revokeMyIp">{{ __('Revoke now') }}</span>
                                    <span wire:loading wire:target="revokeMyIp">{{ __('Revoking…') }}</span>
                                </x-secondary-button>
                            </div>
                        @else
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input type="text" wire:model.live.debounce.500ms="allowIp" placeholder="203.0.113.7" aria-label="{{ __('Public IP address to allow') }}" class="{{ $inputCls }} w-44" />
                                <x-secondary-button size="xs" type="button" wire:click="allowMyIp" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="allowMyIp">{{ __('Allow this IP') }}</span>
                                    <span wire:loading wire:target="allowMyIp">{{ __('Granting…') }}</span>
                                </x-secondary-button>
                            </div>
                            @unless ($operatorIp)
                                <p class="mt-1.5 text-xs leading-relaxed text-brand-moss">{{ __('Your address could not be detected — normal in local development or behind a proxy. Paste the public IP you connect from.') }}</p>
                            @endunless
                        @endif
                    </div>
                @endif

                {{-- Escape hatches, out of the way --}}
                <details class="mt-5 border-t border-brand-ink/10 pt-3">
                    <summary class="cursor-pointer text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Use my own SSH key, or connect manually') }}</summary>
                    <div class="mt-3 space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <label for="connect-ssh-key-{{ $bindingId }}" class="text-xs text-brand-moss">{{ __('SSH key') }}</label>
                            <input id="connect-ssh-key-{{ $bindingId }}" type="text" wire:model.live.debounce.400ms="sshKeyPath" class="{{ $inputCls }} w-64" />
                        </div>
                        @if ($tunnel)
                            <div class="min-w-0">
                                <x-cli-snippet :commands="[
                                    ['label' => __('Tunnel'), 'command' => $tunnel['tunnel']],
                                    ['label' => __('Connection URI'), 'command' => $tunnel['uri']],
                                    ['label' => __('Terminal client'), 'command' => $tunnel['connect']],
                                ]" :summary="__('Manual commands')" />
                            </div>
                        @endif
                        <p class="text-xs text-brand-moss">{{ __('The password is not shown here — it travels only through the buttons above.') }}</p>
                    </div>
                </details>
            @endif

            <div class="mt-5 flex justify-end">
                <x-primary-button size="sm" type="button" x-on:click="$dispatch('close-modal', '{{ $modalName }}')">
                    {{ __('Done') }}
                </x-primary-button>
            </div>
        </div>
    </x-modal>
</div>
