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
    $isFailed = $site->status === \App\Models\Site::STATUS_FUNCTIONS_FAILED;
    $statusBadgeClass = match (true) {
        $isActive => 'bg-brand-forest/15 text-brand-forest',
        $isFailed => 'bg-rose-100 text-rose-800',
        default => 'bg-brand-gold/20 text-brand-ink',
    };
    $statusLabel = match (true) {
        $isActive => __('Live'),
        $isFailed => __('Failed — not live'),
        default => __('Configured — deploying'),
    };
    $costEstimate = app(\App\Modules\Serverless\Services\ServerlessCostEstimator::class)->forSite($site);

    // Status pill summarising the routing surface — links to the full
    // page so Overview stays a glance, not an editor.
    $dnsState = is_array($serverless['dns'] ?? null) ? $serverless['dns'] : [];
    $dnsStatus = (string) ($dnsState['status'] ?? 'pending');
    $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];
    $customDomainCount = is_array($routing['custom_domains'] ?? null)
        ? count(array_filter($routing['custom_domains'], fn ($d) => is_array($d) && ($d['dns_status'] ?? null) === 'ready'))
        : 0;

    $strip = 'border-t border-brand-ink/10 px-4 py-3 sm:px-5';
    $statLabel = 'text-2xs font-semibold uppercase tracking-wide text-brand-moss/70';
@endphp

{{-- Merged chrome: one outer card, sand identity header, every section a
     hairline strip — the function summary, cost, and the four resource panels
     used to be five floating cards stacked on a space-y-6 rhythm. --}}
