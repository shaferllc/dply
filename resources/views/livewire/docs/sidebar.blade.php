<div
    wire:ignore.self
    class="flex h-full flex-col bg-brand-cream text-brand-ink dark:bg-zinc-950 dark:text-brand-cream"
>
    <div class="flex min-w-0 items-center justify-between gap-3 border-b border-brand-ink/10 bg-gradient-to-b from-brand-sand/40 to-transparent px-4 py-3 dark:border-brand-mist/20 dark:from-zinc-900/60">
        <div class="flex min-w-0 flex-1 items-center gap-3">
            {{-- Brand chip: gives the panel a clear "docs" identity and lets the
                 breadcrumb (which already begins with "Documentation") be the
                 primary line instead of a redundant eyebrow. --}}
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/20 text-brand-forest ring-1 ring-brand-sage/30 dark:bg-brand-sage/10 dark:text-brand-sage">
                <x-heroicon-o-book-open class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                @if (count($breadcrumbs) > 1)
                    @php
                        $current = $breadcrumbs[count($breadcrumbs) - 1];
                        $ancestors = array_slice($breadcrumbs, 0, -1);
                    @endphp
                    {{-- Ancestors stay on one muted line and truncate; the current
                         page reads as the title below. Avoids the breadcrumb
                         wrapping vertically in a narrow panel. --}}
                    <nav aria-label="{{ __('Documentation breadcrumb') }}" class="flex min-w-0 items-center gap-1 text-[11px] leading-none text-brand-moss">
                        @foreach ($ancestors as $index => $crumb)
                            @if ($index > 0)
                                <x-heroicon-m-chevron-right class="h-3 w-3 shrink-0 text-brand-mist/70" aria-hidden="true" />
                            @endif
                            @if (($crumb['slug'] ?? null) === 'docs-index')
                                <button
                                    type="button"
                                    wire:click="showIndex"
                                    class="shrink-0 font-medium text-brand-forest transition-colors hover:text-brand-sage hover:underline dark:text-brand-sage"
                                >
                                    {{ $crumb['label'] }}
                                </button>
                            @elseif (is_string($crumb['slug'] ?? null) && $crumb['slug'] !== '')
                                <button
                                    type="button"
                                    wire:click="loadGuide('{{ $crumb['slug'] }}')"
                                    class="min-w-0 truncate font-medium text-brand-forest transition-colors hover:text-brand-sage hover:underline dark:text-brand-sage"
                                >
                                    {{ $crumb['label'] }}
                                </button>
                            @else
                                <span class="min-w-0 truncate">{{ $crumb['label'] }}</span>
                            @endif
                        @endforeach
                    </nav>
                    <h2 class="mt-1 truncate text-base font-semibold leading-tight text-brand-ink dark:text-brand-cream">{{ $current['label'] }}</h2>
                @else
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Documentation') }}</p>
                    <h2 class="truncate text-base font-semibold leading-tight text-brand-ink dark:text-brand-cream">{{ $title }}</h2>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            @if ($fullPageUrl)
                <a
                    href="{{ $fullPageUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="{{ __('Open full page') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2.5 py-1 text-xs font-medium text-brand-forest shadow-sm transition-colors hover:bg-white hover:text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900/80 dark:text-brand-sage dark:hover:bg-zinc-800"
                >
                    <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="hidden sm:inline">{{ __('Open full page') }}</span>
                </a>
            @endif
            <button
                type="button"
                wire:click="close"
                x-on:click="window.dispatchEvent(new CustomEvent('dply-docs-close'))"
                class="rounded-lg p-1.5 text-brand-mist transition-colors hover:bg-brand-sand/60 hover:text-brand-ink dark:hover:bg-zinc-800 dark:hover:text-brand-cream"
                aria-label="{{ __('Close documentation') }}"
            >
                <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
    </div>

    @if ($guideLinks !== [])
        <div x-data="{ open: false }" class="border-b border-brand-ink/10 px-4 py-3 dark:border-brand-mist/20">
            <button
                type="button"
                x-on:click="open = !open"
                :aria-expanded="open ? 'true' : 'false'"
                class="flex w-full items-center justify-between gap-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist hover:text-brand-ink dark:hover:text-brand-cream"
            >
                <span>{{ $guideGroupLabel }}</span>
                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 transition-transform" x-bind:class="open ? '' : '-rotate-90'" aria-hidden="true" />
            </button>
            <ul x-show="open" x-collapse class="mt-2 max-h-28 space-y-1 overflow-y-auto text-xs">
                @foreach ($guideLinks as $guide)
                    <li>
                        <button
                            type="button"
                            wire:click="loadGuide('{{ $guide['slug'] }}')"
                            @class([
                                'w-full rounded-md px-2 py-1 text-left transition-colors',
                                'bg-brand-sage/15 font-semibold text-brand-forest dark:bg-brand-sage/10 dark:text-brand-sage' => $guide['slug'] === $slug,
                                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink dark:hover:bg-zinc-800 dark:hover:text-brand-cream' => $guide['slug'] !== $slug,
                            ])
                        >
                            {{ $guide['title'] }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($headings !== [])
        <div x-data="{ open: false }" class="border-b border-brand-ink/10 px-4 py-3 dark:border-brand-mist/20">
            <button
                type="button"
                x-on:click="open = !open"
                :aria-expanded="open ? 'true' : 'false'"
                class="flex w-full items-center justify-between gap-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist hover:text-brand-ink dark:hover:text-brand-cream"
            >
                <span>{{ __('On this page') }}</span>
                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 transition-transform" x-bind:class="open ? '' : '-rotate-90'" aria-hidden="true" />
            </button>
            <ul x-show="open" x-collapse class="mt-2 max-h-32 space-y-1 overflow-y-auto text-xs">
                @foreach ($headings as $heading)
                    <li @class(['pl-3' => ($heading['level'] ?? 2) === 3])>
                        <a
                            href="#{{ $heading['id'] }}"
                            class="block rounded-md px-2 py-1 text-brand-moss transition-colors hover:bg-brand-sand/40 hover:text-brand-ink dark:hover:bg-zinc-800 dark:hover:text-brand-cream"
                        >
                            {{ $heading['text'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($docsAskEnabled && ! $isIndex && ($html !== '' || $virtualSummary))
        <div class="border-b border-brand-ink/10 px-4 py-3 dark:border-brand-mist/20">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Ask about this page') }}</p>
            <form wire:submit="submitDocsAsk" class="mt-2 space-y-2">
                <label for="docs-ask-question" class="sr-only">{{ __('Question') }}</label>
                <textarea
                    id="docs-ask-question"
                    wire:model="askQuestion"
                    rows="2"
                    placeholder="{{ __('How do I…?') }}"
                    class="w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-cream"
                ></textarea>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="submitDocsAsk"
                    class="inline-flex items-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-ink disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="submitDocsAsk">{{ __('Ask') }}</span>
                    <span wire:loading wire:target="submitDocsAsk">{{ __('Thinking…') }}</span>
                </button>
            </form>
            @if ($askError)
                <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">{{ $askError }}</p>
            @endif
            @if ($askAnswer !== '')
                <div class="mt-3 rounded-xl border border-brand-sage/25 bg-brand-sage/5 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Answer') }} · {{ $askConfidence }}</p>
                    <p class="mt-2 text-sm leading-relaxed text-brand-ink dark:text-brand-cream">{{ $askAnswer }}</p>
                    @if ($askCitedHeadings !== [])
                        <p class="mt-2 text-[11px] text-brand-moss">{{ __('Referenced sections:') }} {{ implode(', ', $askCitedHeadings) }}</p>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div class="flex-1 overflow-y-auto px-4 py-4">
        @if ($isIndex)
            <p class="text-sm text-brand-moss">{{ __('Choose a guide to read in this panel, or open the full documentation index.') }}</p>
            <ul class="mt-4 space-y-2">
                @foreach ($indexEntries as $entry)
                    <li>
                        <button
                            type="button"
                            wire:click="loadGuide('{{ $entry['slug'] }}')"
                            class="w-full rounded-xl border border-brand-ink/10 bg-white px-3 py-2 text-left text-sm font-medium text-brand-ink transition-colors hover:border-brand-mist/40 hover:bg-brand-sand/20 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-cream dark:hover:bg-zinc-800"
                        >
                            {{ $entry['title'] }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif ($virtualSummary)
            <p class="text-sm leading-relaxed text-brand-moss">{{ $virtualSummary }}</p>
            @if ($fullPageUrl)
                <a
                    href="{{ $fullPageUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-4 inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-cream"
                >
                    <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Read full guide') }}
                </a>
            @endif
        @elseif ($html !== '')
            <div class="docs-markdown-prose docs-sidebar-prose text-sm leading-relaxed text-brand-moss
                [&_h1]:text-xl [&_h1]:font-semibold [&_h1]:text-brand-ink [&_h1]:mb-4 dark:[&_h1]:text-brand-cream
                [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-brand-ink [&_h2]:mt-6 [&_h2]:mb-2 dark:[&_h2]:text-brand-cream
                [&_h3]:text-sm [&_h3]:font-medium [&_h3]:text-brand-ink [&_h3]:mt-4 [&_h3]:mb-2 dark:[&_h3]:text-brand-cream
                [&_p]:mb-3
                [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1 [&_ul]:mb-3
                [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:space-y-1 [&_ol]:mb-3
                [&_a]:text-brand-forest [&_a]:underline [&_a:hover]:text-brand-sage dark:[&_a]:text-brand-sage
                [&_code]:text-xs [&_code]:bg-slate-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:rounded [&_code]:font-mono dark:[&_code]:bg-zinc-800
                [&_strong]:text-brand-ink [&_strong]:font-medium dark:[&_strong]:text-brand-cream">
                {!! $html !!}
            </div>
        @else
            <p class="text-sm text-brand-moss">{{ __('This guide is not available yet.') }}</p>
        @endif
    </div>

    <div class="border-t border-brand-ink/10 px-4 py-3 dark:border-brand-mist/20">
        <button
            type="button"
            wire:click="showIndex"
            class="text-xs font-semibold text-brand-forest hover:text-brand-sage dark:text-brand-sage"
        >
            {{ __('All documentation') }}
        </button>
    </div>
</div>
