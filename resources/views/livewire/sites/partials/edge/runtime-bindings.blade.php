@php
    use App\Models\EdgeDeployment;

    $edgeMeta = $site->edgeMeta();
    $defaults = is_array($edgeMeta['default_bindings'] ?? null) ? $edgeMeta['default_bindings'] : [];
    $defaultKvId = is_string($defaults['kv'] ?? null) ? (string) $defaults['kv'] : null;

    $latest = $site->relationLoaded('edgeDeployments') && $site->edgeDeployments !== null
        ? $site->edgeDeployments->first(fn (EdgeDeployment $d): bool => $d->status === EdgeDeployment::STATUS_LIVE)
        : EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first();

    $repoBindings = is_array($latest?->repo_config['bindings'] ?? null) ? $latest->repo_config['bindings'] : [];
    $declaredKv = is_array($repoBindings['kv'] ?? null) ? $repoBindings['kv'] : [];
    $declaredR2 = is_array($repoBindings['r2'] ?? null) ? $repoBindings['r2'] : [];
    $declaredD1 = is_array($repoBindings['d1'] ?? null) ? $repoBindings['d1'] : [];

    $accountId = config('edge.cf.account_id') ?: ($site->edgeProviderCredential?->getMeta('account_id'));
    $kvCfUrl = ($accountId && $defaultKvId)
        ? "https://dash.cloudflare.com/{$accountId}/workers/kv/namespaces/{$defaultKvId}"
        : null;

    // Templates — common starter configurations a user can copy whole-cloth.
    $templates = [
        'session-cache' => [
            'label' => __('Session / cache (KV only)'),
            'hint' => __('Out-of-the-box. env.KV is already provisioned for every site.'),
            'wrangler' => "# env.KV is provisioned automatically — no wrangler entry needed.",
            'yaml' => "# env.KV is provisioned automatically — no dply.yaml entry needed.",
        ],
        'file-uploads' => [
            'label' => __('File uploads (R2 bucket)'),
            'hint' => __('Adds an R2 bucket bound as env.BUCKET. dply creates the bucket on the next deploy.'),
            'wrangler' => "[[r2_buckets]]\nbinding = \"BUCKET\"\nbucket_name = \"my-site-uploads\"",
            'yaml' => "bindings:\n  r2:\n    BUCKET: \"my-site-uploads\"",
        ],
        'app-db' => [
            'label' => __('App database (D1)'),
            'hint' => __('Adds a D1 SQLite database bound as env.DB. Run schema migrations after the first deploy.'),
            'wrangler' => "[[d1_databases]]\nbinding = \"DB\"\ndatabase_name = \"my-site-db\"",
            'yaml' => "bindings:\n  d1:\n    DB: \"my-site-db\"",
        ],
        'full-stack' => [
            'label' => __('Full-stack (KV + R2 + D1)'),
            'hint' => __('Everything an SSR-heavy site usually needs: cache, file storage, and a relational DB.'),
            'wrangler' => "[[r2_buckets]]\nbinding = \"BUCKET\"\nbucket_name = \"my-site-uploads\"\n\n[[d1_databases]]\nbinding = \"DB\"\ndatabase_name = \"my-site-db\"",
            'yaml' => "bindings:\n  r2:\n    BUCKET: \"my-site-uploads\"\n  d1:\n    DB: \"my-site-db\"",
        ],
    ];
@endphp

