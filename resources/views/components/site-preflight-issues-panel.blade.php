@props([
    'checks',
    'compact' => false,
    /** When true, render as a hairline strip (merged chrome) instead of a nested amber card. */
    'embedded' => false,
    'title' => null,
    'description' => null,
    'sectionId' => 'site-preflight-issues',
])

@php
    $issues = collect($checks)->filter(fn ($check) => is_array($check) && ($check['message'] ?? '') !== '');
@endphp

@if ($issues->isNotEmpty())
    <section
        id="{{ $sectionId }}"
        @class([
            'scroll-mt-24',
            'border-b border-amber-200/80 bg-amber-50/40' => $embedded,
            'rounded-2xl border border-amber-200 bg-gradient-to-b from-amber-50/90 to-white' => ! $embedded,
            'px-4 py-4 sm:px-5 sm:py-5' => $compact && ! $embedded,
            'px-5 py-2.5 sm:px-6' => $embedded && $compact,
            'px-5 py-5 sm:px-6' => $embedded && ! $compact,
            'px-6 py-6 sm:px-7' => ! $compact && ! $embedded,
        ])
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <p @class([
                    'font-semibold uppercase tracking-[0.14em] text-amber-800',
                    'text-2xs' => $embedded && $compact,
                    'text-xs tracking-[0.18em]' => ! ($embedded && $compact),
                ])>{{ $title ?? __('Preflight issues') }}</p>
                <p @class([
                    'text-amber-950/90',
                    'mt-0.5 text-xs leading-relaxed' => $embedded && $compact,
                    'mt-1 text-sm leading-relaxed' => ! ($embedded && $compact),
                ])>
                    {{ $description ?? __('Review each item below and jump to the matching workspace section to fix it.') }}
                </p>
            </div>
            <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-900 ring-1 ring-amber-200">
                {{ trans_choice('{1} :count issue|[2,*] :count issues', $issues->count(), ['count' => $issues->count()]) }}
            </span>
        </div>

        <ul @class([
            'mt-2' => $embedded && $compact,
            'mt-4' => ! ($embedded && $compact),
            'divide-y divide-amber-200/70 border-t border-amber-200/70' => $embedded,
            'space-y-3' => ! $embedded,
        ])>
            @foreach ($issues as $issue)
                @php
                    $level = (string) ($issue['level'] ?? 'warning');
                    $isError = $level === 'error';
                    $fix = is_array($issue['fix'] ?? null) ? $issue['fix'] : null;
                @endphp
                <li @class([
                    'px-0 py-2' => $embedded && $compact,
                    'px-0 py-3.5' => $embedded && ! $compact,
                    'rounded-xl border px-4 py-3' => ! $embedded,
                    'border-red-200 bg-red-50/80' => ! $embedded && $isError,
                    'border-amber-200 bg-white/80' => ! $embedded && ! $isError,
                ])>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span @class([
                                    'inline-flex rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                    'bg-red-100 text-red-800' => $isError,
                                    'bg-amber-100 text-amber-900' => ! $isError,
                                ])>
                                    {{ $isError ? __('Blocker') : __('Warning') }}
                                </span>
                                <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                    {{ str($issue['key'] ?? 'check')->headline() }}
                                </span>
                            </div>
                            <p @class([
                                'mt-1 text-xs leading-relaxed' => $embedded && $compact,
                                'mt-2 text-sm leading-6' => ! ($embedded && $compact),
                                'text-red-900' => $isError,
                                'text-amber-950' => ! $isError,
                            ])>{{ $issue['message'] }}</p>
                        </div>
                        @if ($fix !== null && ($fix['action'] ?? '') !== '')
                            <button
                                type="button"
                                wire:click="{{ $fix['action'] }}"
                                wire:loading.attr="disabled"
                                wire:target="{{ $fix['action'] }}"
                                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage disabled:opacity-60"
                            >
                                <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ $fix['label'] ?? __('Fix') }}
                            </button>
                        @elseif ($fix !== null && ($fix['url'] ?? '') !== '')
                            <a
                                href="{{ $fix['url'] }}"
                                wire:navigate
                                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage"
                            >
                                <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ $fix['label'] ?? __('Fix') }}
                            </a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif
