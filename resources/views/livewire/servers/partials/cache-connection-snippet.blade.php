@php
    /** @var \App\Models\ServerCacheService|null $cacheService */
    /** @var \App\Models\Server|null $server */
    $cacheService = $cacheService ?? null;
    $server = $server ?? null;
    $isMemcached = $cacheService && $cacheService->engine === 'memcached';
    $hasAuth = $cacheService && filled($cacheService->auth_password ?? null);
    $authValue = $hasAuth ? $cacheService->auth_password : null;
    $engineKey = $cacheService?->engine;
    $engineLabelMap = $engineLabels ?? [];
    $engineLabel = $engineKey ? ($engineLabelMap[$engineKey] ?? ucfirst($engineKey)) : null;
    $isRedisFork = $cacheService && in_array($engineKey, ['valkey', 'keydb', 'dragonfly'], true);

    // When the cache is network-exposed (bind=0.0.0.0 + firewall rule), the
    // snippets should target the server's public IP — not loopback, which only
    // worked when the app was co-located. Loopback stays as the fallback for
    // non-exposed engines and as the development pattern on app servers.
    $snippetIsExposed = false;
    $snippetHost = '127.0.0.1';
    if ($cacheService && in_array($engineKey, ['redis', 'valkey', 'keydb', 'dragonfly'], true)) {
        try {
            $snippetIsExposed = app(\App\Support\Servers\CacheServiceNetworkExposure::class)->isExposed($cacheService);
        } catch (\Throwable) {
            $snippetIsExposed = false;
        }
        $remoteHost = trim((string) ($server?->ip_address ?? ''));
        if ($snippetIsExposed && $remoteHost !== '') {
            $snippetHost = $remoteHost;
        }
    }

    // CACHE_PREFIX is a Laravel client-side concern — surfaced when the operator
    // set one on the row via the Connection Details card. Empty string ("no
    // prefix") renders as a placeholder comment so the .env template still tells
    // the operator the variable exists.
    $cachePrefixValue = $cacheService ? (string) ($cacheService->cache_prefix ?? '') : '';
    $hasPrefix = $cachePrefixValue !== '';
