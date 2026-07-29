<x-marketing-layout
    :title="$post['title']"
    :description="$post['summary'] ?: 'A dply build-in-public devlog entry.'"
    active="blog"
>
    <section class="px-4 py-12 pb-24 sm:px-6 sm:py-16 lg:px-8">
        <div class="mx-auto max-w-6xl">
            <div class="dply-card min-w-0 overflow-hidden p-0">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6 sm:py-6">
                    <a href="{{ route('blog.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss transition hover:text-brand-ink">
                        <x-heroicon-m-arrow-left class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('All entries') }}
                    </a>

                    <div class="mt-4 flex flex-wrap items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">
                        @if ($post['is_deep_dive'])
                            <span class="inline-flex items-center gap-1 rounded-md bg-brand-forest/10 px-2 py-0.5 text-[10px] text-brand-forest ring-1 ring-inset ring-brand-forest/20">
                                <x-heroicon-m-beaker class="h-3 w-3" aria-hidden="true" />
                                {{ __('Deep dive') }}
                            </span>
                        @endif
                        <time datetime="{{ $post['date'] }}">{{ $post['date_human'] }}</time>
                        <span class="text-brand-mist/50">·</span>
                        <span>{{ __(':n min read', ['n' => $post['reading_minutes']]) }}</span>
                        @foreach ($post['tags'] as $tag)
                            <span class="rounded-md bg-brand-sand/55 px-2 py-0.5 text-[10px] text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ $tag }}</span>
                        @endforeach
                    </div>
                    <h1 class="mt-3 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">{{ $post['title'] }}</h1>
                    @if ($post['summary'])
                        <p class="mt-2 max-w-4xl text-sm leading-relaxed text-brand-moss sm:text-base">{{ $post['summary'] }}</p>
                    @endif
                </div>

                <article class="px-5 py-6 sm:px-6 sm:py-8">
                    <div class="blog-prose max-w-4xl">
                        {!! $html !!}
                    </div>
                </article>

                @if ($recent->isNotEmpty())
                    <footer class="border-t border-brand-ink/10">
                        <div class="bg-brand-sand/15 px-5 py-3 sm:px-6">
                            <p class="text-sm font-semibold tracking-tight text-brand-ink">{{ __('More entries') }}</p>
                        </div>
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($recent as $other)
                                <li>
                                    <a
                                        href="{{ route('blog.show', $other['slug']) }}"
                                        wire:navigate
                                        class="group flex items-baseline justify-between gap-4 px-5 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-6"
                                    >
                                        <span class="min-w-0 truncate text-sm font-medium text-brand-ink group-hover:text-brand-forest">{{ $other['title'] }}</span>
                                        <time class="shrink-0 text-xs tabular-nums text-brand-mist" datetime="{{ $other['date'] }}">{{ $other['date_human'] }}</time>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </footer>
                @endif
            </div>
        </div>
    </section>

    {{-- Self-contained article styling so posts read well without depending on a
         Tailwind typography plugin. --}}
    <style>
        .blog-prose { color: #4b4b46; font-size: 1rem; line-height: 1.75; }
        .blog-prose > * + * { margin-top: 1.15rem; }
        .blog-prose h2 { margin-top: 2.25rem; margin-bottom: 0.5rem; font-size: 1.4rem; font-weight: 650; line-height: 1.3; color: #1c1b18; letter-spacing: -0.01em; }
        .blog-prose h3 { margin-top: 1.75rem; margin-bottom: 0.4rem; font-size: 1.15rem; font-weight: 650; color: #1c1b18; }
        .blog-prose p { margin-top: 1.15rem; }
        .blog-prose a { color: #3f6212; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
        .blog-prose a:hover { color: #1c1b18; }
        .blog-prose ul, .blog-prose ol { margin-top: 1.15rem; padding-left: 1.4rem; }
        .blog-prose ul { list-style: disc; }
        .blog-prose ol { list-style: decimal; }
        .blog-prose li { margin-top: 0.4rem; }
        .blog-prose li::marker { color: #a8a79c; }
        .blog-prose strong { color: #1c1b18; font-weight: 650; }
        .blog-prose code { background: rgba(28,27,24,0.06); border-radius: 0.3rem; padding: 0.1rem 0.35rem; font-size: 0.875em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .blog-prose pre { margin-top: 1.25rem; overflow-x: auto; border-radius: 0.75rem; background: #1c1b18; color: #f4f1e8; padding: 1rem 1.15rem; font-size: 0.85rem; line-height: 1.6; }
        .blog-prose pre code { background: transparent; padding: 0; color: inherit; font-size: inherit; }
        .blog-prose blockquote { margin-top: 1.25rem; border-left: 3px solid #c7d2a8; padding-left: 1rem; color: #6b6a60; font-style: italic; }
        .blog-prose hr { margin: 2rem 0; border: 0; border-top: 1px solid rgba(28,27,24,0.1); }
        .blog-prose h2 + p, .blog-prose h3 + p { margin-top: 0.5rem; }
    </style>
</x-marketing-layout>
