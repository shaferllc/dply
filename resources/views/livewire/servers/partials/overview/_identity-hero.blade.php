@php
    $healthStatus = $healthSummary['status'];
    $healthLabel = match ($healthStatus) {
        \App\Models\Server::HEALTH_REACHABLE => __('Reachable'),
        \App\Models\Server::HEALTH_UNREACHABLE => __('Needs attention'),
        default => __('No health check yet'),
    };
    $healthDot = $healthStatus === \App\Models\Server::HEALTH_REACHABLE
        ? 'bg-emerald-500'
        : ($healthStatus === \App\Models\Server::HEALTH_UNREACHABLE ? 'bg-rose-500' : 'bg-brand-gold');
    $healthRecheckPending = $healthSummary['recheck_pending'] ?? false;

    $heroProvider = $server->provider->label();
    $heroRegion = $server->region ?: '—';
    $heroIp = $server->public_ip_address ?? $server->ip_address ?? '—';
    $heroSize = $server->size ?? '—';
    $heroStatus = ucfirst(str_replace('_', ' ', (string) ($server->status ?? '—')));

    // Same tokens the site Overview uses (general-tab.blade.php) so the two
    // overviews read as one design instead of two: compact panel head, then a
    // grid of small bordered fact cells. Replaces the old split hero — a big
    // icon badge with an "SERVER" eyebrow on the left, a bordered spec table on
    // the right — which needed ~200px to say what the grid says in ~110px.
    // Joined hairline tiles, exactly as the site Overview builds them: one
    // rounded container with a tinted background showing through 1px grid gaps,
    // white cells that tint on hover. Not the separate rounded chips this file
    // had — general-tab.blade.php literally describes its grid as mirroring
    // "the server hero facts card", so the two had drifted apart.
    $factCell = 'bg-white px-3 py-2 transition-colors hover:bg-brand-sand/[0.15] sm:px-4';
    $factLabel = 'text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist';
    $factValue = 'mt-1 truncate text-sm font-medium text-brand-ink';

    $heroFacts = [];
    if ($isWorkerRoleHost) {
        $heroFacts[] = ['label' => __('Role'), 'value' => __('Worker')];
    }
    $heroFacts = array_merge($heroFacts, [
        ['label' => __('Provider'), 'value' => $heroProvider],
        ['label' => __('Region'), 'value' => $heroRegion],
        ['label' => __('Size'), 'value' => $heroSize, 'mono' => true],
        ['label' => __('Status'), 'value' => $heroStatus],
        ['label' => __('IP'), 'value' => $heroIp, 'mono' => true, 'select' => true, 'copy' => true],
    ]);

    if ($server->private_ip_address) {
        $heroFacts[] = ['label' => __('Private IP'), 'value' => $server->private_ip_address, 'mono' => true, 'select' => true, 'copy' => true];
    }

    $heroFacts[] = ['label' => __('SSH'), 'value' => $server->getSshConnectionString(), 'mono' => true, 'select' => true, 'copy' => true];
@endphp

