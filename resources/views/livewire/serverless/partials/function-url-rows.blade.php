{{--
    Copyable function addresses. $invocationUrl is the raw functions host;
    $friendlyUrl is the public testing hostname (or a ready custom domain).
--}}
@php
    $invocationUrl = trim((string) ($invocationUrl ?? ''));
    $friendlyUrl = is_string($friendlyUrl ?? null) ? trim((string) $friendlyUrl) : '';
    $friendlyUrl = $friendlyUrl !== '' ? $friendlyUrl : null;
    $pad = $pad ?? 'px-5 py-3 sm:px-6';
    $urlClass = $urlClass ?? 'mt-1 block break-all font-mono text-sm text-brand-forest hover:underline';
@endphp

@if ($invocationUrl !== '' || $friendlyUrl)
    <div class="{{ $wrapperClass ?? 'border-t border-brand-ink/10' }}">
        @if ($friendlyUrl)
            <div class="{{ $pad }}" x-data="{ copied: false }">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Friendly URL') }}</p>
                <div class="mt-1 flex items-start gap-2">
                    <a href="{{ $friendlyUrl }}" target="_blank" rel="noopener"
                       class="{{ $urlClass }} min-w-0 flex-1">{{ $friendlyUrl }}</a>
                    <button
                        type="button"
                        x-on:click="navigator.clipboard.writeText(@js($friendlyUrl)); copied = true; setTimeout(() => copied = false, 1500)"
                        class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink transition hover:border-brand-sage/40"
                    >
                        <x-heroicon-o-clipboard-document class="h-3.5 w-3.5" aria-hidden="true" />
                        <span x-show="!copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </button>
                </div>
            </div>
        @elseif ($invocationUrl !== '')
            <div class="{{ $pad }}">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Friendly URL') }}</p>
                <p class="mt-1 text-sm text-brand-moss/50">{{ __('Friendly URL when DNS is ready') }}</p>
            </div>
        @endif

        @if ($invocationUrl !== '')
            <div class="{{ $pad }} border-t border-brand-ink/10" x-data="{ copied: false }">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Invocation URL') }}</p>
                <div class="mt-1 flex items-start gap-2">
                    <a href="{{ $invocationUrl }}" target="_blank" rel="noopener"
                       class="{{ $urlClass }} min-w-0 flex-1">{{ $invocationUrl }}</a>
                    <button
                        type="button"
                        x-on:click="navigator.clipboard.writeText(@js($invocationUrl)); copied = true; setTimeout(() => copied = false, 1500)"
                        class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink transition hover:border-brand-sage/40"
                    >
                        <x-heroicon-o-clipboard-document class="h-3.5 w-3.5" aria-hidden="true" />
                        <span x-show="!copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </button>
                </div>
            </div>
        @endif
    </div>
@endif
