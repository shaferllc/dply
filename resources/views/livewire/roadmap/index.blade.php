<div class="min-h-screen bg-brand-cream text-brand-ink">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header active="roadmap" />

    <main class="px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
        <section class="mx-auto max-w-7xl">
            <div class="max-w-3xl">
                <p class="inline-flex items-center gap-2 rounded-full border border-brand-sage/20 bg-white/75 px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.2em] text-brand-forest">
                    <span class="h-2 w-2 rounded-full bg-brand-gold" aria-hidden="true"></span>
                    {{ __('Product direction') }}
                </p>
                <h1 class="mt-6 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">
                    {{ __('Product roadmap') }}
                </h1>
                <p class="mt-4 max-w-2xl text-lg leading-8 text-brand-moss">
                    {{ __('See what we are building next across the dply platform. This board is read-only — share ideas using the suggestion form below.') }}
                </p>
            </div>

            <div class="mt-10 flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Filter by product area') }}">
                <button
                    type="button"
                    wire:click="$set('area', 'all')"
                    @class([
                        'rounded-full border px-4 py-2 text-sm font-medium transition-colors',
                        $area === 'all'
                            ? 'border-brand-sage/40 bg-brand-sand/70 text-brand-ink shadow-sm'
                            : 'border-brand-ink/15 bg-white/80 text-brand-moss hover:border-brand-sage/30 hover:text-brand-ink',
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
                            'rounded-full border px-4 py-2 text-sm font-medium transition-colors',
                            $area === $areaKey
                                ? 'border-brand-sage/40 bg-brand-sand/70 text-brand-ink shadow-sm'
                                : 'border-brand-ink/15 bg-white/80 text-brand-moss hover:border-brand-sage/30 hover:text-brand-ink',
                        ])
                    >
                        {{ __($areaLabel) }}
                    </button>
                @endforeach
            </div>

            @if (! $hasPublishedItems)
                <div class="mt-10 rounded-2xl border border-dashed border-brand-ink/15 bg-white/70 px-6 py-12 text-center">
                    <x-heroicon-o-map class="mx-auto h-10 w-10 text-brand-mist" />
                    <p class="mt-4 text-lg font-medium text-brand-ink">{{ __('Roadmap coming soon') }}</p>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('We are preparing the first public items. You can still send suggestions below.') }}</p>
                </div>
            @else
                <div class="mt-10 grid gap-6 lg:grid-cols-3">
                    @foreach ($statusLabels as $statusKey => $statusLabel)
                        @php $columnItems = $itemsByStatus[$statusKey] ?? collect(); @endphp
                        <section wire:key="roadmap-column-{{ $statusKey }}" class="flex flex-col rounded-2xl border border-brand-ink/10 bg-white/80 shadow-sm">
                            <header class="border-b border-brand-ink/10 px-4 py-3">
                                <div class="flex items-center justify-between gap-2">
                                    <h2 class="text-sm font-semibold uppercase tracking-[0.14em] text-brand-ink">{{ __($statusLabel) }}</h2>
                                    <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-xs font-medium text-brand-moss">{{ $columnItems->count() }}</span>
                                </div>
                            </header>
                            <ul class="flex flex-1 flex-col gap-3 p-4">
                                @forelse ($columnItems as $item)
                                    <li wire:key="roadmap-item-{{ $item->id }}" class="rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                                        @if ($item->areaLabel())
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ $item->areaLabel() }}</p>
                                        @endif
                                        <h3 class="mt-1 font-semibold text-brand-ink">{{ $item->title }}</h3>
                                        @if ($item->summary)
                                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $item->summary }}</p>
                                        @endif
                                        @if ($item->description)
                                            <p class="mt-2 text-sm leading-relaxed text-brand-moss/90 whitespace-pre-line">{{ $item->description }}</p>
                                        @endif
                                        @if ($item->status === \App\Models\RoadmapItem::STATUS_SHIPPED && $item->shipped_at)
                                            <p class="mt-3 text-xs text-brand-mist">{{ __('Shipped :date', ['date' => $item->shipped_at->format('M j, Y')]) }}</p>
                                        @endif
                                    </li>
                                @empty
                                    <li class="rounded-xl border border-dashed border-brand-ink/10 px-4 py-8 text-center text-sm text-brand-mist">
                                        {{ __('Nothing here yet.') }}
                                    </li>
                                @endforelse
                            </ul>
                        </section>
                    @endforeach
                </div>
            @endif

            <section class="mt-14 rounded-3xl border border-brand-ink/10 bg-white/90 p-6 shadow-xl shadow-brand-forest/10 ring-1 ring-brand-ink/5 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Have an idea?') }}</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Suggest a feature') }}</h2>
                        <p class="mt-3 max-w-2xl text-sm leading-6 text-brand-moss">
                            {{ __('Tell us what would help your team. Suggestions go to the product team only — they are not shown publicly.') }}
                        </p>
                    </div>
                </div>

                @if ($suggestionSubmitted)
                    <div class="mt-6 rounded-2xl border border-brand-sage/20 bg-brand-sage/10 px-4 py-4 text-sm leading-6 text-brand-forest">
                        {{ __('Thanks — we received your suggestion and will review it.') }}
                    </div>
                @endif

                <form wire:submit="submitSuggestion" class="mt-6 space-y-4">
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
            </section>
        </section>
    </main>

    <x-marketing-footer />
</div>
