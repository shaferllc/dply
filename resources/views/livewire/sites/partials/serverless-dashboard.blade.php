@php
    $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
    $invocationUrl = trim((string) ($serverless['action_url'] ?? ''));
    $functionHost = $site->serverlessFunctionHost();
    $friendlyUrl = $functionHost !== null
        ? 'https://'.$functionHost
        : url('fn/'.$site->ensureServerlessProxySlug());
    $runtime = trim((string) ($serverless['runtime'] ?? ''));
    $lastDeployedAt = $serverless['last_deployed_at'] ?? null;
    $revision = trim((string) ($serverless['last_revision_id'] ?? ''));
    $isActive = $site->status === \App\Models\Site::STATUS_FUNCTIONS_ACTIVE;
    $statusBadgeClass = $isActive ? 'bg-brand-forest/15 text-brand-forest' : 'bg-brand-gold/20 text-brand-ink';
    $statusLabel = $isActive ? __('Live') : __('Configured — deploying');
    $costEstimate = app(\App\Services\Serverless\ServerlessCostEstimator::class)->forSite($site);
@endphp

<section class="dply-card p-6 sm:p-8 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Serverless') }}</p>
            <h2 class="mt-1 text-lg font-bold text-brand-ink">{{ __('Function') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('HTTP-triggered function on DigitalOcean Functions.') }}</p>
        </div>
        <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
    </div>

    <div x-data="{ copied: false }">
        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss/70">{{ __('Function URL') }}</p>
        <div class="mt-1 flex items-center gap-2">
            <code class="flex-1 min-w-0 truncate rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 text-sm text-brand-ink">{{ $friendlyUrl }}</code>
            <button type="button"
                    x-on:click="navigator.clipboard.writeText(@js($friendlyUrl)); copied = true; setTimeout(() => copied = false, 1500)"
                    class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                <span x-show="!copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </button>
            <a href="{{ $friendlyUrl }}" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                {{ __('Open') }}
            </a>
        </div>
        @if ($invocationUrl !== '')
            <p class="mt-1.5 truncate text-xs text-brand-moss/60">{{ __('Direct:') }} <span class="font-mono">{{ $invocationUrl }}</span></p>
        @else
            <p class="mt-1.5 text-xs text-brand-moss/60">{{ __('Live once the first deploy completes.') }}</p>
        @endif
    </div>

    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4 text-sm">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss/70">{{ __('Runtime') }}</dt>
            <dd class="mt-0.5 text-brand-ink">{{ $runtime !== '' ? $runtime : '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss/70">{{ __('Repository') }}</dt>
            <dd class="mt-0.5 truncate text-brand-ink">{{ $site->git_repository_url ?: '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss/70">{{ __('Last deployed') }}</dt>
            <dd class="mt-0.5 text-brand-ink">
                {{ $lastDeployedAt ? \Illuminate\Support\Carbon::parse($lastDeployedAt)->diffForHumans() : __('Never') }}
            </dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss/70">{{ __('Revision') }}</dt>
            <dd class="mt-0.5 text-brand-ink">{{ $revision !== '' ? $revision : '—' }}</dd>
        </div>
    </dl>

    <div class="flex flex-wrap items-center gap-3 pt-1">
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}"
           class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
            {{ __('Deploy / redeploy') }}
        </a>
        <a href="{{ route('serverless.journey', ['server' => $server, 'site' => $site]) }}"
           class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
            {{ __('Deploy journey') }}
        </a>
    </div>
</section>

<div class="dply-card mt-6 p-6 sm:p-8">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Cost estimate') }}</p>
            <h2 class="mt-1 text-lg font-bold text-brand-ink">≈ ${{ number_format($costEstimate['total'], 2) }}{{ __('/mo') }}</h2>
        </div>
    </div>
    <dl class="mt-3 divide-y divide-brand-ink/10">
        @foreach ($costEstimate['lines'] as $line)
            <div class="flex items-center justify-between py-2 text-sm">
                <dt class="text-brand-moss">
                    {{ $line['label'] }}
                    <span class="text-brand-moss/50">· {{ __('billed by :who', ['who' => $line['billed_by']]) }}</span>
                </dt>
                <dd class="font-medium text-brand-ink">${{ number_format($line['amount'], 2) }}{{ __('/mo') }}</dd>
            </div>
        @endforeach
    </dl>
    <p class="mt-3 text-xs text-brand-moss/60">
        {{ __('Estimated. dply bills the function fee; DigitalOcean bills any database or Redis clusters directly. Function invocation usage is metered separately by DigitalOcean.') }}
    </p>
</div>

<div class="mt-6">
    @livewire('serverless.database-panel', ['site' => $site], key('serverless-db-'.$site->id))
</div>

<div class="mt-6">
    @livewire('serverless.cache-panel', ['site' => $site], key('serverless-cache-'.$site->id))
</div>

<div class="mt-6">
    @livewire('serverless.background-panel', ['site' => $site], key('serverless-bg-'.$site->id))
</div>

<div class="mt-6">
    @livewire('serverless.rollback-panel', ['site' => $site], key('serverless-rollback-'.$site->id))
</div>
