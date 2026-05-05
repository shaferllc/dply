@props([
    /** @var array<int, array{label: string, command: string}>|null */
    'commands' => null,
    /** @var string|null Single command for footer mode. */
    'command' => null,
    /** @var string|null Override the default summary/heading. */
    'summary' => null,
    /** @var 'details'|'footer'|'stub'|null Render mode; auto-detected from props if null. */
    'tone' => null,
    /** @var 'xs'|'10' Font-size class for snippet rows. */
    'size' => 'xs',
    /** @var string|null Optional intro paragraph above the list. */
    'intro' => null,
])

@php
    $resolvedTone = $tone
        ?? ($commands !== null && $commands !== [] ? 'details' : ($command !== null ? 'footer' : 'stub'));
    $rowSizeClass = $size === '10' ? 'text-[10px]' : 'text-[11px]';
    $detailsSummary = $summary ?? __('CLI commands');
    $footerLabel = $summary ?? __('CLI commands:');
    $stubMessage = __('CLI commands for this section are coming soon.');

    $rows = collect($commands ?? [])
        ->filter(fn ($entry): bool => is_array($entry) && isset($entry['command']) && trim((string) $entry['command']) !== '')
        ->values()
        ->all();
@endphp

@if ($resolvedTone === 'footer' && $command !== null && trim((string) $command) !== '')
    <footer
        {{ $attributes->class(['text-xs text-slate-500']) }}
        data-cli-snippet="footer"
        x-data="{ copied: false }"
    >
        {{ $footerLabel }}
        <code class="ml-1 select-all rounded bg-slate-100 px-1 py-0.5 font-mono">{{ $command }}</code>
        <button
            type="button"
            class="ml-1 inline-flex items-center justify-center rounded p-0.5 text-slate-400 align-middle hover:bg-slate-200 hover:text-slate-700"
            title="{{ __('Copy command') }}"
            aria-label="{{ __('Copy command') }}"
            @click="navigator.clipboard.writeText(@js($command)); copied = true; setTimeout(() => copied = false, 1500)"
        >
            <x-heroicon-o-clipboard class="h-3 w-3" />
        </button>
        <span x-show="copied" x-cloak class="ml-1 text-[10px] font-medium text-emerald-700">{{ __('Copied') }}</span>
    </footer>
@elseif ($resolvedTone === 'details' && $rows !== [])
    <details
        {{ $attributes->class(['rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600']) }}
        data-cli-snippet="details"
    >
        <summary class="cursor-pointer font-semibold text-slate-700">{{ $detailsSummary }}</summary>
        @if ($intro)
            <p class="mt-2">{{ $intro }}</p>
        @endif
        <ul class="mt-2 space-y-1 font-mono {{ $rowSizeClass }}">
            @foreach ($rows as $row)
                <li x-data="{ copied: false }" class="flex flex-wrap items-center gap-x-1.5">
                    @if (! empty($row['label']))
                        <span class="font-sans text-slate-600">{{ $row['label'] }}</span>
                        <span class="font-sans text-slate-400" aria-hidden="true">—</span>
                    @endif
                    <code class="select-all rounded bg-white px-1 py-0.5">{{ $row['command'] }}</code>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded p-0.5 text-slate-400 hover:bg-white hover:text-slate-700"
                        title="{{ __('Copy command') }}"
                        aria-label="{{ __('Copy command') }}"
                        @click="navigator.clipboard.writeText(@js($row['command'])); copied = true; setTimeout(() => copied = false, 1500)"
                    >
                        <x-heroicon-o-clipboard class="h-3 w-3" />
                    </button>
                    <span x-show="copied" x-cloak class="text-[10px] font-medium text-emerald-700">{{ __('Copied') }}</span>
                </li>
            @endforeach
        </ul>
    </details>
@else
    <details
        {{ $attributes->class(['rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600']) }}
        data-cli-snippet="stub"
    >
        <summary class="cursor-pointer font-semibold text-slate-700">{{ $detailsSummary }}</summary>
        <p class="mt-2 text-slate-500">{{ $summary === null ? $stubMessage : ($intro ?? $stubMessage) }}</p>
    </details>
@endif
