<div class="mx-auto max-w-6xl px-6 py-10">
    @include('livewire.fleet._tabs')

    <header class="mb-6 border-b border-brand-ink/10 pb-4">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Blast radius') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-brand-moss">{{ __('Map servers, sites, databases, and hybrid Edge ↔ Cloud links. Select a resource to see what else would break if it went down.') }}</p>
    </header>

    <section class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Servers') }}</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['servers'] }}</p>
        </div>
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Sites') }}</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['sites'] }}</p>
        </div>
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Databases') }}</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['databases'] }}</p>
        </div>
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Dependencies') }}</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['links'] }}</p>
        </div>
    </section>

    @if ($counts['servers'] + $counts['sites'] + $counts['databases'] === 0)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/40 p-8 text-center text-sm text-brand-moss">
            {{ __('No inventory to map yet. Connect servers or create Cloud / Edge apps to build a dependency graph.') }}
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
            <section class="space-y-6">
                @foreach ([
                    'infrastructure' => __('Infrastructure'),
                    'applications' => __('Applications'),
                    'edge' => __('Edge & CDN'),
                ] as $layerKey => $layerLabel)
                    @if (count($nodesByLayer[$layerKey]) > 0)
                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                            <h2 class="text-sm font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $layerLabel }}</h2>
                            <ul class="mt-3 space-y-2">
                                @foreach ($nodesByLayer[$layerKey] as $node)
                                    @php
                                        $isFocused = $focusNodeId === $node['id'];
                                        $outbound = collect($edges)->where('from', $node['id'])->count();
                                        $inbound = collect($edges)->where('to', $node['id'])->count();
                                    @endphp
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="$set('focusNodeId', @js($isFocused ? '' : $node['id']))"
                                            @class([
                                                'flex w-full items-start justify-between gap-3 rounded-xl border px-3 py-2.5 text-left text-sm transition',
                                                'border-brand-forest bg-brand-sage/10 ring-1 ring-brand-sage/30' => $isFocused,
                                                'border-brand-ink/10 bg-brand-cream/20 hover:border-brand-ink/20 hover:bg-brand-cream/40' => ! $isFocused,
                                            ])
                                        >
                                            <span class="min-w-0">
                                                <span class="font-semibold text-brand-ink">{{ $node['label'] }}</span>
                                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-moss">
                                                    <span class="rounded-full bg-brand-ink/5 px-2 py-0.5 font-medium uppercase tracking-wide">{{ $node['kind'] }}</span>
                                                    @if (($node['product'] ?? null) && $node['kind'] === 'site')
                                                        <span>{{ strtoupper($node['product']) }}</span>
                                                    @endif
                                                    @if ($outbound > 0 || $inbound > 0)
                                                        <span>{{ trans(':out out · :in in', ['out' => $outbound, 'in' => $inbound]) }}</span>
                                                    @endif
                                                </span>
                                                @if (! empty($node['external_origin']))
                                                    <span class="mt-1 block truncate font-mono text-[11px] text-brand-mist">{{ $node['external_origin'] }}</span>
                                                @endif
                                            </span>
                                            @if ($isFocused)
                                                <x-heroicon-o-check-circle class="h-5 w-5 shrink-0 text-brand-forest" />
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach

                @if ($edges !== [])
                    <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Dependency links') }}</h2>
                        <ul class="mt-3 max-h-64 space-y-1 overflow-y-auto text-sm text-brand-moss">
                            @foreach ($edges as $edge)
                                @php
                                    $from = $graph->node($edge['from']);
                                    $to = $graph->node($edge['to']);
                                @endphp
                                @if ($from && $to)
                                    <li class="rounded-lg bg-brand-cream/30 px-3 py-1.5">
                                        <span class="font-medium text-brand-ink">{{ $from['label'] }}</span>
                                        <span class="text-brand-mist"> → </span>
                                        <span class="font-medium text-brand-ink">{{ $to['label'] }}</span>
                                        <span class="text-[11px] text-brand-mist"> · {{ $edge['label'] }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Impact simulation') }}</h2>
                    @if ($focused === null)
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Select a server, site, or database to preview downstream blast radius.') }}</p>
                    @else
                        <div class="mt-3 rounded-xl border border-amber-200/80 bg-amber-50/60 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-amber-900">{{ __('If this fails') }}</p>
                            <p class="mt-1 text-base font-semibold text-brand-ink">{{ $focused['label'] }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ ucfirst($focused['kind']) }} · {{ strtoupper($focused['product'] ?? 'byo') }}</p>
                        </div>

                        @if ($affected === [])
                            <p class="mt-4 text-sm text-emerald-800">{{ __('No mapped dependents — nothing else in dply inventory would break directly.') }}</p>
                        @else
                            <p class="mt-4 text-sm font-medium text-brand-ink">{{ trans_choice(':count dependent resource would be affected|:count dependent resources would be affected', count($affected), ['count' => count($affected)]) }}</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($affected as $node)
                                    <li class="flex items-center justify-between gap-2 rounded-lg border border-brand-ink/10 bg-brand-cream/30 px-3 py-2 text-sm">
                                        <span>
                                            <span class="font-medium text-brand-ink">{{ $node['label'] }}</span>
                                            <span class="ml-1 text-[11px] uppercase text-brand-mist">{{ $node['product'] ?? $node['kind'] }}</span>
                                        </span>
                                        @if (! empty($node['href']))
                                            <a href="{{ $node['href'] }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-forest hover:underline">{{ __('Open') }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if (! empty($focused['href']))
                            <a href="{{ $focused['href'] }}" wire:navigate class="mt-4 inline-flex text-sm font-semibold text-brand-forest hover:underline">{{ __('Open selected resource') }} →</a>
                        @endif
                    @endif
                </div>

                <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/30 p-4 text-xs leading-relaxed text-brand-moss">
                    {{ __('v1 maps hosting dependencies only — not DNS, external SaaS, or env-var database bindings. Hybrid Edge sites show linked dply Cloud origins when configured.') }}
                </div>
            </aside>
        </div>
    @endif
</div>