<section class="dply-card min-w-0 overflow-hidden p-0">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-bolt"
        :title="__('Function')"
        :note="__('HTTP-triggered function.')"
    >
        <x-slot:actions>
            <span class="inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-2xs font-semibold {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
        </x-slot:actions>
    </x-workspace-panel-head>

    {{-- URL + the actions that belong to it, on one line. --}}
    <div class="px-4 py-3 sm:px-5" x-data="{ copied: false }">
        <div class="flex items-center gap-2">
            <span class="shrink-0 rounded-md bg-brand-sand/70 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('URL') }}</span>
            <a href="{{ $friendlyUrl }}" target="_blank" rel="noopener noreferrer"
               class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink underline-offset-2 hover:text-brand-sage hover:underline"
               title="{{ $friendlyUrl }}">{{ preg_replace('#^https?://#', '', $friendlyUrl) }}</a>
            <button type="button"
                    x-on:click="navigator.clipboard.writeText(@js($friendlyUrl)); copied = true; setTimeout(() => copied = false, 1500)"
                    class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                    :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy URL') }}'">
                <x-heroicon-o-clipboard class="h-4 w-4" aria-hidden="true" />
            </button>
            <a href="{{ $friendlyUrl }}" target="_blank" rel="noopener noreferrer"
               title="{{ __('Open function') }}"
               class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink">
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
            </a>
            <span x-show="copied" x-cloak class="shrink-0 text-2xs font-medium text-brand-forest">{{ __('Copied') }}</span>
        </div>
        @if ($invocationUrl !== '')
            <p class="mt-1 truncate text-2xs text-brand-moss/60" title="{{ $invocationUrl }}">{{ __('Direct:') }} <span class="font-mono">{{ $invocationUrl }}</span></p>
        @else
            <p class="mt-1 text-2xs text-brand-moss/60">{{ __('Live once the first deploy completes.') }}</p>
        @endif
    </div>

    {{-- Combined stat strip rather than a card of its own. --}}
    <dl class="{{ $strip }} grid grid-cols-2 gap-x-4 gap-y-2.5 sm:grid-cols-4">
        <div class="min-w-0">
            <dt class="{{ $statLabel }}">{{ __('Runtime') }}</dt>
            <dd class="mt-0.5 truncate text-xs text-brand-ink">{{ $runtime !== '' ? $runtime : '—' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="{{ $statLabel }}">{{ __('Repository') }}</dt>
            <dd class="mt-0.5 truncate text-xs text-brand-ink" title="{{ $site->git_repository_url }}">{{ $site->git_repository_url ?: '—' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="{{ $statLabel }}">{{ __('Last deployed') }}</dt>
            <dd class="mt-0.5 truncate text-xs text-brand-ink">
                {{ $lastDeployedAt ? \Illuminate\Support\Carbon::parse($lastDeployedAt)->diffForHumans() : __('Never') }}
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="{{ $statLabel }}">{{ __('Revision') }}</dt>
            <dd class="mt-0.5 truncate text-xs text-brand-ink">{{ $revision !== '' ? $revision : '—' }}</dd>
        </div>
    </dl>

    <a href="{{ route('sites.routing', ['server' => $server, 'site' => $site]) }}"
       wire:navigate
       class="{{ $strip }} flex items-center justify-between gap-3 transition-colors hover:bg-brand-sand/20">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Routing') }}</p>
            <p class="mt-0.5 text-xs text-brand-moss">
                @php
                    $dnsBadge = match ($dnsStatus) {
                        'ready' => 'bg-emerald-100 text-emerald-900',
                        'failed' => 'bg-rose-100 text-rose-900',
                        default => 'bg-amber-100 text-amber-900',
                    };
                @endphp
                <span class="inline-flex items-center rounded-full {{ $dnsBadge }} px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em]">{{ $dnsStatus }}</span>
                <span class="ml-1">{{ __('DNS') }}</span>
                @if ($customDomainCount > 0)
                    <span class="mx-1.5 text-brand-mist">·</span>
                    <span>{{ trans_choice('{1} :count custom domain|[2,*] :count custom domains', $customDomainCount, ['count' => $customDomainCount]) }}</span>
                @endif
            </p>
        </div>
        <x-heroicon-o-arrow-right class="h-4 w-4 shrink-0 text-brand-moss" />
    </a>

    {{-- Cost: total on the header line, lines underneath — no second card. --}}
    <div class="{{ $strip }}">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="{{ $statLabel }}">{{ __('Cost estimate') }}</p>
            <p class="text-sm font-bold text-brand-ink">≈ ${{ number_format($costEstimate['total'], 2) }}{{ __('/mo') }}</p>
        </div>
        <dl class="mt-1.5 divide-y divide-brand-ink/8">
            @foreach ($costEstimate['lines'] as $line)
                <div class="flex items-center justify-between gap-3 py-1.5 text-xs">
                    <dt class="min-w-0 truncate text-brand-moss">
                        {{ $line['label'] }}
                        <span class="text-brand-moss/50">· {{ __('billed by :who', ['who' => $line['billed_by']]) }}</span>
                    </dt>
                    <dd class="shrink-0 font-medium tabular-nums text-brand-ink">${{ number_format($line['amount'], 2) }}{{ __('/mo') }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-1.5 text-2xs text-brand-moss/60">
            {{ __('Estimated. dply bills the function fee; DigitalOcean bills any database or Redis clusters directly. Function invocation usage is metered separately by DigitalOcean.') }}
        </p>
    </div>

    {{-- Resource panels render their own hairline strips (embedded chrome), so
         they continue the card rather than stacking four more floating cards. --}}
    @livewire('serverless.database-panel', ['site' => $site], key('serverless-db-'.$site->id))
    @livewire('serverless.cache-panel', ['site' => $site], key('serverless-cache-'.$site->id))
    @livewire('serverless.background-panel', ['site' => $site], key('serverless-bg-'.$site->id))
    @livewire('serverless.rollback-panel', ['site' => $site], key('serverless-rollback-'.$site->id))

    <div class="flex flex-wrap items-center gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-4 py-2.5 sm:px-5">
        @if ($isActive)
            {{-- The function is live — redeploys + history belong on the
                 Deployments tab. Overview stays a glance, not an action bar. --}}
            <a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site]) }}"
               wire:navigate
               class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-forest hover:underline">
                {{ __('Manage deploys') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" />
            </a>
        @else
            {{-- Not live yet — the first deploy is the operator's next step. --}}
            <button type="button"
                    wire:click="redeployServerlessFunction"
                    wire:loading.attr="disabled"
                    wire:target="redeployServerlessFunction"
                    class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60">
                <span wire:loading.remove wire:target="redeployServerlessFunction">{{ $isFailed ? __('Retry deploy') : __('Deploy function') }}</span>
                <span wire:loading wire:target="redeployServerlessFunction">{{ __('Starting deploy…') }}</span>
            </button>
            <a href="{{ \App\Support\Serverless\ServerlessWorkspaceUrl::journey($site) }}"
               class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                {{ __('Deploy journey') }}
            </a>
        @endif
    </div>
</section>
