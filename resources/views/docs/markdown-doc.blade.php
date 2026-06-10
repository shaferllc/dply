<x-app-layout>
    <x-docs-shell
        :categories="$categories"
        :current="$slug"
        :title="$title"
        :headings="$headings"
        :prev="$prev"
        :next="$next"
    >
        <nav class="mb-4 flex items-center gap-1.5 text-xs text-brand-moss" aria-label="Breadcrumb">
            <a href="{{ route('docs.index') }}" class="hover:text-brand-ink" wire:navigate>{{ __('Docs') }}</a>
            @if (! empty($current['category']))
                <span class="text-brand-mist">/</span>
                <span>{{ $current['category'] }}</span>
            @endif
            <span class="text-brand-mist">/</span>
            <span class="text-brand-ink">{{ $title }}</span>
        </nav>

        <article class="docs-markdown-prose text-brand-moss text-[0.925rem] leading-relaxed space-y-4
            [&_h1]:text-3xl [&_h1]:font-semibold [&_h1]:tracking-tight [&_h1]:text-brand-ink [&_h1]:mb-4
            [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-brand-ink [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:scroll-mt-24
            [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-brand-ink [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:scroll-mt-24
            [&_h4]:scroll-mt-24
            [&_p]:mb-3
            [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:space-y-1 [&_ul]:mb-3
            [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:space-y-1 [&_ol]:mb-3
            [&_li]:pl-0.5
            [&_a]:text-brand-forest [&_a]:underline [&_a:hover]:text-brand-sage
            [&_pre]:bg-brand-ink/95 [&_pre]:text-brand-cream [&_pre]:rounded-xl [&_pre]:p-4 [&_pre]:overflow-x-auto [&_pre]:text-xs [&_pre]:my-4
            [&_code]:text-[0.8125rem] [&_code]:bg-brand-sand/50 [&_code]:px-1 [&_code]:py-0.5 [&_code]:rounded [&_code]:font-mono
            [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_pre_code]:text-inherit
            [&_table]:w-full [&_table]:text-sm
            [&_strong]:text-brand-ink [&_strong]:font-semibold"
            x-init="$el.querySelectorAll('pre').forEach((pre) => {
                if (pre.querySelector('.docs-copy')) return;
                pre.classList.add('relative');
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'docs-copy absolute top-2 right-2 rounded-md bg-white/10 px-2 py-1 text-[0.625rem] font-semibold text-brand-cream hover:bg-white/20';
                b.textContent = 'Copy';
                b.addEventListener('click', () => {
                    navigator.clipboard.writeText(pre.innerText.replace(/Copy$/, '').trim());
                    b.textContent = 'Copied'; setTimeout(() => b.textContent = 'Copy', 1500);
                });
                pre.appendChild(b);
            })"
        >
            {!! $html !!}
        </article>
    </x-docs-shell>
</x-app-layout>
