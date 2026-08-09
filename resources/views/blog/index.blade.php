<x-marketing-layout
    title="Blog"
    description="Building dply in public — a daily devlog of what we shipped, broke, and fixed."
    active="blog"
>
    <section class="px-4 py-12 pb-24 sm:px-6 sm:py-16 lg:px-8">
        <div class="mx-auto max-w-6xl">
            <div class="dply-card min-w-0 overflow-hidden p-0">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-6 sm:px-6 sm:py-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Build in public') }}</p>
                    <h1 class="mt-1.5 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">{{ __('Devlog') }}</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-moss sm:text-base">
                        {{ __('A running log of building dply — what shipped each day, the bugs that bit, and the calls we made. One entry per day of work.') }}
                    </p>
                    @if ($posts->isNotEmpty())
                        <p class="mt-3 text-xs font-medium tabular-nums text-brand-mist">
                            {{ trans_choice(':n entry|:n entries', $posts->count(), ['n' => $posts->count()]) }}
                        </p>
                    @endif
                </div>

                @if ($posts->isEmpty())
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-newspaper class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No posts yet') }}</p>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">{{ __('The first devlog entry is on its way.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($posts as $post)
                            <li>
                                <a
                                    href="{{ route('blog.show', $post['slug']) }}"
                                    wire:navigate
                                    class="group block px-5 py-5 transition-colors hover:bg-brand-sand/15 sm:px-6 sm:py-5"
                                >
                                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide text-brand-mist">
                                        @if ($post['is_deep_dive'])
                                            <span class="inline-flex items-center gap-1 rounded-md bg-brand-forest/10 px-2 py-0.5 text-2xs text-brand-forest ring-1 ring-inset ring-brand-forest/20">
                                                <x-heroicon-m-beaker class="h-3 w-3" aria-hidden="true" />
                                                {{ __('Deep dive') }}
                                            </span>
                                        @endif
                                        <time datetime="{{ $post['date'] }}">{{ $post['date_human'] }}</time>
                                        <span class="text-brand-mist/50">·</span>
                                        <span>{{ __(':n min read', ['n' => $post['reading_minutes']]) }}</span>
                                        @foreach (array_slice($post['tags'], 0, 3) as $tag)
                                            <span class="rounded-md bg-brand-sand/55 px-2 py-0.5 text-2xs text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ $tag }}</span>
                                        @endforeach
                                    </div>
                                    <h2 class="mt-2 text-base font-semibold tracking-tight text-brand-ink transition group-hover:text-brand-forest sm:text-lg">
                                        {{ $post['title'] }}
                                    </h2>
                                    @if ($post['summary'])
                                        <p class="mt-1.5 max-w-4xl text-sm leading-relaxed text-brand-moss">{{ $post['summary'] }}</p>
                                    @endif
                                    <span class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-brand-forest">
                                        {{ __('Read entry') }}
                                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" aria-hidden="true" />
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <p class="mt-6 text-center text-xs text-brand-mist">
                Looking ahead?
                <a href="{{ route('roadmap') }}" class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('View the roadmap') }}</a>
                <span class="mx-1.5 text-brand-mist/50">·</span>
                <a href="{{ route('changelog') }}" class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('Changelog') }}</a>
            </p>
        </div>
    </section>
</x-marketing-layout>
