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
    <div class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8" x-data="{ tab: 'laravel' }">
        <h2 class="text-base font-semibold text-brand-ink">{{ __(':engine — connection snippet', ['engine' => $engineLabel]) }}</h2>
        <p class="mt-2 text-sm text-brand-moss">
            @if ($snippetIsExposed)
                {{ __('Drop into apps connecting from outside this server. The host is the server\'s public IP, allowed by the network exposure rule on the Configure tab.') }}
            @else
                {{ __('Drop into your app on this server. The engine is bound to the loopback interface — expose it from the Configure tab if a remote app needs to connect.') }}
            @endif
        </p>
        @if ($isRedisFork)
            <p class="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                {{ __(':engine speaks the Redis wire protocol — Laravel\'s `redis` driver and any Redis client library work as-is. The env vars stay REDIS_* on purpose.', ['engine' => $engineLabel]) }}
            </p>
        @endif

        <div role="tablist" class="mt-4 flex flex-wrap gap-1.5 border-b border-brand-ink/10">
            @foreach (['laravel' => 'Laravel .env', 'node' => 'Node.js', 'python' => 'Python', 'docker' => 'Docker Compose'] as $tabKey => $tabLabel)
                <button
                    type="button"
                    role="tab"
                    @click="tab = '{{ $tabKey }}'"
                    :aria-selected="tab === '{{ $tabKey }}' ? 'true' : 'false'"
                    :class="tab === '{{ $tabKey }}'
                        ? 'border-b-2 border-brand-forest text-brand-ink'
                        : 'border-b-2 border-transparent text-brand-moss hover:text-brand-ink'"
                    class="px-3 py-2 text-xs font-medium transition-colors"
                >{{ $tabLabel }}</button>
            @endforeach
        </div>

        @if ($isMemcached)
            <div x-show="tab === 'laravel'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
CACHE_STORE=memcached
MEMCACHED_HOST={{ $snippetHost }}
MEMCACHED_PORT={{ $cacheService->port }}</pre>
            </div>
            <div x-show="tab === 'node'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">// Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
// npm install memjs
const memjs = require('memjs');
const client = memjs.Client.create('{{ $snippetHost }}:{{ $cacheService->port }}');</pre>
            </div>
            <div x-show="tab === 'python'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
# pip install pymemcache
from pymemcache.client.base import Client
client = Client(('{{ $snippetHost }}', {{ $cacheService->port }}))</pre>
            </div>
            <div x-show="tab === 'docker'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
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
            <div x-show="tab === 'laravel'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
CACHE_STORE=redis
REDIS_HOST={{ $snippetHost }}
REDIS_PORT={{ $cacheService->port }}
REDIS_PASSWORD={{ $redisPassword }}
{{ $envCachePrefixLine }}
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis</pre>
            </div>
            <div x-show="tab === 'node'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">// Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
// npm install ioredis
import Redis from 'ioredis';
const redis = new Redis({
    host: '{{ $snippetHost }}',
    port: {{ $cacheService->port }}{{ $redisAuthArg }}{{ $ioredisPrefixArg }},
});</pre>
            </div>
            <div x-show="tab === 'python'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
# pip install redis
import redis
r = redis.Redis(
    host='{{ $snippetHost }}',
    port={{ $cacheService->port }}{{ $pyAuthArg }},
    decode_responses=True,
){{ $pyPrefixComment }}</pre>
            </div>
            <div x-show="tab === 'docker'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"># Engine: {{ $engineLabel }} on {{ $snippetHost }}:{{ $cacheService->port }}
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
