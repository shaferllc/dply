@props([
    'categories',      // Collection<string, Collection<doc>>
    'current' => null, // active slug
    'title' => 'Documentation',
    'headings' => [],  // list<{id,text,level}>
    'prev' => null,
    'next' => null,
])

@php
    $isActive = fn (?string $slug): bool => $current !== null && $slug === $current;
@endphp

<div class="mx-auto w-full max-w-[90rem] px-4 sm:px-6 lg:px-8 py-6" x-data="docsSearch()">
    {{-- Top bar: title + search trigger --}}
    <div class="mb-6 flex items-center justify-between gap-4">
        <a href="{{ route('docs.index') }}" class="flex items-center gap-2 text-brand-ink hover:opacity-80" wire:navigate>
            <x-heroicon-o-book-open class="h-5 w-5 text-brand-sage" aria-hidden="true" />
            <span class="font-semibold tracking-tight">{{ __('Documentation') }}</span>
        </a>
        <button
            type="button"
            @click="open()"
            class="group inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-moss shadow-sm hover:border-brand-ink/25 hover:text-brand-ink min-w-[12rem] sm:min-w-[18rem]"
        >
            <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
            <span class="flex-1 text-left">{{ __('Search the docs…') }}</span>
            <kbd class="hidden sm:inline-flex items-center rounded border border-brand-ink/15 bg-brand-sand/40 px-2 py-0.5 text-xs font-semibold text-brand-moss">Ctrl /</kbd>
        </button>
    </div>

    <div class="lg:grid lg:grid-cols-12 lg:gap-8">
        {{-- Left rail: category → docs --}}
        <aside class="lg:col-span-3 mb-8 lg:mb-0">
            <nav class="lg:sticky lg:top-6 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto pr-2 space-y-6" aria-label="{{ __('Documentation navigation') }}">
                @foreach ($categories as $category => $docs)
                    <div>
                        <p class="px-2 text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ $category }}</p>
                        <ul class="mt-1.5 space-y-0.5">
                            @foreach ($docs as $doc)
                                <li>
                                    <a
                                        href="{{ $doc['route'] ? route($doc['route']) : route('docs.markdown', ['slug' => $doc['slug']]) }}"
                                        wire:navigate
                                        @class([
                                            'block rounded-md px-2 py-1.5 text-sm transition-colors',
                                            'bg-brand-sand/70 text-brand-ink font-medium' => $isActive($doc['slug']),
                                            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! $isActive($doc['slug']),
                                        ])
                                    >
                                        {{ $doc['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </aside>

        {{-- Center: content --}}
        <main class="lg:col-span-6 min-w-0">
            {{ $slot }}

            @if ($prev || $next)
                <div class="mt-12 grid gap-3 border-t border-brand-ink/10 pt-6 sm:grid-cols-2">
                    <div>
                        @if ($prev)
                            <a href="{{ route('docs.markdown', ['slug' => $prev['slug']]) }}" wire:navigate class="block rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:border-brand-ink/25">
                                <span class="text-xs text-brand-moss">← {{ __('Previous') }}</span>
                                <span class="mt-0.5 block text-sm font-medium text-brand-ink">{{ $prev['title'] }}</span>
                            </a>
                        @endif
                    </div>
                    <div class="sm:text-right">
                        @if ($next)
                            <a href="{{ route('docs.markdown', ['slug' => $next['slug']]) }}" wire:navigate class="block rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:border-brand-ink/25">
                                <span class="text-xs text-brand-moss">{{ __('Next') }} →</span>
                                <span class="mt-0.5 block text-sm font-medium text-brand-ink">{{ $next['title'] }}</span>
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </main>

        {{-- Right: on this page --}}
        <aside class="hidden lg:block lg:col-span-3">
            @if (! empty($headings))
                <div class="sticky top-6 max-h-[calc(100vh-6rem)] overflow-y-auto">
                    <p class="text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('On this page') }}</p>
                    <ul class="mt-2 space-y-1 border-l border-brand-ink/10">
                        @foreach ($headings as $h)
                            <li>
                                <a href="#{{ $h['id'] }}" @class([
                                    'block border-l-2 -ml-px py-0.5 text-sm text-brand-moss hover:text-brand-ink hover:border-brand-sage border-transparent',
                                    'pl-3' => ($h['level'] ?? 2) <= 2,
                                    'pl-6' => ($h['level'] ?? 2) >= 3,
                                ])>{{ $h['text'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </aside>
    </div>

    @include('docs.partials.search-palette')
</div>
