{{-- Invocation URLs tab — hairline strips inside the parent Routing card. --}}
@php
    $panelPad = 'px-3 py-2.5 sm:px-4';
    $stripHead = 'border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4';
@endphp

<div class="border-b border-brand-ink/10">
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Invocation URLs') }}
        </h3>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-[11px] text-brand-mist" title="{{ __('Origin skips the edge (no redirects/headers/CORS). Use as a fallback only.') }}">
            {{ __('Public addresses · origin skips edge proxy') }}
        </p>
    </div>

    @if (empty($invocationUrls))
        <div class="{{ $panelPad }} text-center text-xs text-brand-moss">
            {{ __('Not deployed yet — URLs appear after the first deploy completes.') }}
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($invocationUrls as $entry)
                @php
                    $scopeClasses = match ($entry['scope']) {
                        'upstream' => 'bg-amber-100 text-amber-900',
                        'edge' => 'bg-emerald-100 text-emerald-900',
                        'custom' => 'bg-sky-100 text-sky-900',
                        default => 'bg-brand-sand/40 text-brand-moss',
                    };
                @endphp
                <li class="{{ $panelPad }} flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between" x-data="{ copied: false }">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-xs font-semibold text-brand-ink">{{ $entry['label'] }}</span>
                            <span class="inline-flex items-center rounded-full {{ $scopeClasses }} px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.12em]">{{ $entry['scope'] }}</span>
                        </div>
                        <code class="mt-0.5 block break-all font-mono text-[11px] text-brand-moss">{{ $entry['url'] }}</code>
                    </div>
                    <button
                        type="button"
                        x-on:click="navigator.clipboard.writeText(@js($entry['url'])); copied = true; setTimeout(() => copied = false, 1500)"
                        class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                        <span x-show="!copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

<details class="group">
    <summary class="{{ $panelPad }} cursor-pointer list-none text-xs font-semibold text-brand-ink marker:content-none [&::-webkit-details-marker]:hidden">
        <span class="inline-flex items-center gap-1.5">
            <x-heroicon-o-information-circle class="h-3.5 w-3.5 text-brand-sage" aria-hidden="true" />
            {{ __('How requests flow') }}
            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-brand-mist transition group-open:rotate-180" aria-hidden="true" />
        </span>
    </summary>
    <ul class="space-y-1 border-t border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5 pl-8 text-[11px] leading-relaxed text-brand-moss sm:px-4 sm:pl-9">
        <li>{{ __('Edge requests: redirects → CORS preflight → upstream → response decoration.') }}</li>
        <li>{{ __('Origin URL bypasses dply — useful for ops debugging.') }}</li>
        <li>{{ __('Custom domains appear only after DNS status is ready on Domains.') }}</li>
    </ul>
</details>
