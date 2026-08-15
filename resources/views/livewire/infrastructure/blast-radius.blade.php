<div>
    <x-infrastructure-shell
        :title="__('Blast radius')"
        :description="__('Map servers, sites, databases, and hybrid Edge ↔ Cloud links. Select a resource to see what else would break if it went down.')"
        :section="__('Blast radius')"
        icon="heroicon-o-share"
    >
        <section class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-infrastructure-stat :label="__('Servers')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['servers'] }}</p>
                </x-infrastructure-stat>
                <x-infrastructure-stat :label="__('Sites')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['sites'] }}</p>
                </x-infrastructure-stat>
                <x-infrastructure-stat :label="__('Databases')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['databases'] }}</p>
                </x-infrastructure-stat>
                <x-infrastructure-stat :label="__('Dependencies')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['links'] }}</p>
                </x-infrastructure-stat>
            </div>
        </section>

        @if ($counts['servers'] + $counts['sites'] + $counts['databases'] === 0)
            <x-infrastructure-empty>{{ __('No inventory to map yet. Connect servers or create Cloud / Edge apps to build a dependency graph.') }}</x-infrastructure-empty>
        @else
            <div class="grid lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] lg:divide-x lg:divide-brand-ink/10">
                <section class="min-w-0 divide-y divide-brand-ink/10">
                    @foreach ([
                        'infrastructure' => __('Infrastructure'),
                        'applications' => __('Applications'),
                        'edge' => __('Edge & CDN'),
                    ] as $layerKey => $layerLabel)
                        @if (count($nodesByLayer[$layerKey]) > 0)
                            <div>
                                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-6">
                                    <h2 class="text-sm font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $layerLabel }}</h2>
                                </div>
                                <ul class="space-y-2 px-5 py-4 sm:px-6">
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
                                                    <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-moss">
                                                        <span class="rounded-full bg-brand-ink/5 px-2 py-0.5 font-medium uppercase tracking-wide">{{ $node['kind'] }}</span>
                                                        @if (($node['product'] ?? null) && $node['kind'] === 'site')
                                                            <span>{{ strtoupper($node['product']) }}</span>
                                                        @endif
                                                        @if ($outbound > 0 || $inbound > 0)
                                                            <span>{{ trans(':out out · :in in', ['out' => $outbound, 'in' => $inbound]) }}</span>
                                                        @endif
                                                    </span>
                                                    @if (! empty($node['external_origin']))
                                                        <span class="mt-1 block truncate font-mono text-xs text-brand-mist">{{ $node['external_origin'] }}</span>
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
                        <div>
                            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Dependency links') }}</h2>
                            </div>
                            <ul class="max-h-64 space-y-1 overflow-y-auto px-5 py-4 text-sm text-brand-moss sm:px-6">
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
                                            <span class="text-xs text-brand-mist"> · {{ $edge['label'] }}</span>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>

                <aside class="min-w-0 lg:sticky lg:top-6 lg:self-start">
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Impact') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Impact simulation') }}</h2>
                            </div>
                        </div>
                    </div>
                    <div class="px-5 py-5 sm:px-6">
                        @if ($focused === null)
                            <p class="text-sm text-brand-moss">{{ __('Select a server, site, or database to preview downstream blast radius.') }}</p>
                        @else
                            <div class="rounded-xl border border-amber-200/80 bg-amber-50/60 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-amber-900">{{ __('If this fails') }}</p>
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
                                                <span class="ml-1 text-xs uppercase text-brand-mist">{{ $node['product'] ?? $node['kind'] }}</span>
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

                    <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-xs leading-relaxed text-brand-moss sm:px-6">
                        {{ __('v1 maps hosting dependencies only — not DNS, external SaaS, or env-var database bindings. Hybrid Edge sites show linked dply Cloud origins when configured.') }}
                    </div>
                </aside>
            </div>
        @endif
    </x-infrastructure-shell>
</div>
