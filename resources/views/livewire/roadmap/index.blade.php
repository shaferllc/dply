<div class="min-h-screen text-brand-ink">
    {{-- Cream canvas + mesh gradient. The root div must stay transparent so the
         fixed -z-10 mesh shows through (an opaque bg here would cover it). --}}
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header active="roadmap" />

    @php
        // Per-status dot + subtle rail accent for the board columns + header meta.
        $statusMeta = [
            'planned' => [
                'dot' => 'bg-brand-mist',
                'rail' => 'border-l-brand-mist/40',
            ],
            'in_progress' => [
                'dot' => 'bg-brand-gold',
                'rail' => 'border-l-brand-gold/60',
            ],
            'shipped' => [
                'dot' => 'bg-brand-forest',
                'rail' => 'border-l-brand-forest/50',
            ],
        ];
        $metaFallback = ['dot' => 'bg-brand-sage', 'rail' => 'border-l-brand-sage/40'];

        // Per-area accent for card labels (brand palette only).
        $areaAccent = [
            'platform' => 'text-brand-forest',
            'servers' => 'text-brand-sage',
            'edge' => 'text-brand-gold',
            'cloud' => 'text-brand-rust',
            'serverless' => 'text-brand-moss',
            'other' => 'text-brand-mist',
        ];

        $statusCounts = [];
        foreach ($statusLabels as $statusKey => $statusLabel) {
            $statusCounts[$statusKey] = ($itemsByStatus[$statusKey] ?? collect())->count();
        }
        $totalItems = array_sum($statusCounts);
    @endphp

    <main class="px-4 py-12 pb-24 sm:px-6 sm:py-16 lg:px-8">
        <div class="mx-auto max-w-6xl">
            {{-- One surface: sand identity + flush filters + hairline sections --}}
            <div class="dply-card min-w-0 overflow-hidden p-0">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-6 sm:px-6 sm:py-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ config('app.name') }}</p>
                    <h1 class="mt-1.5 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">
                        {{ __('Product roadmap') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-moss sm:text-base">
                        {{ __('What we are building next across the dply platform — planned, in flight, and shipped. This board is read-only; share ideas with the form below.') }}
                    </p>

                    @if ($totalItems > 0)
                        <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm text-brand-moss">
                            @foreach ($statusLabels as $statusKey => $statusLabel)
                                @php $meta = $statusMeta[$statusKey] ?? $metaFallback; @endphp
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $meta['dot'] }}"></span>
                                    <span class="font-semibold tabular-nums text-brand-ink">{{ $statusCounts[$statusKey] }}</span>
                                    {{ __($statusLabel) }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Filters --}}
                <div class="sticky top-16 z-10 space-y-2.5 border-b border-brand-ink/10 bg-white/95 px-4 py-3 backdrop-blur-md sm:px-5">
                    @if ($publishedReleaseTrains->isNotEmpty())
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <p class="w-16 shrink-0 text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Release') }}</p>
                            <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Filter by release train') }}">
                                <button
                                    type="button"
                                    role="tab"
                                    wire:click="$set('release', 'all')"
                                    aria-selected="{{ $release === 'all' ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                        $release === 'all'
                                            ? 'bg-brand-ink text-brand-cream shadow-sm'
                                            : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink',
                                    ])
                                >
                                    {{ __('All trains') }}
                                </button>
                                @foreach ($publishedReleaseTrains as $releaseTrain)
                                    <button
                                        type="button"
                                        role="tab"
                                        wire:click="$set('release', '{{ $releaseTrain->id }}')"
                                        wire:key="roadmap-release-{{ $releaseTrain->id }}"
                                        aria-selected="{{ $release === $releaseTrain->id ? 'true' : 'false' }}"
                                        @class([
                                            'inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                            $release === $releaseTrain->id
                                                ? 'bg-brand-ink text-brand-cream shadow-sm'
                                                : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink',
                                        ])
                                    >
                                        {{ $releaseTrain->trainLabel() }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <p class="w-16 shrink-0 text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Area') }}</p>
                        <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="{{ __('Filter by product area') }}">
                            <button
                                type="button"
                                role="tab"
                                wire:click="$set('area', 'all')"
                                aria-selected="{{ $area === 'all' ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                    $area === 'all'
                                        ? 'bg-brand-ink text-brand-cream shadow-sm'
                                        : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink',
                                ])
                            >
                                {{ __('All areas') }}
                            </button>
                            @foreach ($areaLabels as $areaKey => $areaLabel)
                                <button
                                    type="button"
                                    role="tab"
                                    wire:click="$set('area', '{{ $areaKey }}')"
                                    wire:key="roadmap-area-{{ $areaKey }}"
                                    aria-selected="{{ $area === $areaKey ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                        $area === $areaKey
                                            ? 'bg-brand-ink text-brand-cream shadow-sm'
                                            : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink',
                                    ])
                                >
                                    {{ __($areaLabel) }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Recently shipped highlights --}}
                @if ($recentlyShipped->isNotEmpty())
                    <div class="border-b border-brand-ink/10">
                        <div class="flex items-baseline justify-between gap-3 bg-brand-sand/15 px-5 py-3 sm:px-6">
                            <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('Recently shipped') }}</h2>
                            <span class="text-xs font-medium text-brand-mist">{{ __('Latest capabilities that landed on the platform.') }}</span>
                        </div>
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($recentlyShipped as $shippedItem)
                                <li wire:key="recently-shipped-{{ $shippedItem->id }}" class="border-l-2 {{ $statusMeta['shipped']['rail'] }} px-5 py-4 sm:px-6">
                                    <div class="flex flex-wrap items-center gap-2 text-2xs font-semibold uppercase tracking-[0.14em]">
                                        @if ($shippedItem->areaLabel())
                                            <span class="{{ $areaAccent[$shippedItem->area] ?? 'text-brand-sage' }}">{{ $shippedItem->areaLabel() }}</span>
                                        @endif
                                        @if ($shippedItem->shippedRelease)
                                            <span class="text-brand-mist">·</span>
                                            <span class="text-brand-sage">{{ $shippedItem->shippedRelease->trainLabel() }}</span>
                                        @endif
                                    </div>
                                    <h3 class="mt-1 text-sm font-semibold leading-snug text-brand-ink">{{ $shippedItem->title }}</h3>
                                    @if ($shippedItem->summary)
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $shippedItem->summary }}</p>
                                    @endif
                                    @if ($shippedItem->shipped_at)
                                        <p class="mt-1.5 inline-flex items-center gap-1 text-xs text-brand-mist">
                                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 text-brand-forest" />
                                            {{ __('Shipped :date', ['date' => $shippedItem->shipped_at->format('M j, Y')]) }}
                                        </p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Active release banner --}}
                @if ($activeRelease)
                    <div class="border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-5 sm:px-6">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $activeRelease->trainLabel() }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $activeRelease->displayTitle() }}</h2>
                        @if ($activeRelease->summary)
                            <p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-brand-moss">{{ $activeRelease->summary }}</p>
                        @endif
                        @if ($activeRelease->published_at)
                            <p class="mt-1.5 text-xs text-brand-mist">{{ __('Published :date', ['date' => $activeRelease->published_at->format('M j, Y')]) }}</p>
                        @endif
                    </div>
                @endif

                {{-- Board --}}
                @if (! $hasPublishedItems)
                    <div class="border-b border-brand-ink/10 px-6 py-16 text-center">
                        <x-heroicon-o-map class="mx-auto h-8 w-8 text-brand-mist" />
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('Roadmap coming soon') }}</p>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('We are preparing the first public items. You can still send suggestions below.') }}</p>
                    </div>
                @else
                    <div class="grid divide-y divide-brand-ink/10 border-b border-brand-ink/10 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                        @foreach ($statusLabels as $statusKey => $statusLabel)
                            @php
                                $columnItems = $itemsByStatus[$statusKey] ?? collect();
                                $meta = $statusMeta[$statusKey] ?? $metaFallback;
                            @endphp
                            <section wire:key="roadmap-column-{{ $statusKey }}" class="flex flex-col">
                                <header class="flex items-center justify-between gap-2 bg-brand-sand/15 px-4 py-3 sm:px-5">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2 w-2 rounded-full {{ $meta['dot'] }}"></span>
                                        <h3 class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-ink">{{ __($statusLabel) }}</h3>
                                    </div>
                                    <span class="text-xs font-medium tabular-nums text-brand-mist">{{ $columnItems->count() }}</span>
                                </header>
                                <ul class="flex-1 divide-y divide-brand-ink/10">
                                    @forelse ($columnItems as $item)
                                        <li wire:key="roadmap-item-{{ $item->id }}" class="border-l-2 {{ $meta['rail'] }} px-4 py-4 sm:px-5">
                                            @if ($item->areaLabel())
                                                <p class="text-2xs font-semibold uppercase tracking-[0.14em] {{ $areaAccent[$item->area] ?? 'text-brand-sage' }}">{{ $item->areaLabel() }}</p>
                                            @endif
                                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                <h4 class="text-sm font-semibold leading-snug text-brand-ink">{{ $item->title }}</h4>
                                                @if ($item->targetQuarterLabel())
                                                    <span class="rounded-full bg-brand-sand/70 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ $item->targetQuarterLabel() }}</span>
                                                @endif
                                                @if ($item->targetRelease && $item->status !== \App\Modules\Roadmap\Models\RoadmapItem::STATUS_SHIPPED)
                                                    <span class="rounded-full bg-brand-sage/15 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ $item->targetRelease->trainLabel() }}</span>
                                                @endif
                                                @if ($item->shippedRelease)
                                                    <span class="rounded-full bg-brand-sage/15 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ $item->shippedRelease->trainLabel() }}</span>
                                                @endif
                                            </div>
                                            @if ($item->summary)
                                                <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">{{ $item->summary }}</p>
                                            @endif
                                            @if ($item->description)
                                                <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-brand-moss/90">{{ $item->description }}</p>
                                            @endif
                                            @if ($item->status === \App\Modules\Roadmap\Models\RoadmapItem::STATUS_SHIPPED && $item->shipped_at)
                                                <p class="mt-1.5 inline-flex items-center gap-1 text-xs text-brand-mist">
                                                    <x-heroicon-m-check-circle class="h-3.5 w-3.5 text-brand-forest" />
                                                    {{ __('Shipped :date', ['date' => $item->shipped_at->format('M j, Y')]) }}
                                                </p>
                                            @endif
                                        </li>
                                    @empty
                                        <li class="px-4 py-10 text-center text-sm text-brand-mist">
                                            {{ __('Nothing here yet.') }}
                                        </li>
                                    @endforelse
                                </ul>
                            </section>
                        @endforeach
                    </div>
                @endif

                {{-- Release history (hairline timeline) --}}
                @if ($release === 'all' && $releaseTimeline->isNotEmpty())
                    <div class="border-b border-brand-ink/10">
                        <div class="flex items-baseline justify-between gap-3 bg-brand-sand/15 px-5 py-3 sm:px-6">
                            <h2 class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('Release history') }}</h2>
                            <span class="text-xs font-medium text-brand-mist">{{ __('What shipped in each calendar release train.') }}</span>
                        </div>
                        <div class="divide-y divide-brand-ink/10">
                            @foreach ($releaseTimeline as $train)
                                <article wire:key="release-timeline-{{ $train->id }}" class="px-5 py-5 sm:px-6">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $train->trainLabel() }}</p>
                                            <h3 class="mt-1 text-base font-semibold text-brand-ink">{{ $train->displayTitle() }}</h3>
                                            @if ($train->summary)
                                                <p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-brand-moss">{{ $train->summary }}</p>
                                            @endif
                                        </div>
                                        @if ($train->published_at)
                                            <p class="text-xs font-medium text-brand-mist">{{ $train->published_at->format('M j, Y') }}</p>
                                        @endif
                                    </div>
                                    <ul class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                        @foreach ($train->shippedItems as $shippedItem)
                                            <li wire:key="release-item-{{ $train->id }}-{{ $shippedItem->id }}" class="border-l-2 border-l-brand-ink/10 pl-3">
                                                <p class="text-sm font-medium text-brand-ink">{{ $shippedItem->title }}</p>
                                                @if ($shippedItem->summary)
                                                    <p class="mt-0.5 text-sm text-brand-moss">{{ $shippedItem->summary }}</p>
                                                @endif
                                                @if ($shippedItem->shipped_at)
                                                    <p class="mt-0.5 text-xs text-brand-mist">{{ __('Shipped :date', ['date' => $shippedItem->shipped_at->format('M j, Y')]) }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Suggestion form --}}
                <div class="bg-brand-sand/20 px-5 py-8 sm:px-6 sm:py-10">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Suggest a feature') }}</h2>
                        <p class="text-xs text-brand-mist">{{ __('Have an idea? Tell us what would help your team — suggestions go to the product team only.') }}</p>
                    </div>

                    @if ($suggestionSubmitted)
                        <div class="mt-4 flex items-start gap-3 rounded-lg border border-brand-sage/20 bg-brand-sage/10 px-4 py-3 text-sm leading-6 text-brand-forest">
                            <x-heroicon-m-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-brand-forest" />
                            <span>{{ __('Thanks — we received your suggestion and will review it.') }}</span>
                        </div>
                    @endif

                    <form wire:submit="submitSuggestion" class="mt-5 max-w-2xl space-y-4">
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
        </div>
    </main>
</div>
