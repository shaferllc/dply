<x-marketing-layout
    title="Blog"
    description="Building dply in public — a daily devlog of what we shipped, broke, and fixed."
    active="blog"
>
    <div class="mx-auto max-w-3xl px-6 py-16 sm:py-20">
        <header class="mb-12">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Build in public') }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ __('Devlog') }}</h1>
            <p class="mt-3 max-w-2xl text-base leading-relaxed text-brand-moss">
                {{ __('A running log of building dply — what shipped each day, the bugs that bit, and the calls we made. One entry per day of work.') }}
            </p>
        </header>

        @if ($posts->isEmpty())
            <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-6 py-16 text-center">
                <p class="text-sm font-semibold text-brand-ink">{{ __('No posts yet') }}</p>
                <p class="mt-2 text-sm text-brand-moss">{{ __('The first devlog entry is on its way.') }}</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($posts as $post)
                    <a
                        href="{{ route('blog.show', $post['slug']) }}"
                        wire:navigate
                        class="group block rounded-2xl border border-brand-ink/10 bg-white px-5 py-5 shadow-sm transition hover:border-brand-sage/40 hover:shadow-md sm:px-6"
                    >
                        <div class="flex flex-wrap items-center gap-2.5 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">
                            @if ($post['is_deep_dive'])
                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-forest/10 px-2 py-0.5 text-[10px] text-brand-forest ring-1 ring-brand-forest/20">
                                    <x-heroicon-m-beaker class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Deep dive') }}
                                </span>
                            @endif
                            <time datetime="{{ $post['date'] }}">{{ $post['date_human'] }}</time>
                            <span class="text-brand-mist/50">·</span>
                            <span>{{ __(':n min read', ['n' => $post['reading_minutes']]) }}</span>
                            @foreach (array_slice($post['tags'], 0, 3) as $tag)
                                <span class="rounded-full bg-brand-sand/50 px-2 py-0.5 text-[10px] text-brand-moss ring-1 ring-brand-ink/10">{{ $tag }}</span>
                            @endforeach
                        </div>
                        <h2 class="mt-2 text-lg font-semibold text-brand-ink transition group-hover:text-brand-forest">{{ $post['title'] }}</h2>
                        @if ($post['summary'])
                            <p class="mt-1.5 text-sm leading-relaxed text-brand-moss">{{ $post['summary'] }}</p>
                        @endif
                        <span class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-brand-forest">
                            {{ __('Read entry') }}
                            <x-heroicon-m-arrow-right class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-marketing-layout>