<section class="dply-card overflow-hidden" x-data="{
    tab: 'read',
    r2Open: {{ $declaredR2 === [] ? 'false' : 'true' }},
    d1Open: {{ $declaredD1 === [] ? 'false' : 'true' }},
    templatesOpen: false,
    r2Fmt: 'wrangler',
    d1Fmt: 'wrangler',
    tpl: 'session-cache',
    tplFmt: 'wrangler',
    copy(text, btn) {
        navigator.clipboard.writeText(text);
        const original = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(() => { btn.textContent = original; }, 1500);
    }
}">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Runtime') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Edge runtime') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('What your worker can read from `env`. dply provisions a default KV namespace per site automatically; declare more in wrangler.toml or dply.yaml.') }}</p>
        </div>
    </div>

    {{-- env.KV — always present (or will be on next deploy). --}}
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <p class="font-mono text-sm font-semibold text-brand-ink">env.KV</p>
                <p class="mt-0.5 text-xs text-brand-moss">
                    {{ __('KV namespace') }}
                    @if ($defaultKvId)
                        — <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900">{{ __('Live') }}</span>
                    @else
                        — <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Provisioned on next deploy') }}</span>
                    @endif
                </p>
            </div>
            @if ($kvCfUrl)
                <a href="{{ $kvCfUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline">
                    {{ __('View on Cloudflare') }}
                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                </a>
            @endif
        </div>

        <div class="mt-3 flex gap-1 border-b border-brand-ink/10">
            <button type="button" :class="tab === 'read' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="tab = 'read'">{{ __('Read') }}</button>
            <button type="button" :class="tab === 'write' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="tab = 'write'">{{ __('Write') }}</button>
            <button type="button" :class="tab === 'list' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="tab = 'list'">{{ __('List') }}</button>
        </div>

        <div x-show="tab === 'read'" x-cloak class="mt-2">
            <pre class="overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>const value = await env.KV.get('user:42');
return new Response(value ?? 'not found');</code></pre>
        </div>
        <div x-show="tab === 'write'" x-cloak class="mt-2">
            <pre class="overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>await env.KV.put('user:42', JSON.stringify({ name: 'Ada' }), {
  expirationTtl: 60 * 60, // 1 hour
});</code></pre>
        </div>
        <div x-show="tab === 'list'" x-cloak class="mt-2">
            <pre class="overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>const { keys } = await env.KV.list({ prefix: 'user:' });
for (const { name } of keys) console.log(name);</code></pre>
        </div>

        @if ($declaredKv !== [])
            <p class="mt-3 text-[11px] text-brand-mist">
                {{ __('Extra KV bindings declared:') }}
                <span class="font-mono text-brand-moss">{{ implode(', ', array_map(fn ($n) => "env.{$n}", array_keys($declaredKv))) }}</span>
            </p>
        @endif
    </div>

    {{-- R2 disclosure --}}
    <div class="border-b border-brand-ink/10">
        <button type="button" @click="r2Open = ! r2Open" class="flex w-full items-center justify-between gap-3 px-6 py-3 text-left hover:bg-brand-sand/20 sm:px-8">
            <div>
                <p class="font-mono text-sm font-semibold text-brand-ink">env.&lt;name&gt;</p>
                <p class="mt-0.5 text-xs text-brand-moss">
                    {{ __('R2 bucket') }}
                    @if ($declaredR2 !== [])
                        — <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900">{{ count($declaredR2) }} {{ __('declared') }}</span>
                    @else
                        — <span class="text-brand-mist">{{ __('Not wired — declare in wrangler.toml or dply.yaml') }}</span>
                    @endif
                </p>
            </div>
            <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform" x-bind:class="r2Open ? 'rotate-180' : ''" />
        </button>
        <div x-show="r2Open" x-cloak class="space-y-4 px-6 pb-4 sm:px-8">
            @if ($declaredR2 === [])
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Declare it') }}</p>
                    <div class="mt-1 flex gap-1 border-b border-brand-ink/10">
                        <button type="button" :class="r2Fmt === 'wrangler' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="r2Fmt = 'wrangler'">{{ __('wrangler.toml') }}</button>
                        <button type="button" :class="r2Fmt === 'yaml' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="r2Fmt = 'yaml'">{{ __('dply.yaml') }}</button>
                    </div>
                    <div x-show="r2Fmt === 'wrangler'" x-cloak class="mt-2 flex items-start gap-2">
                        <pre class="flex-1 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand" x-ref="r2Wrangler"><code>[[r2_buckets]]
