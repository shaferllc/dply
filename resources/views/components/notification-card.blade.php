@props([
    'severity' => null,
    'isResolved' => false,
    'unread' => false,
    'title',
    'body' => null,
    'category' => null,
    'time' => null,
    'url' => null,
])

@php
    $severityKey = $isResolved ? 'resolved' : strtolower((string) ($severity ?? ''));

    [$accent, $iconBg, $iconText, $iconComponent] = match ($severityKey) {
        'critical' => ['bg-red-500', 'bg-red-50', 'text-red-700', 'heroicon-s-exclamation-triangle'],
        'warning' => ['bg-amber-500', 'bg-amber-50', 'text-amber-700', 'heroicon-s-exclamation-circle'],
        'info' => ['bg-sky-500', 'bg-sky-50', 'text-sky-700', 'heroicon-s-information-circle'],
        'resolved' => ['bg-emerald-500', 'bg-emerald-50', 'text-emerald-700', 'heroicon-s-check-circle'],
        default => ['bg-brand-mist', 'bg-brand-sand/60', 'text-brand-moss', 'heroicon-s-bell'],
    };

    // Strip "[Dply] [WARNING]" email-subject prefixes from titles and
    // bodies (the dispatcher emits them for email; the inline UI carries
    // the same info via the colored icon + accent bar). Also drop the
    // trailing "Severity: warning" metadata line that shows up on insight
    // notifications.
    $cleanTitle = preg_replace('/^(?:\[[^\]]+\]\s*)+/u', '', (string) $title) ?: (string) $title;
    $cleanTitle = ltrim($cleanTitle, " \t\u{2014}-");
    $cleanBody = $body !== null ? trim((string) $body) : null;
    if (is_string($cleanBody) && $cleanBody !== '') {
        $cleanBody = preg_replace('/^\[[^\]]+\]\s*/u', '', $cleanBody) ?? $cleanBody;
        $cleanBody = preg_replace('/\s*Severity:\s*\S+\s*$/iu', '', $cleanBody) ?? $cleanBody;
        $cleanBody = trim($cleanBody);
    }
@endphp

<article {{ $attributes->class([
    'relative overflow-hidden rounded-2xl border bg-white p-5 shadow-sm transition-colors',
    'border-brand-gold/50 bg-brand-sand/25' => $unread,
    'border-brand-ink/10' => ! $unread,
]) }}>
    <span class="absolute inset-y-3 left-0 w-1 rounded-full {{ $accent }}" aria-hidden="true"></span>

    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div class="flex min-w-0 items-start gap-3">
            <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $iconBg }}">
                <x-dynamic-component :component="$iconComponent" class="h-4 w-4 {{ $iconText }}" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                @if ($category)
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $category }}</p>
                @endif
                <h4 class="text-base font-semibold leading-snug text-brand-ink">{{ $cleanTitle }}</h4>
                @if (is_string($cleanBody) && $cleanBody !== '')
                    <p class="mt-1.5 text-sm leading-6 text-brand-moss">{{ $cleanBody }}</p>
                @endif
                @if ($url)
                    <div class="mt-3">
                        <a href="{{ $url }}" class="inline-flex items-center gap-1 text-sm font-medium text-brand-forest hover:text-brand-ink">
                            {{ __('Open') }}
                            <x-heroicon-o-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </a>
                    </div>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 flex-col items-end gap-2 text-right">
            @if ($time)
                <span class="text-xs text-brand-mist whitespace-nowrap">{{ $time }}</span>
            @endif
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2 justify-end">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    </div>
</article>
