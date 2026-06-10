<div class="min-h-screen text-brand-ink">
    {{-- Cream canvas + mesh gradient. The root div must stay transparent so the
         fixed -z-10 mesh shows through (an opaque bg here would cover it). --}}
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header active="roadmap" />

    @php
        // Per-status visual treatment for the board columns + hero stats.
        $statusMeta = [
            'planned' => [
                'icon'  => 'heroicon-o-clock',
                'bar'   => 'bg-brand-mist/60',
                'soft'  => 'bg-brand-mist/15 text-brand-moss',
                'count' => 'bg-brand-sand/70 text-brand-moss',
                'rail'  => 'bg-brand-mist/50',
                'dot'   => 'bg-brand-mist',
                'tint'  => '',
            ],
            'in_progress' => [
                'icon'  => 'heroicon-o-bolt',
                'bar'   => 'bg-brand-gold',
                'soft'  => 'bg-brand-gold/15 text-amber-700',
                'count' => 'bg-brand-gold/20 text-amber-700',
                'rail'  => 'bg-brand-gold',
                'dot'   => 'bg-brand-gold',
                'tint'  => 'bg-brand-gold/[0.04]',
            ],
            'shipped' => [
                'icon'  => 'heroicon-o-check-circle',
                'bar'   => 'bg-brand-forest',
                'soft'  => 'bg-brand-forest/10 text-brand-forest',
                'count' => 'bg-brand-forest/10 text-brand-forest',
                'rail'  => 'bg-brand-forest',
                'dot'   => 'bg-brand-forest',
                'tint'  => '',
            ],
        ];
        $metaFallback = [
            'icon'  => 'heroicon-o-square-3-stack-3d',
            'bar'   => 'bg-brand-sage', 'soft' => 'bg-brand-sage/15 text-brand-forest',
            'count' => 'bg-brand-sand/70 text-brand-moss', 'rail' => 'bg-brand-sage',
            'dot'   => 'bg-brand-sage', 'tint' => '',
        ];

        // Per-area accent for card labels (brand palette only).
        $areaAccent = [
            'platform'   => 'text-brand-forest',
            'servers'    => 'text-brand-sage',
            'edge'       => 'text-brand-gold',
            'cloud'      => 'text-brand-rust',
            'serverless' => 'text-brand-moss',
            'other'      => 'text-brand-mist',
        ];

        $statusCounts = [];
        foreach ($statusLabels as $statusKey => $statusLabel) {
            $statusCounts[$statusKey] = ($itemsByStatus[$statusKey] ?? collect())->count();
        }
        $totalItems = array_sum($statusCounts);
    @endphp

    <main class="px-4 pb-20 sm:px-6 lg:px-8">
        {{-- Hero --}}
        <section class="relative pt-16 pb-10 sm:pt-24">
            <div class="mx-auto max-w-3xl text-center">
                <p class="inline-flex items-center gap-2 rounded-full border border-brand-sage/25 bg-white/60 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-brand-forest">
                    <span class="relative flex h-1.5 w-1.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-gold opacity-60"></span>
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-brand-gold"></span>
                    </span>
                    {{ __('Product direction') }}
                </p>
                <h1 class="mt-8 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">
                    {{ __('Product roadmap') }}
                </h1>
                <p class="mt-5 text-lg leading-relaxed text-brand-moss">
                    {{ __('What we are building next across the dply platform — planned, in flight, and shipped. This board is read-only; share ideas with the form below.') }}
                </p>

                {{-- Status stat pills --}}
                @if ($totalItems > 0)
                    <div class="mt-9 flex flex-wrap items-center justify-center gap-2.5">
                        @foreach ($statusLabels as $statusKey => $statusLabel)
                            @php $meta = $statusMeta[$statusKey] ?? $metaFallback; @endphp
                            <span class="inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-white/70 px-4 py-1.5 text-sm font-semibold text-brand-ink backdrop-blur-sm">
                                <span class="h-2 w-2 rounded-full {{ $meta['dot'] }}"></span>
                                <span class="tabular-nums">{{ $statusCounts[$statusKey] }}</span>
                                <span class="font-medium text-brand-moss">{{ __($statusLabel) }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="mx-auto max-w-7xl">

            {{-- Recently shipped highlights --}}
            @if ($recentlyShipped->isNotEmpty())
                <section class="mt-6 overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-white/90 to-brand-sage/[0.06] p-6 shadow-sm sm:p-8">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest">
                            <x-heroicon-o-rocket-launch class="h-5 w-5" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-forest">{{ __('Recently shipped') }}</p>
                            <p class="text-sm text-brand-moss">{{ __('Latest capabilities that landed on the platform.') }}</p>
                        </div>
                    </div>
                    <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($recentlyShipped as $shippedItem)
                            <li wire:key="recently-shipped-{{ $shippedItem->id }}"
                                class="group relative overflow-hidden rounded-2xl border border-brand-ink/10 bg-white/90 p-5 shadow-sm transition-shadow hover:shadow-md">
                                <span class="absolute inset-y-0 left-0 w-1 bg-brand-forest" aria-hidden="true"></span>
                                <div class="flex flex-wrap items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.14em]">
                                    @if ($shippedItem->areaLabel())
                                        <span class="{{ $areaAccent[$shippedItem->area] ?? 'text-brand-sage' }}">{{ $shippedItem->areaLabel() }}</span>
                                    @endif
                                    @if ($shippedItem->shippedRelease)
                                        <span class="text-brand-mist">·</span>
                                        <span class="text-brand-sage">{{ $shippedItem->shippedRelease->trainLabel() }}</span>
                                    @endif
                                </div>
                                <h3 class="mt-1.5 font-semibold leading-snug text-brand-ink">{{ $shippedItem->title }}</h3>
                                @if ($shippedItem->summary)
                                    <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $shippedItem->summary }}</p>
                                @endif
                                @if ($shippedItem->shipped_at)
                                    <p class="mt-3 inline-flex items-center gap-1 text-xs text-brand-mist">
                                        <x-heroicon-m-check-circle class="h-3.5 w-3.5 text-brand-forest" />
                                        {{ __('Shipped :date', ['date' => $shippedItem->shipped_at->format('M j, Y')]) }}
                                    </p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Filters --}}
            <div class="mt-10 rounded-2xl border border-brand-ink/10 bg-white/70 p-4 shadow-sm backdrop-blur-sm sm:p-5">
                @if ($publishedReleaseTrains->isNotEmpty())
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <p class="w-28 shrink-0 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Release') }}</p>
                        <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Filter by release train') }}">
                            <button
                                type="button"
                                wire:click="$set('release', 'all')"
                                @class([
                                    'rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
                                    $release === 'all'
                                        ? 'border-brand-forest bg-brand-forest text-white shadow-sm'
                                        : 'border-brand-ink/15 bg-white/80 text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink',
                                ])
                            >
                                {{ __('All trains') }}
                            </button>
                            @foreach ($publishedReleaseTrains as $releaseTrain)
                                <button
                                    type="button"
                                    wire:click="$set('release', '{{ $releaseTrain->id }}')"
                                    wire:key="roadmap-release-{{ $releaseTrain->id }}"
                                    @class([
                                        'rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
                                        $release === $releaseTrain->id
                                            ? 'border-brand-forest bg-brand-forest text-white shadow-sm'
                                            : 'border-brand-ink/15 bg-white/80 text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink',
                                    ])
                                >
                                    {{ $releaseTrain->trainLabel() }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="my-3 border-t border-brand-ink/5"></div>
                @endif

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <p class="w-28 shrink-0 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Area') }}</p>
                    <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Filter by product area') }}">
                        <button
                            type="button"
                            wire:click="$set('area', 'all')"
                            @class([
                                'rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
                                $area === 'all'
                                    ? 'border-brand-forest bg-brand-forest text-white shadow-sm'
                                    : 'border-brand-ink/15 bg-white/80 text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink',
                            ])
                        >
                            {{ __('All areas') }}
                        </button>
                        @foreach ($areaLabels as $areaKey => $areaLabel)
                            <button
                                type="button"
                                wire:click="$set('area', '{{ $areaKey }}')"
                                wire:key="roadmap-area-{{ $areaKey }}"
                                @class([
                                    'rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
                                    $area === $areaKey
                                        ? 'border-brand-forest bg-brand-forest text-white shadow-sm'
                                        : 'border-brand-ink/15 bg-white/80 text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink',
                                ])
                            >
                                {{ __($areaLabel) }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Active release banner --}}
            @if ($activeRelease)
                <section class="mt-6 overflow-hidden rounded-2xl border border-brand-sage/30 bg-gradient-to-br from-brand-sand/40 to-white/80 p-6 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $activeRelease->trainLabel() }}</p>
                    <h2 class="mt-2 text-2xl font-semibold text-brand-ink">{{ $activeRelease->displayTitle() }}</h2>
                    @if ($activeRelease->summary)
                        <p class="mt-3 max-w-3xl text-sm leading-relaxed text-brand-moss">{{ $activeRelease->summary }}</p>
                    @endif
                    @if ($activeRelease->published_at)
                        <p class="mt-3 text-xs text-brand-mist">{{ __('Published :date', ['date' => $activeRelease->published_at->format('M j, Y')]) }}</p>
                    @endif
                </section>
            @endif

            {{-- Board --}}
            @if (! $hasPublishedItems)
                <div class="mt-10 rounded-2xl border border-dashed border-brand-ink/15 bg-white/70 px-6 py-16 text-center">
                    <x-heroicon-o-map class="mx-auto h-10 w-10 text-brand-mist" />
                    <p class="mt-4 text-lg font-medium text-brand-ink">{{ __('Roadmap coming soon') }}</p>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('We are preparing the first public items. You can still send suggestions below.') }}</p>
                </div>
            @else
                <div class="mt-8 grid gap-6 lg:grid-cols-3">
                    @foreach ($statusLabels as $statusKey => $statusLabel)
                        @php
                            $columnItems = $itemsByStatus[$statusKey] ?? collect();
                            $meta = $statusMeta[$statusKey] ?? $metaFallback;
                        @endphp
                        <section wire:key="roadmap-column-{{ $statusKey }}"
                                 class="flex flex-col overflow-hidden rounded-2xl border border-brand-ink/10 bg-white/85 shadow-sm">
                            <span class="block h-1 {{ $meta['bar'] }}" aria-hidden="true"></span>
                            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 px-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg {{ $meta['soft'] }}">
                                        <x-dynamic-component :component="$meta['icon']" class="h-5 w-5" />
                                    </span>
                                    <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-brand-ink">{{ __($statusLabel) }}</h2>
                                </div>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold tabular-nums {{ $meta['count'] }}">{{ $columnItems->count() }}</span>
                            </header>
                            <ul class="flex flex-1 flex-col gap-3 p-4 {{ $meta['tint'] }}">
                                @forelse ($columnItems as $item)
                                    <li wire:key="roadmap-item-{{ $item->id }}"
                                        class="group relative overflow-hidden rounded-xl border border-brand-ink/10 bg-white p-4 pl-5 shadow-sm transition-shadow hover:shadow-md">
                                        <span class="absolute inset-y-0 left-0 w-1 {{ $meta['rail'] }}" aria-hidden="true"></span>
                                        @if ($item->areaLabel())
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] {{ $areaAccent[$item->area] ?? 'text-brand-sage' }}">{{ $item->areaLabel() }}</p>
                                        @endif
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <h3 class="font-semibold leading-snug text-brand-ink">{{ $item->title }}</h3>
                                            @if ($item->targetQuarterLabel())
                                                <span class="rounded-full bg-brand-sand/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $item->targetQuarterLabel() }}</span>
                                            @endif
                                            @if ($item->targetRelease && $item->status !== \App\Models\RoadmapItem::STATUS_SHIPPED)
                                                <span class="rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ $item->targetRelease->trainLabel() }}</span>
                                            @endif
                                            @if ($item->shippedRelease)
                                                <span class="rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ $item->shippedRelease->trainLabel() }}</span>
                                            @endif
                                        </div>
                                        @if ($item->summary)
                                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $item->summary }}</p>
                                        @endif
                                        @if ($item->description)
                                            <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-brand-moss/90">{{ $item->description }}</p>
                                        @endif
                                        @if ($item->status === \App\Models\RoadmapItem::STATUS_SHIPPED && $item->shipped_at)
                                            <p class="mt-3 inline-flex items-center gap-1 text-xs text-brand-mist">
                                                <x-heroicon-m-check-circle class="h-3.5 w-3.5 text-brand-forest" />
                                                {{ __('Shipped :date', ['date' => $item->shipped_at->format('M j, Y')]) }}
                                            </p>
                                        @endif
                                    </li>
                                @empty
                                    <li class="rounded-xl border border-dashed border-brand-ink/10 px-4 py-10 text-center text-sm text-brand-mist">
                                        {{ __('Nothing here yet.') }}
                                    </li>
                                @endforelse
                            </ul>
                        </section>
                    @endforeach
                </div>
            @endif

            {{-- Release history (vertical timeline) --}}
            @if ($release === 'all' && $releaseTimeline->isNotEmpty())
                <section class="mt-16">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Release history') }}</p>
                            <p class="text-sm text-brand-moss">{{ __('What shipped in each calendar release train.') }}</p>
                        </div>
                    </div>

                    <div class="relative mt-8 space-y-8 sm:pl-8">
                        <div class="absolute bottom-0 left-0 top-2 hidden w-px bg-gradient-to-b from-brand-ink/15 via-brand-ink/10 to-transparent sm:block" aria-hidden="true"></div>
                        @foreach ($releaseTimeline as $train)
                            <article wire:key="release-timeline-{{ $train->id }}" class="relative">
                                <span class="absolute -left-[calc(2rem+0.4rem)] top-6 hidden h-3.5 w-3.5 rounded-full bg-brand-forest ring-4 ring-brand-forest/15 sm:block" aria-hidden="true"></span>
                                <div class="rounded-2xl border border-brand-ink/10 bg-white/85 p-6 shadow-sm">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $train->trainLabel() }}</p>
                                            <h3 class="mt-1 text-xl font-semibold text-brand-ink">{{ $train->displayTitle() }}</h3>
                                            @if ($train->summary)
                                                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-brand-moss">{{ $train->summary }}</p>
                                            @endif
                                        </div>
                                        @if ($train->published_at)
                                            <p class="rounded-full bg-brand-sand/60 px-3 py-1 text-xs font-medium text-brand-moss">{{ $train->published_at->format('M j, Y') }}</p>
                                        @endif
                                    </div>
                                    <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                                        @foreach ($train->shippedItems as $shippedItem)
                                            <li wire:key="release-item-{{ $train->id }}-{{ $shippedItem->id }}" class="rounded-xl border border-brand-ink/10 bg-white p-4">
                                                <p class="font-medium text-brand-ink">{{ $shippedItem->title }}</p>
                                                @if ($shippedItem->summary)
                                                    <p class="mt-1 text-sm text-brand-moss">{{ $shippedItem->summary }}</p>
                                                @endif
                                                @if ($shippedItem->shipped_at)
                                                    <p class="mt-2 text-xs text-brand-mist">{{ __('Shipped :date', ['date' => $shippedItem->shipped_at->format('M j, Y')]) }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Suggestion form --}}
            <section class="mt-16 overflow-hidden rounded-3xl border border-brand-ink/10 bg-white/90 shadow-xl shadow-brand-forest/10 ring-1 ring-brand-ink/5">
                <div class="grid gap-0 lg:grid-cols-[0.9fr_1.1fr]">
                    {{-- Pitch --}}
                    <div class="relative overflow-hidden bg-gradient-to-br from-brand-forest to-brand-forest/85 p-8 text-white sm:p-10">
                        <div class="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-brand-gold/20 blur-2xl" aria-hidden="true"></div>
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white/15">
                            <x-heroicon-o-light-bulb class="h-5 w-5 text-brand-gold" />
                        </span>
                        <p class="mt-5 text-sm font-semibold uppercase tracking-[0.18em] text-brand-gold">{{ __('Have an idea?') }}</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight">{{ __('Suggest a feature') }}</h2>
                        <p class="mt-3 max-w-sm text-sm leading-6 text-white/80">
                            {{ __('Tell us what would help your team. Suggestions go to the product team only — they are not shown publicly.') }}
                        </p>
                    </div>

                    {{-- Form --}}
                    <div class="p-8 sm:p-10">
                        @if ($suggestionSubmitted)
                            <div class="mb-6 flex items-start gap-3 rounded-2xl border border-brand-sage/20 bg-brand-sage/10 px-4 py-4 text-sm leading-6 text-brand-forest">
                                <x-heroicon-m-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-brand-forest" />
                                <span>{{ __('Thanks — we received your suggestion and will review it.') }}</span>
                            </div>
                        @endif

                        <form wire:submit="submitSuggestion" class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="roadmap_suggestion_name" :value="__('Name (optional)')" />
                                    <x-text-input id="roadmap_suggestion_name" wire:model="suggestionName" type="text" autocomplete="name" class="w-full" />
                                    <x-input-error :messages="$errors->get('suggestionName')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="roadmap_suggestion_email" :value="__('Email')" />
                                    <x-text-input id="roadmap_suggestion_email" wire:model="suggestionEmail" type="email" inputmode="email" autocomplete="email" class="w-full" required />
                                    <x-input-error :messages="$errors->get('suggestionEmail')" class="mt-2" />
                                </div>
                            </div>
                            <div>
                                <x-input-label for="roadmap_suggestion_title" :value="__('Title')" />
                                <x-text-input id="roadmap_suggestion_title" wire:model="suggestionTitle" type="text" class="w-full" required />
                                <x-input-error :messages="$errors->get('suggestionTitle')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="roadmap_suggestion_description" :value="__('Description')" />
                                <textarea
                                    id="roadmap_suggestion_description"
                                    wire:model="suggestionDescription"
                                    rows="4"
                                    class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    required
                                ></textarea>
                                <x-input-error :messages="$errors->get('suggestionDescription')" class="mt-2" />
                            </div>
                            <x-primary-button wire:loading.attr="disabled" wire:target="submitSuggestion">
                                <span wire:loading.remove wire:target="submitSuggestion">{{ __('Submit suggestion') }}</span>
                                <span wire:loading wire:target="submitSuggestion">{{ __('Sending…') }}</span>
                            </x-primary-button>
                        </form>
                    </div>
                </div>
            </section>
        </section>
    </main>

    <x-marketing-footer />
</div>
