@php
    /** @var \App\Models\ServerCacheService $row */
    /** @var \App\Models\Server $server */
    /** @var array<string, string> $engineLabels */

    // Memcached has no AUTH and is intentionally excluded from the network
    // exposure flow — this card surfaces redis-family connection details only.
    $isRedisFamily = in_array($row->engine, ['redis', 'valkey', 'keydb', 'dragonfly'], true);

    if ($isRedisFamily) {
        $networkExposure = app(\App\Support\Servers\CacheServiceNetworkExposure::class);
        $isExposed = $networkExposure->isExposed($row);
        $exposedRule = $isExposed ? $networkExposure->findManagedRule($row) : null;
        $hasAuth = filled($row->auth_password ?? null);
        $remoteHost = trim((string) ($server->ip_address ?? ''));
        $effectiveHost = $isExposed && $remoteHost !== '' ? $remoteHost : '127.0.0.1';
        $authPlaintext = $hasAuth ? (string) $row->auth_password : '';
        $engineLabel = $engineLabels[$row->engine] ?? ucfirst($row->engine);
        $cliBin = match ($row->engine) {
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };
        // Two versions: the masked form for shoulder-surfing safety on initial
        // render, and the plain form revealed (and copied) when the operator
        // clicks the eye toggle. Both single-quote the password so terminals
        // accept the paste verbatim regardless of special characters.
        $cliCommandPlain = $cliBin.' -h '.$effectiveHost.' -p '.$row->port.($hasAuth ? " -a '".str_replace("'", "'\\''", $authPlaintext)."'" : '');
        $cliCommandMasked = $cliBin.' -h '.$effectiveHost.' -p '.$row->port.($hasAuth ? " -a '••••••••'" : '');
    }
@endphp

@if ($isRedisFamily)
    <div
        class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8"
        x-data="{
            shown: false,
            copy(value, label) {
                if (! value) { return; }
                navigator.clipboard?.writeText(value);
                this.$dispatch('toast', { message: (label || 'Value') + ' copied' });
            },
        }"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-brand-ink">{{ __(':engine — connection details', ['engine' => $engineLabel]) }}</h2>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('Host, port, and AUTH password for a remote client. Anything in the source CIDR can connect with these values.') }}
                </p>
            </div>
            @if ($isExposed)
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    {{ __('Remote') }}
                </span>
            @else
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                    {{ __('Loopback only') }}
                </span>
            @endif
        </div>

        @if ($isExposed)
            <p class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-900">
                {{ __('Exposed to :source on TCP :port. Sources outside that range are blocked at the firewall.', ['source' => $exposedRule?->source ?? '—', 'port' => $row->port]) }}
            </p>
        @else
            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">
                <p>{{ __('Engine is bound to 127.0.0.1 only — remote clients cannot connect yet. Open the Configure tab and add a network exposure rule to allow another server in.') }}</p>
                <button
                    type="button"
                    wire:click="setEngineSubtab('configure')"
                    class="mt-1.5 inline-flex items-center gap-1 font-semibold text-amber-900 underline-offset-2 hover:underline"
                >
                    {{ __('Configure remote access') }}
                    <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                </button>
            </div>
        @endif

        <dl class="mt-5 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host') }}</dt>
                <dd class="mt-1">
                    <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-zinc-50 px-3 py-2">
                        <code class="min-w-0 flex-1 truncate font-mono text-sm text-brand-ink">{{ $effectiveHost }}</code>
                        <button
                            type="button"
                            x-on:click="copy(@js($effectiveHost), @js(__('Host')))"
                            class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                            title="{{ __('Copy') }}"
                        >
                            <x-heroicon-o-clipboard class="h-4 w-4" />
                        </button>
                    </div>
                    @if (! $isExposed && $remoteHost !== '')
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Server public IP is :ip — connections will start working from there once you expose the engine.', ['ip' => $remoteHost]) }}</p>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</dt>
                <dd class="mt-1">
                    <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-zinc-50 px-3 py-2">
                        <code class="min-w-0 flex-1 truncate font-mono text-sm text-brand-ink">{{ $row->port }}</code>
                        <button
                            type="button"
                            x-on:click="copy(@js((string) $row->port), @js(__('Port')))"
                            class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                            title="{{ __('Copy') }}"
                        >
                            <x-heroicon-o-clipboard class="h-4 w-4" />
                        </button>
                    </div>
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="flex items-center justify-between text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <span>{{ __('AUTH password') }}</span>
                    @if ($hasAuth)
                        <button
                            type="button"
                            wire:click="setEngineSubtab('configure')"
                            class="rounded-md font-sans text-[11px] font-medium normal-case tracking-normal text-brand-forest hover:underline"
                        >
                            {{ __('Rotate') }}
                        </button>
                    @endif
                </dt>
                <dd class="mt-1">
                    @if ($hasAuth)
                        <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-zinc-50 px-3 py-2">
                            <code class="min-w-0 flex-1 truncate font-mono text-sm text-brand-ink">
                                <span x-show="! shown">{{ str_repeat('•', min(strlen($authPlaintext), 24)) }}</span>
                                <span x-show="shown" x-cloak>{{ $authPlaintext }}</span>
                            </code>
                            <button
                                type="button"
                                x-on:click="shown = !shown"
                                class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                                :title="shown ? @js(__('Hide')) : @js(__('Show'))"
                            >
                                <x-heroicon-o-eye class="h-4 w-4" x-show="! shown" />
                                <x-heroicon-o-eye-slash class="h-4 w-4" x-show="shown" x-cloak />
                            </button>
                            <button
                                type="button"
                                x-on:click="copy(@js($authPlaintext), @js(__('Password')))"
                                class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                                title="{{ __('Copy') }}"
                            >
                                <x-heroicon-o-clipboard class="h-4 w-4" />
                            </button>
                        </div>
                    @else
                        <div class="rounded-lg border border-rose-200 bg-rose-50/70 px-3 py-2 text-xs text-rose-900">
                            <p>{{ __('No AUTH password is set. Anything that reaches this port can run commands. Set one before exposing remotely.') }}</p>
                            <button
                                type="button"
                                wire:click="setEngineSubtab('configure')"
                                class="mt-1.5 inline-flex items-center gap-1 font-semibold text-rose-900 underline-offset-2 hover:underline"
                            >
                                {{ __('Set password') }}
                                <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                            </button>
                        </div>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($hasAuth || $isExposed)
            <div class="mt-5">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Open a remote :cli session', ['cli' => $cliBin]) }}</p>
                <div class="mt-1 flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-zinc-50 px-3 py-2">
                    <code class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap font-mono text-xs text-brand-ink">
                        <span x-show="! shown">{{ $cliCommandMasked }}</span>
                        <span x-show="shown" x-cloak>{{ $cliCommandPlain }}</span>
                    </code>
                    <button
                        type="button"
                        x-on:click="copy(@js($cliCommandPlain), @js(__('Command')))"
                        class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                        title="{{ __('Copy command') }}"
                    >
                        <x-heroicon-o-clipboard class="h-4 w-4" />
                    </button>
                </div>
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Toggle the eye icon above to reveal the password inside this command.') }}</p>
            </div>
        @endif
    </div>
@endif
