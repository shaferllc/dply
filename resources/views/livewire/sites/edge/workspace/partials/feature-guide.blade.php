{{--
  Shared explainer for Edge Access / Site feature pages.
  @var string $what     One sentence: what this feature does for visitors/operators.
  @var list<string> $steps  Numbered how-to (keep short; 3–5).
  @var list<string>|null $tips  Optional callouts / gotchas.
  @var string|null $docSlug  Optional docs slide-over slug (e.g. edge-bot-protection).
  @var list<array{label: string, href: string, external?: bool}>|null $setupLinks  Optional setup links.
--}}
@php
    $what = is_string($what ?? null) ? $what : '';
    $steps = is_array($steps ?? null) ? array_values(array_filter($steps, fn ($s) => is_string($s) && $s !== '')) : [];
    $tips = is_array($tips ?? null) ? array_values(array_filter($tips, fn ($s) => is_string($s) && $s !== '')) : [];
    $docSlug = is_string($docSlug ?? null) && $docSlug !== '' ? $docSlug : null;
    $setupLinks = is_array($setupLinks ?? null) ? array_values(array_filter($setupLinks, function ($link) {
        return is_array($link)
            && is_string($link['label'] ?? null) && $link['label'] !== ''
            && is_string($link['href'] ?? null) && $link['href'] !== '';
    })) : [];
@endphp

@if ($what !== '' || $steps !== [])
    <div class="mb-5 rounded-2xl border border-brand-ink/10 bg-brand-sand/25 px-4 py-4 text-left dark:bg-brand-sand/10 sm:px-5">
        <div class="flex flex-wrap items-start justify-between gap-2">
            @if ($what !== '')
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('How this works') }}</p>
                    <p class="mt-1.5 text-sm leading-relaxed text-brand-ink">{{ $what }}</p>
                </div>
            @endif
            @if ($docSlug !== null)
                <div class="shrink-0">
                    <x-docs-link :slug="$docSlug">
                        {{ __('Open full guide') }}
                    </x-docs-link>
                </div>
            @endif
        </div>

        @if ($steps !== [])
            <ol class="mt-3 list-decimal space-y-1.5 pl-5 text-sm leading-relaxed text-brand-moss">
                @foreach ($steps as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        @endif

        @if ($setupLinks !== [])
            <div class="mt-3 flex flex-wrap gap-2 border-t border-brand-ink/10 pt-3">
                @foreach ($setupLinks as $link)
                    @php
                        $external = (bool) ($link['external'] ?? str_starts_with($link['href'], 'http'));
                    @endphp
                    @if ($external)
                        <a
                            href="{{ $link['href'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-cream dark:hover:bg-zinc-800"
                        >
                            {{ $link['label'] }}
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                        </a>
                    @else
                        <a
                            href="{{ $link['href'] }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-cream dark:hover:bg-zinc-800"
                        >
                            {{ $link['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>
        @endif

        @if ($tips !== [])
            <ul class="mt-3 space-y-1.5 border-t border-brand-ink/10 pt-3 text-xs leading-relaxed text-brand-moss">
                @foreach ($tips as $tip)
                    <li class="flex gap-2">
                        <x-heroicon-o-light-bulb class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                        <span>{{ $tip }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
