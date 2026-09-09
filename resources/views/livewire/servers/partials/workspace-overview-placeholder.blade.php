{{--
    Lazy-load skeleton for Overview. Mirrors _identity-hero exactly: dense panel
    head, the hairline fact grid, then the role-dependent bands. Keep the two in
    step — this file previously mirrored the old split hero (big icon badge +
    "SERVER" eyebrow + bordered spec table) that _identity-hero replaced, so the
    page visibly jumped from a tall skeleton to a compact card on load.

    Role matters: a dedicated cache/database host renders no Workspace summary
    band at all (see the @unless in _identity-hero), so neither does its skeleton.
--}}
@php
    // Same derivation as workspace-overview.blade.php / WorkspaceOverview::render.
    $serverRole = (string) ($server->meta['server_role'] ?? '');
    $isDedicatedServiceRoleHost = in_array($serverRole, ['redis', 'valkey', 'database'], true);
    $isWorkerRoleHost = $serverRole === 'worker';

    // Mirrors the $heroFacts list: Role (worker) / Provider / Region / Size / Status / IP,
    // plus Private IP when set, plus SSH.
    $factLabels = $isWorkerRoleHost ? [__('Role')] : [];
    $factLabels = array_merge($factLabels, [__('Provider'), __('Region'), __('Size'), __('Status'), __('IP')]);
    if ($server->private_ip_address) {
        $factLabels[] = __('Private IP');
    }
    $factLabels[] = __('SSH');

    $factCell = 'bg-white px-3 py-2 sm:px-4';
    $factLabel = 'text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="overview"
    :title="__('Overview')"
    hide-hero
>
    <div class="space-y-4" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading overview…') }}</span>

        <section class="dply-card overflow-hidden p-0" aria-hidden="true">
            {{-- Dense head: real icon + name (both known before load), pulsing
                 stand-ins for the note and the two action pills. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                <h2 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                    @if ($isWorkerRoleHost)
                        <x-heroicon-o-square-3-stack-3d class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    @else
                        <x-heroicon-o-server-stack class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    @endif
                    {{ $server->name }}
                </h2>
                @if ($isWorkerRoleHost)
                    <span class="inline-flex shrink-0 items-center rounded-full bg-white px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ __('Worker server') }}</span>
                @endif
                <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                <span class="h-3 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
                <div class="ml-auto flex shrink-0 items-center gap-1.5">
                    <span class="h-6 w-32 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    <span class="h-6 w-20 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </div>
            </div>

            <div class="px-3 py-3 sm:px-4">
                <dl class="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-ink/[0.07] shadow-sm sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($factLabels as $label)
                        <div class="{{ $factCell }}">
                            <dt class="{{ $factLabel }}">{{ $label }}</dt>
                            <dd class="mt-1 h-5 w-24 animate-pulse rounded bg-brand-ink/10"></dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            @unless ($isDedicatedServiceRoleHost)
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-y border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <h2 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                        <x-heroicon-o-squares-2x2 class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('Workspace summary') }}
                    </h2>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                    <p class="min-w-0 flex-1 truncate text-xs text-brand-mist">{{ __('Each tile drops you onto its full workspace page.') }}</p>
                </div>
                <div class="px-3 py-2.5 sm:px-4">
                    {{-- Joined hairline cells, matching _summary-tiles-grid. --}}
                    <div class="grid gap-px overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-ink/[0.07] shadow-sm sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                        @foreach (range(1, 5) as $tile)
                            <div class="bg-white px-3 py-2 sm:px-4">
                                <div class="h-2.5 w-14 animate-pulse rounded bg-brand-ink/10"></div>
                                <div class="mt-1.5 h-4 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                                <div class="mt-1 h-3 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endunless

            {{-- Live system load band, same slot as _live-metrics-body. --}}
            <div class="border-t border-brand-ink/10 px-3 py-2.5 sm:px-4">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <div class="h-2.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-6 w-28 animate-pulse rounded-lg bg-brand-ink/10"></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach (range(1, 3) as $metric)
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                            <div class="h-2.5 w-10 animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="mt-2 h-6 w-14 animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="mt-2 h-1.5 w-full animate-pulse rounded-full bg-brand-ink/10"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Below the hero the page is a stack of conditional cards (checklist,
             SSH reminder, role tile pack, shortcuts). One card-shaped block
             stands in for whichever lands, rather than a fixed six-cell grid
             that matched nothing. --}}
        <section class="dply-card overflow-hidden p-0" aria-hidden="true">
            <div class="flex items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                <span class="h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                <span class="h-3 w-44 animate-pulse rounded bg-brand-ink/10"></span>
            </div>
            <div class="space-y-2 px-3 py-3 sm:px-4">
                @foreach (range(1, 3) as $row)
                    <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                        <span class="h-4 w-4 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1">
                            <div class="h-3 w-40 animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="mt-1.5 h-2.5 w-64 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                        <span class="h-6 w-24 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-server-workspace-layout>