binding = "BUCKET"
bucket_name = "my-site-photos"</code></pre>
                        <button type="button" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" @click="copy($refs.r2Wrangler.innerText, $event.currentTarget)">{{ __('Copy') }}</button>
                    </div>
                    <div x-show="r2Fmt === 'yaml'" x-cloak class="mt-2 flex items-start gap-2">
                        <pre class="flex-1 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand" x-ref="r2Yaml"><code>bindings:
  r2:
    BUCKET: "my-site-photos"</code></pre>
                        <button type="button" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" @click="copy($refs.r2Yaml.innerText, $event.currentTarget)">{{ __('Copy') }}</button>
                    </div>
                    <p class="mt-1.5 text-[11px] text-brand-mist">{{ __('dply auto-creates the bucket on your next deploy.') }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Once wired, your code can use:') }}</p>
                    <pre class="mt-1 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand opacity-70"><code>await env.BUCKET.put('photos/hero.jpg', request.body, {
  httpMetadata: { contentType: 'image/jpeg' },
});
const obj = await env.BUCKET.get('photos/hero.jpg');</code></pre>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($declaredR2 as $name => $bucketName)
                        <div class="rounded-lg border border-brand-ink/10 p-3">
                            <p class="font-mono text-sm text-brand-ink">env.{{ $name }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Bucket:') }} <span class="font-mono">{{ $bucketName }}</span></p>
                        </div>
                    @endforeach
                </div>
                @php $firstName = (string) array_key_first($declaredR2); @endphp
                <pre class="overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>await env.{{ $firstName }}.put('photos/hero.jpg', request.body, {
  httpMetadata: { contentType: 'image/jpeg' },
});
const obj = await env.{{ $firstName }}.get('photos/hero.jpg');</code></pre>
            @endif
        </div>
    </div>

    {{-- D1 disclosure --}}
    <div class="border-b border-brand-ink/10">
        <button type="button" @click="d1Open = ! d1Open" class="flex w-full items-center justify-between gap-3 px-6 py-3 text-left hover:bg-brand-sand/20 sm:px-8">
            <div>
                <p class="font-mono text-sm font-semibold text-brand-ink">env.&lt;name&gt;</p>
                <p class="mt-0.5 text-xs text-brand-moss">
                    {{ __('D1 database') }}
                    @if ($declaredD1 !== [])
                        — <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900">{{ count($declaredD1) }} {{ __('declared') }}</span>
                    @else
                        — <span class="text-brand-mist">{{ __('Not wired — declare in wrangler.toml or dply.yaml') }}</span>
                    @endif
                </p>
            </div>
            <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform" x-bind:class="d1Open ? 'rotate-180' : ''" />
        </button>
        <div x-show="d1Open" x-cloak class="space-y-4 px-6 pb-4 sm:px-8">
            @if ($declaredD1 === [])
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Declare it') }}</p>
                    <div class="mt-1 flex gap-1 border-b border-brand-ink/10">
                        <button type="button" :class="d1Fmt === 'wrangler' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="d1Fmt = 'wrangler'">{{ __('wrangler.toml') }}</button>
                        <button type="button" :class="d1Fmt === 'yaml' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="d1Fmt = 'yaml'">{{ __('dply.yaml') }}</button>
                    </div>
                    <div x-show="d1Fmt === 'wrangler'" x-cloak class="mt-2 flex items-start gap-2">
                        <pre class="flex-1 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand" x-ref="d1Wrangler"><code>[[d1_databases]]
