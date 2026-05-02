@props([
    'title',
    'meta' => null,
    'transcript' => '',
    'maxHeight' => '28rem',
    'linkHref' => null,
    'linkLabel' => null,
])

<div class="overflow-hidden rounded-[1.5rem] border border-slate-300 bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
            </div>
            <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-600">{{ $title }}</p>
        </div>

        <div class="flex items-center gap-3">
            @if ($meta)
                <p class="font-mono text-[11px] text-slate-500">{{ $meta }}</p>
            @endif

            @if ($linkHref && $linkLabel)
                <a href="{{ $linkHref }}" wire:navigate class="text-sm font-medium text-sky-700 hover:text-sky-900">{{ $linkLabel }}</a>
            @endif
        </div>
    </div>

    <div class="bg-[#fcfcfb] p-4">
        <pre class="overflow-auto rounded-xl border border-slate-300 bg-white px-4 py-4 font-mono text-[12px] leading-6 text-slate-700 whitespace-pre-wrap break-words [scrollbar-color:rgb(148_163_184_/_0.55)_transparent]" style="max-height: {{ $maxHeight }}">{{ trim($transcript) !== '' ? $transcript : __('No activity recorded yet.') }}</pre>
    </div>
</div>
