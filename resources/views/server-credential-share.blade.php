<x-app-layout>
    @php
        $hostDisplay = $host !== '' ? $host : __('your server IP');
        $authPart = $requiresPassword && filled($password) ? ':'.$password.'@' : '';
        $connectionUrl = $engine.'://'.$authPart.$hostDisplay.':'.$port;
        $cli = 'redis-cli -h '.$hostDisplay.' -p '.$port.($requiresPassword && filled($password) ? " -a '".$password."'" : '');
    @endphp
    <div class="mx-auto max-w-lg px-4 py-10 sm:px-6 lg:px-8">
        <h1 class="text-xl font-semibold text-brand-ink">{{ __('Server credentials') }}</h1>
        <p class="mt-2 text-sm text-brand-moss">
            {{ __('Server: :name', ['name' => $server->name]) }}
            <span class="text-brand-mist" aria-hidden="true">·</span>
            {{ __('Engine: :engine', ['engine' => $engine]) }}
        </p>

        <dl class="mt-8 space-y-4 rounded-2xl border border-brand-ink/10 bg-white p-6 text-sm shadow-sm">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host') }}</dt>
                <dd class="mt-1 font-mono text-brand-ink">{{ $hostDisplay }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</dt>
                <dd class="mt-1 font-mono text-brand-ink">{{ $port }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</dt>
                <dd class="mt-1 break-all font-mono text-brand-ink">
                    @if ($requiresPassword && filled($password))
                        {{ $password }}
                    @else
                        <span class="text-brand-moss">{{ __('No password required (AUTH disabled).') }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Connection URL') }}</dt>
                <dd class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $connectionUrl }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('redis-cli') }}</dt>
                <dd class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $cli }}</dd>
            </div>
        </dl>

        @unless ($remoteAccess)
            <p class="mt-4 rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-xs text-brand-forest">
                {{ __('Remote access is disabled — this engine only accepts connections from the server itself (localhost). Connect from an app on the same host, or enable remote access in the workspace.') }}
            </p>
        @endunless

        <p class="mt-6 text-xs text-brand-mist">
            {{ __('This page stops working after the link expires or reaches its view limit (:remaining view(s) left). Do not share it publicly.', ['remaining' => max(0, (int) $share->views_remaining)]) }}
        </p>
    </div>
</x-app-layout>