{{-- Hero: server identity + facts. --}}
<section class="dply-card overflow-hidden p-0">
    {{-- Dense head: the reachability line is reference, not something to read
         now, so it rides beside the name instead of taking its own row. --}}
    <x-workspace-panel-head
        dense
        :icon="$isWorkerRoleHost ? 'heroicon-o-square-3-stack-3d' : 'heroicon-o-server-stack'"
        :title="$server->name"
        :count="$isWorkerRoleHost ? __('Worker server') : null"
        :note="$isWorkerRoleHost
            ? ($workerHost->originSite
                ? __('Queue workers for :site — not a public web front.', ['site' => $workerHost->originSite->name])
                : __('Queue workers from deployed code — not a public web front.'))
            : ($healthSummary['last_checked_at']
                ? __('Reachability checked :ago.', ['ago' => $healthSummary['last_checked_at']->diffForHumans()])
                : __('No reachability check has run for this machine yet.'))"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="recheckHealth"
                @if ($healthRecheckPending) wire:poll.4s @endif
                @disabled($healthRecheckPending)
                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-default disabled:hover:bg-white"
                title="{{ __('Re-run the reachability check now') }}"
            >
                @if ($healthRecheckPending)
                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin text-brand-mist" aria-hidden="true" />
                    {{ __('Checking…') }}
                @else
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $healthDot }}"></span>
                    {{ $healthLabel }}
                @endif
            </button>
            @feature('workspace.services')
                <a href="{{ route('servers.services', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    <x-heroicon-o-squares-2x2 class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                    {{ __('Services') }}
                </a>
            @endfeature
        </x-slot:actions>
    </x-workspace-panel-head>

    @include('livewire.servers.partials._worker-host-banner')

    <div class="px-3 py-3 sm:px-4">
        <dl class="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-ink/[0.07] shadow-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($heroFacts as $fact)
                <div @class([$factCell, 'group flex items-center justify-between gap-3' => $fact['copy'] ?? false])>
                    <div class="min-w-0">
                        <dt class="{{ $factLabel }}">{{ $fact['label'] }}</dt>
                        <dd @class([
                            $factValue,
                            'font-mono' => $fact['mono'] ?? false,
                            'select-all' => $fact['select'] ?? false,
                        ]) title="{{ $fact['value'] }}">{{ $fact['value'] }}</dd>
                    </div>
                    @if ($fact['copy'] ?? false)
                        {{-- Same copy affordance the site Overview puts on its testing URL. --}}
                        <button
                            type="button"
                            x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($fact['value'])); this.copied = true; setTimeout(() => { this.copied = false; }, 1500); } }"
                            x-on:click.stop="copy()"
                            :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"
                            class="shrink-0 rounded-lg border border-brand-ink/10 bg-white p-1.5 text-brand-mist shadow-sm transition hover:border-brand-ink/20 hover:text-brand-ink"
                        >
                            <x-heroicon-o-clipboard x-show="!copied" class="h-4 w-4" aria-hidden="true" />
                            <x-heroicon-s-check x-show="copied" x-cloak class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                        </button>
                    @endif
                </div>
            @endforeach
        </dl>

        {{-- Installed runtime, incorporated into the identity block rather than a
             standalone card: database / language / webserver / cache. --}}
        @php
            $hasRuntimeChips = ! $isDedicatedServiceRoleHost && (
                $installedStack->database
                || $installedStack->phpVersion
                || $installedStack->webserver
                || ($installedStack->cacheService && $installedStack->cacheService !== 'none')
            );
        @endphp
        @if ($hasRuntimeChips)
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                <span class="{{ $factLabel }} mr-1">{{ __('Stack') }}</span>
                @if ($installedStack->database)
                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink">
                        {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion)<span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->databaseVersion }}</span>@endif
                    </span>
                @endif
                @if ($installedStack->phpVersion)
                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink">
                        PHP <span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->phpVersion }}</span>
                    </span>
                @endif
                @if ($installedStack->webserver && ! $isWorkerRoleHost)
                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink">
                        {{ str($installedStack->webserver)->headline() }}
                    </span>
                @endif
                @if ($installedStack->cacheService && $installedStack->cacheService !== 'none')
                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink">
                        {{ str($installedStack->cacheService)->headline() }}
                    </span>
                @endif
                @if ($installedStack->lowMemoryMode)
                    <span class="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800" title="{{ __('Provisioned in low-memory mode — substituted lighter services where possible.') }}">
                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Low-memory mode') }}
                    </span>
                @endif
            </div>

            @if ($installedStack->lowMemoryMode)
                <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs leading-relaxed text-amber-900">
                    @if ($installedStackDiverges)
                        {{ __('Low-memory mode: :memMb MB RAM is under the 1 GB threshold, so SQLite was installed instead of :requested. Re-provision on a 2 GB+ droplet for a full database server — see journey for details.', [
                            'memMb' => $installedStack->totalMemoryMb ?: '<1024',
                            'requested' => str($server->meta['database'] ?? 'a database server')->headline(),
                        ]) }}
                    @else
                        {{ __('Low-memory mode: :memMb MB RAM is under the 1 GB threshold, so lighter services were substituted. Re-provision on a 2 GB+ droplet for the full stack — see journey for details.', [
                            'memMb' => $installedStack->totalMemoryMb ?: '<1024',
                        ]) }}
                    @endif
                </p>
            @elseif ($installedStackDiverges)
                <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs leading-relaxed text-amber-900">
                    {{ __('Wizard requested :requested but :installed was installed instead. See journey for context.', [
                        'requested' => $server->meta['database'] ?? '—',
                        'installed' => $installedStack->database ?? '—',
                    ]) }}
                </p>
            @endif
        @endif
    </div>

    {{-- Merged: workspace summary tiles live in the same card as the identity,
         so the overview opens with a single header card instead of two. --}}
    @unless ($isDedicatedServiceRoleHost)
        <x-workspace-panel-head
            dense
            icon="heroicon-o-squares-2x2"
            :title="__('Workspace summary')"
            :note="__('Each tile drops you onto its full workspace page.')"
            class="border-y border-brand-ink/10"
        />
        <div class="px-3 py-2.5 sm:px-4">
            @include('livewire.servers.partials.overview._summary-tiles-grid')
        </div>
    @endunless

    {{-- Merged: live system load (CPU / memory / disk) lives in the header card too. --}}
    <div class="border-t border-brand-ink/10 px-3 py-2.5 sm:px-4">
        @include('livewire.servers.partials.overview._live-metrics-body')
    </div>
</section>