binding = "DB"
database_name = "my-site-db"</code></pre>
                        <button type="button" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" @click="copy($refs.d1Wrangler.innerText, $event.currentTarget)">{{ __('Copy') }}</button>
                    </div>
                    <div x-show="d1Fmt === 'yaml'" x-cloak class="mt-2 flex items-start gap-2">
                        <pre class="flex-1 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand" x-ref="d1Yaml"><code>bindings:
  d1:
    DB: "my-site-db"</code></pre>
                        <button type="button" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" @click="copy($refs.d1Yaml.innerText, $event.currentTarget)">{{ __('Copy') }}</button>
                    </div>
                    <p class="mt-1.5 text-[11px] text-brand-mist">{{ __('dply auto-creates the database on your next deploy.') }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Once wired, your code can use:') }}</p>
                    <pre class="mt-1 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand opacity-70"><code>const { results } = await env.DB
  .prepare('SELECT id, email FROM users WHERE org_id = ?')
  .bind(orgId)
  .all();</code></pre>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($declaredD1 as $name => $databaseId)
                        <div class="rounded-lg border border-brand-ink/10 p-3">
                            <p class="font-mono text-sm text-brand-ink">env.{{ $name }}</p>
                            <p class="mt-0.5 break-all font-mono text-[11px] text-brand-mist">{{ $databaseId }}</p>
                        </div>
                    @endforeach
                </div>
                @php $firstName = (string) array_key_first($declaredD1); @endphp
                <pre class="overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>const { results } = await env.{{ $firstName }}
  .prepare('SELECT id, email FROM users WHERE org_id = ?')
  .bind(orgId)
  .all();</code></pre>
            @endif
        </div>
    </div>

    {{-- Quick templates --}}
    <div>
        <button type="button" @click="templatesOpen = ! templatesOpen" class="flex w-full items-center justify-between gap-3 px-6 py-3 text-left hover:bg-brand-sand/20 sm:px-8">
            <div>
                <p class="text-sm font-semibold text-brand-ink">{{ __('Quick templates') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Common starter configurations — copy + paste into your repo.') }}</p>
            </div>
            <x-heroicon-o-chevron-down class="h-4 w-4 text-brand-moss transition-transform" x-bind:class="templatesOpen ? 'rotate-180' : ''" />
        </button>
        <div x-show="templatesOpen" x-cloak class="space-y-4 px-6 pb-4 sm:px-8">
            <div class="flex flex-wrap gap-1">
                @foreach ($templates as $key => $template)
                    <button type="button" :class="tpl === '{{ $key }}' ? 'border-brand-ink text-brand-ink bg-brand-sand/50' : 'border-brand-ink/15 text-brand-moss hover:bg-brand-sand/30'" class="rounded-lg border px-3 py-1 text-xs font-medium" @click="tpl = '{{ $key }}'">{{ $template['label'] }}</button>
                @endforeach
            </div>

            @foreach ($templates as $key => $template)
                <div x-show="tpl === '{{ $key }}'" x-cloak>
                    <p class="text-xs text-brand-moss">{{ $template['hint'] }}</p>

                    <div class="mt-2 flex gap-1 border-b border-brand-ink/10">
                        <button type="button" :class="tplFmt === 'wrangler' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="tplFmt = 'wrangler'">{{ __('wrangler.toml') }}</button>
                        <button type="button" :class="tplFmt === 'yaml' ? 'border-brand-ink text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="border-b-2 px-3 py-1 text-xs font-semibold uppercase tracking-wide" @click="tplFmt = 'yaml'">{{ __('dply.yaml') }}</button>
                    </div>

                    <div x-show="tplFmt === 'wrangler'" x-cloak class="mt-2 flex items-start gap-2">
                        <pre class="flex-1 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand" x-ref="tplWrangler{{ $loop->index }}"><code>{{ $template['wrangler'] }}</code></pre>
                        <button type="button" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" @click="copy($refs.tplWrangler{{ $loop->index }}.innerText, $event.currentTarget)">{{ __('Copy') }}</button>
                    </div>
                    <div x-show="tplFmt === 'yaml'" x-cloak class="mt-2 flex items-start gap-2">
                        <pre class="flex-1 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand" x-ref="tplYaml{{ $loop->index }}"><code>{{ $template['yaml'] }}</code></pre>
                        <button type="button" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40" @click="copy($refs.tplYaml{{ $loop->index }}.innerText, $event.currentTarget)">{{ __('Copy') }}</button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