@endphp
@if ($cacheService)
    <div class="{{ $card ?? 'border-b border-brand-ink/10' }}" x-data="{ subtab: 'laravel' }">
        {{-- Dense head: the "where does this connect from" caveat is reference
             text, so it rides the note instead of two lines of body prose. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-code-bracket-square"
            :title="__(':engine — connection snippet', ['engine' => $engineLabel])"
            :note="$snippetIsExposed
                ? __('Drop into apps connecting from outside this server. The host is the server\'s public IP, allowed by the network exposure rule on the Configure tab.')
                : __('Drop into your app on this server. The engine is bound to the loopback interface — expose it from the Configure tab if a remote app needs to connect.')"
            class="border-b border-brand-ink/10"
        />

        @if ($isRedisFork)
            <p class="border-b border-brand-ink/10 px-4 py-2 text-xs leading-relaxed text-brand-moss sm:px-5">
                {{ __(':engine speaks the Redis wire protocol — Laravel\'s `redis` driver and any Redis client library work as-is. The env vars stay REDIS_* on purpose.', ['engine' => $engineLabel]) }}
            </p>
        @endif

        <div class="px-4 pt-2.5 sm:px-5">
        <x-server-workspace-tablist :aria-label="__('Connection snippet languages')" class="!mb-0" scroll bare>
            @foreach (['laravel' => 'Laravel .env', 'node' => 'Node.js', 'python' => 'Python', 'docker' => 'Docker Compose'] as $tabKey => $tabLabel)
                <x-server-workspace-tab :subtab-key="$tabKey">{{ $tabLabel }}</x-server-workspace-tab>
            @endforeach
        </x-server-workspace-tablist>
        </div>

        @if ($isMemcached)
            <div x-show="subtab === 'laravel'" x-cloak>
                <pre class="mx-4 my-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink sm:mx-5"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
CACHE_STORE=memcached
MEMCACHED_HOST={{ $snippetHost }}
MEMCACHED_PORT={{ $cacheService->port }}</pre>
            </div>
            <div x-show="subtab === 'node'" x-cloak>
                <pre class="mx-4 my-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink sm:mx-5">// Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
// npm install memjs
const memjs = require('memjs');
const client = memjs.Client.create('{{ $snippetHost }}:{{ $cacheService->port }}');</pre>
            </div>
            <div x-show="subtab === 'python'" x-cloak>
                <pre class="mx-4 my-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink sm:mx-5"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
# pip install pymemcache
from pymemcache.client.base import Client
client = Client(('{{ $snippetHost }}', {{ $cacheService->port }}))</pre>
            </div>
            <div x-show="subtab === 'docker'" x-cloak>
                <pre class="mx-4 my-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink sm:mx-5"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
services:
  app:
    image: your-app:latest
    network_mode: host
    environment:
      MEMCACHED_HOST: {{ $snippetHost }}
      MEMCACHED_PORT: '{{ $cacheService->port }}'</pre>
            </div>
        @else
            {{-- redis / valkey / keydb / dragonfly are wire-compatible; one set of snippets covers all. --}}
            @php
                $redisPassword = $hasAuth ? $authValue : 'null';
                $redisAuthArg = $hasAuth ? ", password: '".$authValue."'" : '';
                $pyAuthArg = $hasAuth ? ", password='".$authValue."'" : '';
                $dockerPasswordLine = $hasAuth ? "      REDIS_PASSWORD: '".$authValue."'" : '      # REDIS_PASSWORD not set on the engine';
                $envCachePrefixLine = $hasPrefix ? 'CACHE_PREFIX='.$cachePrefixValue : '# CACHE_PREFIX=  # optional — Laravel prepends to every key, e.g. acme_cache_';
                $dockerCachePrefixLine = $hasPrefix ? "      CACHE_PREFIX: '".$cachePrefixValue."'" : "      # CACHE_PREFIX: 'acme_cache_'  # optional Laravel key namespace";
                $ioredisPrefixArg = $hasPrefix ? ", keyPrefix: '".$cachePrefixValue."'" : '';
                $pyPrefixComment = $hasPrefix ? "\n# Apply prefix in-app: r.set(f'{prefix}{key}', v) — redis-py has no built-in prefix.\nprefix = '".$cachePrefixValue."'" : '';
            @endphp
            <div x-show="subtab === 'laravel'" x-cloak>
                <pre class="mx-4 my-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink sm:mx-5"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
CACHE_STORE=redis
REDIS_HOST={{ $snippetHost }}
REDIS_PORT={{ $cacheService->port }}
REDIS_PASSWORD={{ $redisPassword }}
{{ $envCachePrefixLine }}
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis</pre>
            </div>
            <div x-show="subtab === 'node'" x-cloak>
                <pre class="mx-4 my-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink sm:mx-5">// Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
// npm install ioredis
import Redis from 'ioredis';
const redis = new Redis({
    host: '{{ $snippetHost }}',
    port: {{ $cacheService->port }}{{ $redisAuthArg }}{{ $ioredisPrefixArg }},
});</pre>
            </div>
            <div x-show="subtab === 'python'" x-cloak>
                <pre class="mx-4 my-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink sm:mx-5"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
# pip install redis
import redis
r = redis.Redis(
    host='{{ $snippetHost }}',
    port={{ $cacheService->port }}{{ $pyAuthArg }},
    decode_responses=True,
){{ $pyPrefixComment }}</pre>
            </div>
            <div x-show="subtab === 'docker'" x-cloak>
                <pre class="mx-4 my-3 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink sm:mx-5"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
services:
  app:
    image: your-app:latest
    network_mode: host
    environment:
      REDIS_HOST: {{ $snippetHost }}
      REDIS_PORT: '{{ $cacheService->port }}'
{{ $dockerPasswordLine }}
{{ $dockerCachePrefixLine }}</pre>
            </div>
        @endif

        @if ($hasAuth && ! $isMemcached)
            <p class="mt-3 text-xs text-brand-moss">{{ __('Password is the AUTH value Dply set on the engine. Treat it like any other secret — rotate it from the AUTH password card if anyone with read access leaves your team.') }}</p>
        @endif
    </div>
@endif
