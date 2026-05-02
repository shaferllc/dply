@props([
    'items' => [],
    /** Tailwind classes on the inner `<ol>` (spacing below the trail). */
    'wrapperClass' => 'mb-6',
])

@php
    $crumbs = array_values(array_filter(
        $items ?? [],
        static fn ($item): bool => is_array($item) && isset($item['label'])
    ));

    /** @var array<string, string> Heroicon outline component names (allowlisted; never from raw user input). */
    $iconComponents = [
        'home' => 'heroicon-o-home',
        'map-pin' => 'heroicon-o-map-pin',
        'folder' => 'heroicon-o-folder',
        'user-circle' => 'heroicon-o-user-circle',
        'cog-6-tooth' => 'heroicon-o-cog-6-tooth',
        'server' => 'heroicon-o-server',
        'code-bracket-square' => 'heroicon-o-code-bracket-square',
        'key' => 'heroicon-o-key',
        'bolt' => 'heroicon-o-bolt',
        'shield-check' => 'heroicon-o-shield-check',
        'bell-alert' => 'heroicon-o-bell-alert',
        'archive-box' => 'heroicon-o-archive-box',
        'user-group' => 'heroicon-o-user-group',
        'rectangle-stack' => 'heroicon-o-rectangle-stack',
        'cloud' => 'heroicon-o-cloud',
        'document-text' => 'heroicon-o-document-text',
        'gift' => 'heroicon-o-gift',
        'trash' => 'heroicon-o-trash',
        'building-office-2' => 'heroicon-o-building-office-2',
        'rectangle-group' => 'heroicon-o-rectangle-group',
        'server-stack' => 'heroicon-o-server-stack',
        'globe-alt' => 'heroicon-o-globe-alt',
    ];
@endphp

@if ($crumbs !== [])
    <nav {{ $attributes->class(['text-sm text-brand-moss']) }} aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 {{ $wrapperClass }}">
            @foreach ($crumbs as $item)
                @php
                    $href = $item['href'] ?? $item['url'] ?? null;
                    $hasHref = filled($href);
                    $isLast = $loop->last;

                    if (array_key_exists('icon', $item) && $item['icon'] === false) {
                        $resolvedIcon = null;
                    } elseif (isset($item['icon']) && is_string($item['icon']) && $item['icon'] !== '') {
                        $resolvedIcon = $iconComponents[$item['icon']] ?? null;
                    } else {
                        $resolvedIcon = match (true) {
                            count($crumbs) === 1 => $iconComponents['home'],
                            $loop->first => $iconComponents['home'],
                            $isLast => $iconComponents['map-pin'],
                            default => $iconComponents['folder'],
                        };
                    }
                @endphp
                @if (! $loop->first)
                    <li class="select-none text-brand-mist" aria-hidden="true">/</li>
                @endif
                <li class="min-w-0">
                    @if ($hasHref)
                        <a
                            href="{{ $href }}"
                            class="group inline-flex max-w-full min-w-0 items-center gap-1.5 text-brand-moss transition-colors hover:text-brand-ink"
                            wire:navigate
                        >
                            @if ($resolvedIcon)
                                <x-dynamic-component
                                    :component="$resolvedIcon"
                                    @class([
                                        'h-4 w-4 shrink-0 opacity-90',
                                        'text-brand-moss group-hover:text-brand-ink',
                                    ])
                                    aria-hidden="true"
                                />
                            @endif
                            <span class="truncate">{{ $item['label'] }}</span>
                        </a>
                    @elseif ($isLast)
                        <span class="inline-flex max-w-full min-w-0 items-center gap-1.5 font-semibold text-brand-ink" aria-current="page">
                            @if ($resolvedIcon)
                                <x-dynamic-component
                                    :component="$resolvedIcon"
                                    class="h-4 w-4 shrink-0 text-brand-ink opacity-90"
                                    aria-hidden="true"
                                />
                            @endif
                            <span class="truncate">{{ $item['label'] }}</span>
                        </span>
                    @else
                        <span class="inline-flex max-w-full min-w-0 items-center gap-1.5 font-medium text-brand-ink">
                            @if ($resolvedIcon)
                                <x-dynamic-component
                                    :component="$resolvedIcon"
                                    class="h-4 w-4 shrink-0 text-brand-ink opacity-90"
                                    aria-hidden="true"
                                />
                            @endif
                            <span class="truncate">{{ $item['label'] }}</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
