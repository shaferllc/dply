<x-marketing-layout
    :title="$post['title']"
    :description="$post['summary'] ?: 'A dply build-in-public devlog entry.'"
    active="blog"
>
    <article class="mx-auto max-w-3xl px-6 py-16 sm:py-20">
        <a href="{{ route('blog.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss transition hover:text-brand-ink">
            <x-heroicon-m-arrow-left class="h-3.5 w-3.5" aria-hidden="true" />
            {{ __('All entries') }}
        </a>

        <header class="mt-6 border-b border-brand-ink/10 pb-6">
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
                @foreach ($post['tags'] as $tag)
                    <span class="rounded-full bg-brand-sand/50 px-2 py-0.5 text-[10px] text-brand-moss ring-1 ring-brand-ink/10">{{ $tag }}</span>
                @endforeach
            </div>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ $post['title'] }}</h1>
            @if ($post['summary'])
                <p class="mt-3 text-base leading-relaxed text-brand-moss">{{ $post['summary'] }}</p>
            @endif
        </header>

        <div class="blog-prose mt-8">
            {!! $html !!}
        </div>

        @if ($recent->isNotEmpty())
            <footer class="mt-16 border-t border-brand-ink/10 pt-8">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('More entries') }}</p>
                <div class="mt-4 space-y-2">
                    @foreach ($recent as $other)
                        <a href="{{ route('blog.show', $other['slug']) }}" wire:navigate class="group flex items-baseline justify-between gap-4 rounded-lg px-2 py-2 transition hover:bg-brand-sand/30">
                            <span class="truncate text-sm font-medium text-brand-ink group-hover:text-brand-forest">{{ $other['title'] }}</span>
                            <time class="shrink-0 text-xs text-brand-mist" datetime="{{ $other['date'] }}">{{ $other['date_human'] }}</time>
                        </a>
                    @endforeach
                </div>
            </footer>
        @endif
    </article>

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
