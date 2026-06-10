<x-app-layout>
    @php
        // Per-category icon + accent. Falls back to a neutral book icon.
        $catMeta = [
            'Getting started' => ['icon' => 'rocket-launch', 'ring' => 'ring-brand-sage/30', 'bg' => 'bg-brand-sage/15', 'fg' => 'text-brand-forest'],
            'Servers'         => ['icon' => 'server-stack',  'ring' => 'ring-brand-forest/25', 'bg' => 'bg-brand-forest/10', 'fg' => 'text-brand-forest'],
            'Sites & deploys' => ['icon' => 'globe-alt',     'ring' => 'ring-sky-200', 'bg' => 'bg-sky-50', 'fg' => 'text-sky-700'],
            'Edge'            => ['icon' => 'bolt',          'ring' => 'ring-amber-200', 'bg' => 'bg-amber-50', 'fg' => 'text-amber-700'],
            'Organization'    => ['icon' => 'users',         'ring' => 'ring-violet-200', 'bg' => 'bg-violet-50', 'fg' => 'text-violet-700'],
            'Billing'         => ['icon' => 'credit-card',   'ring' => 'ring-brand-sage/30', 'bg' => 'bg-brand-sage/15', 'fg' => 'text-brand-forest'],
            'Reference'       => ['icon' => 'code-bracket',  'ring' => 'ring-brand-ink/15', 'bg' => 'bg-brand-sand/60', 'fg' => 'text-brand-moss'],
        ];
        $total = $categories->sum(fn ($d) => $d->count());
    @endphp

    <div x-data="docsSearch()" class="min-h-screen bg-gradient-to-b from-brand-sand/30 to-transparent">
        <div class="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8">

            {{-- Brand row --}}
            <div class="flex items-center justify-between py-5">
                <a href="{{ route('docs.index') }}" class="flex items-center gap-2 text-brand-ink" wire:navigate>
                    <x-heroicon-o-book-open class="h-5 w-5 text-brand-sage" aria-hidden="true" />
                    <span class="font-semibold tracking-tight">{{ __('Documentation') }}</span>
                </a>
                <a href="{{ route('dashboard') }}" class="text-sm text-brand-moss hover:text-brand-ink" wire:navigate>{{ __('Back to dashboard') }} →</a>
            </div>

            {{-- Hero --}}
            <header class="py-10 text-center sm:py-14">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sage/15 px-3 py-1 text-xs font-semibold text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-sparkles class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ trans_choice('{1}:count guide|[2,*]:count guides', $total, ['count' => $total]) }}
                </span>
                <h1 class="mt-4 text-4xl font-semibold tracking-tight text-brand-ink sm:text-5xl">{{ __('dply documentation') }}</h1>
                <p class="mx-auto mt-3 max-w-2xl text-base text-brand-moss sm:text-lg">
                    {{ __('Everything you need to ship and run servers, sites, deploys, and Edge.') }}
                </p>

                <button
                    type="button"
                    @click="open()"
                    class="group mx-auto mt-7 flex w-full max-w-xl items-center gap-3 rounded-2xl border border-brand-ink/10 bg-white px-4 py-3.5 text-left shadow-sm ring-1 ring-transparent transition hover:border-brand-ink/20 hover:shadow-md focus:outline-none focus-visible:ring-brand-sage/40"
                >
                    <x-heroicon-o-magnifying-glass class="h-5 w-5 shrink-0 text-brand-moss" aria-hidden="true" />
                    <span class="flex-1 text-brand-mist">{{ __('Search the documentation…') }}</span>
                    <kbd class="hidden sm:inline-flex items-center rounded-md border border-brand-ink/15 bg-brand-sand/40 px-2 py-0.5 text-xs font-semibold text-brand-moss">Ctrl /</kbd>
                </button>
            </header>

            {{-- Category grid --}}
            <div class="grid gap-5 pb-20 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($categories as $category => $docs)
                    @php
                        $m = $catMeta[$category] ?? ['icon' => 'book-open', 'ring' => 'ring-brand-ink/15', 'bg' => 'bg-brand-sand/60', 'fg' => 'text-brand-moss'];
                        $first = $docs->first();
                        $firstUrl = $first['route'] ? route($first['route']) : route('docs.markdown', ['slug' => $first['slug']]);
                        $top = $docs->take(5);
                        $rest = $docs->count() - $top->count();
                    @endphp
                    <section class="group flex flex-col rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm transition hover:border-brand-ink/20 hover:shadow-md">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl {{ $m['bg'] }} {{ $m['fg'] }} ring-1 {{ $m['ring'] }}">
                                <x-dynamic-component :component="'heroicon-o-'.$m['icon']" class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div>
                                <h2 class="font-semibold text-brand-ink">{{ $category }}</h2>
                                <p class="text-xs text-brand-moss">{{ trans_choice('{1}:count guide|[2,*]:count guides', $docs->count(), ['count' => $docs->count()]) }}</p>
                            </div>
                        </div>

                        <ul class="mt-4 flex-1 space-y-2.5">
                            @foreach ($top as $doc)
                                <li>
                                    <a
                                        href="{{ $doc['route'] ? route($doc['route']) : route('docs.markdown', ['slug' => $doc['slug']]) }}"
                                        wire:navigate
                                        class="flex items-start gap-2 text-sm text-brand-moss hover:text-brand-ink"
                                    >
                                        <x-heroicon-o-arrow-right class="mt-1 h-3.5 w-3.5 shrink-0 text-brand-mist group-hover:text-brand-sage" aria-hidden="true" />
                                        <span class="font-medium text-brand-ink">{{ $doc['title'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>

                        <a href="{{ $firstUrl }}" wire:navigate class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-forest hover:text-brand-sage">
                            @if ($rest > 0)
                                {{ __('View all :count', ['count' => $docs->count()]) }} →
                            @else
                                {{ __('Open') }} →
                            @endif
                        </a>
                    </section>
                @endforeach
            </div>
        </div>

        @include('docs.partials.search-palette')
    </div>
</x-app-layout>
