@php
    /** @var \App\Models\ServerCacheService|null $cacheService */
    $cacheService = $cacheService ?? null;
    $isMemcached = $cacheService && $cacheService->engine === 'memcached';
    $hasAuth = $cacheService && filled($cacheService->auth_password ?? null);
    $authValue = $hasAuth ? $cacheService->auth_password : null;
@endphp
@if ($cacheService)
    <div class="{{ $card ?? 'dply-card overflow-hidden' }} p-6 sm:p-8" x-data="{ tab: 'laravel' }">
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Connection snippet') }}</h2>
        <p class="mt-2 text-sm text-brand-moss">{{ __('Drop into your app on this server. Localhost-only — Dply does not expose cache services beyond the loopback interface.') }}</p>

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
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">CACHE_STORE=memcached
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT={{ $cacheService->port }}</pre>
            </div>
            <div x-show="tab === 'node'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">// npm install memjs
const memjs = require('memjs');
const client = memjs.Client.create('127.0.0.1:{{ $cacheService->port }}');</pre>
            </div>
            <div x-show="tab === 'python'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"># pip install pymemcache
from pymemcache.client.base import Client
client = Client(('127.0.0.1', {{ $cacheService->port }}))</pre>
            </div>
            <div x-show="tab === 'docker'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">services:
  app:
    image: your-app:latest
    network_mode: host
    environment:
      MEMCACHED_HOST: 127.0.0.1
      MEMCACHED_PORT: '{{ $cacheService->port }}'</pre>
            </div>
        @else
            {{-- redis / valkey / keydb / dragonfly are wire-compatible; one set of snippets covers all. --}}
            @php
                $redisPassword = $hasAuth ? $authValue : 'null';
                $redisAuthArg = $hasAuth ? ", password: '".$authValue."'" : '';
                $pyAuthArg = $hasAuth ? ", password='".$authValue."'" : '';
                $dockerPasswordLine = $hasAuth ? "      REDIS_PASSWORD: '".$authValue."'" : '      # REDIS_PASSWORD not set on the engine';
            @endphp
            <div x-show="tab === 'laravel'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT={{ $cacheService->port }}
REDIS_PASSWORD={{ $redisPassword }}
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis</pre>
            </div>
            <div x-show="tab === 'node'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">// npm install ioredis
import Redis from 'ioredis';
const redis = new Redis({
    host: '127.0.0.1',
    port: {{ $cacheService->port }}{{ $redisAuthArg }},
});</pre>
            </div>
            <div x-show="tab === 'python'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink"># pip install redis
import redis
r = redis.Redis(
    host='127.0.0.1',
    port={{ $cacheService->port }}{{ $pyAuthArg }},
    decode_responses=True,
)</pre>
            </div>
            <div x-show="tab === 'docker'" x-cloak>
                <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">services:
  app:
    image: your-app:latest
    network_mode: host
    environment:
      REDIS_HOST: 127.0.0.1
      REDIS_PORT: '{{ $cacheService->port }}'
{{ $dockerPasswordLine }}</pre>
            </div>
        @endif

        @if ($hasAuth && ! $isMemcached)
            <p class="mt-3 text-xs text-brand-moss">{{ __('Password is the AUTH value Dply set on the engine. Treat it like any other secret — rotate it from the AUTH password card if anyone with read access leaves your team.') }}</p>
        @endif
    </div>
@endif
