{{--
    Body skeleton for a Settings section, shaped per section so the skeleton
    resembles the panel that's arriving. One generic field-grid used to stand in
    for every category, which read wrong on the short ones (Export is a single
    button) and on Keys (a key textarea, not form fields).

    Rendered by workspace-settings-placeholder during the #[Lazy] load, which is
    also what you see between sections — the strip is links, so switching
    navigates and re-serves this. Shape and highlighted tab both come from the
    requested ?tab=, so it looks like the destination from the first frame.

    Head metrics track the real section head (x-workspace-panel-head dense) so
    the card doesn't resize when the body swaps in.

    Receives: $section (slug).
--}}
@php
    $skeletonSection = $section ?? 'connection';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
    // Danger's real head is tone="danger" — match its tint, not the sand default.
    $headTone = $skeletonSection === 'danger' ? 'bg-rose-50/60' : 'bg-brand-sand/20';
@endphp

<div class="border-b border-brand-ink/10" aria-hidden="true">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 {{ $headTone }} px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-36 shrink-0 {{ $bar }}"></span>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        {{-- The real dense head truncates its note to whatever width is left, so
             the stub takes the remaining space rather than a fixed short bar. --}}
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
    </div>

    @switch ($skeletonSection)
        {{-- Key textarea + Copy, fingerprint row + Copy, then the Git-host pills. --}}
        @case ('keys')
            <div class="space-y-4 px-5 py-4 sm:px-6">
                <div class="space-y-1.5">
                    <div class="h-2.5 w-40 {{ $bar }}"></div>
                    <div class="flex gap-2">
                        <div class="h-20 flex-1 rounded-lg {{ $bar }}"></div>
                        <div class="h-10 w-16 shrink-0 rounded-lg {{ $bar }}"></div>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <div class="h-2.5 w-32 {{ $bar }}"></div>
                    <div class="flex gap-2">
                        <div class="h-10 flex-1 rounded-lg {{ $bar }}"></div>
                        <div class="h-10 w-16 shrink-0 rounded-lg {{ $bar }}"></div>
                    </div>
                </div>
                <div class="space-y-2">
                    <div class="h-2.5 w-28 {{ $bar }}"></div>
                    <div class="flex flex-wrap gap-2">
                        @foreach (range(1, 3) as $host)
                            <div class="h-7 w-24 rounded-lg {{ $bar }}"></div>
                        @endforeach
                    </div>
                </div>
            </div>
            @break

        {{-- Compose box, helper row + Add button, divider, then note cards. --}}
        @case ('notes')
            <div class="space-y-5 px-5 py-4 sm:px-6">
                <div class="space-y-3">
                    <div class="h-24 w-full rounded-lg {{ $bar }}"></div>
                    <div class="flex items-center justify-between gap-3">
                        <div class="h-2.5 w-56 {{ $bar }}"></div>
                        <div class="h-9 w-24 shrink-0 rounded-lg {{ $bar }}"></div>
                    </div>
                </div>
                <div class="border-t border-brand-ink/10"></div>
                @foreach (range(1, 2) as $note)
                    <div class="space-y-2 rounded-xl border border-brand-ink/10 bg-white px-4 py-4 sm:px-5">
                        {{-- Note bodies are full-bleed markdown, so the lines run
                             the width of the card; only the byline is short. --}}
                        <div class="h-2.5 w-full {{ $bar }}"></div>
                        <div class="h-2.5 w-3/4 {{ $bar }}"></div>
                        <div class="mt-3 h-2 w-40 {{ $bar }}"></div>
                    </div>
                @endforeach
            </div>
            @break

        {{-- One download button. --}}
        @case ('export')
            <div class="px-5 py-4 sm:px-6">
                <div class="h-10 w-56 rounded-lg {{ $bar }}"></div>
            </div>
            @break

        {{-- Heading, then the destructive action button. --}}
        @case ('danger')
            <div class="px-5 py-4 sm:px-6">
                <div class="h-3 w-28 {{ $bar }}"></div>
                <div class="mt-4 h-10 w-64 animate-pulse rounded-lg bg-rose-200/60"></div>
            </div>
            @break

        {{-- Heading, prose, full-width cost note + pull button, then two fields. --}}
        @case ('governance')
            <div class="space-y-4 px-5 py-4 sm:px-6">
                <div class="h-3 w-36 {{ $bar }}"></div>
                {{-- The real intro paragraph has no max-width, so neither do these. --}}
                <div class="space-y-1.5">
                    <div class="h-2.5 w-full {{ $bar }}"></div>
                    <div class="h-2.5 w-3/4 {{ $bar }}"></div>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <div class="flex items-end justify-between gap-3">
                            <div class="h-2.5 w-36 {{ $bar }}"></div>
                            <div class="h-7 w-28 shrink-0 rounded-lg {{ $bar }}"></div>
                        </div>
                        <div class="h-10 w-full rounded-lg {{ $bar }}"></div>
                    </div>
                    @foreach (range(1, 2) as $field)
                        <div class="space-y-2">
                            <div class="h-2.5 w-24 {{ $bar }}"></div>
                            <div class="h-10 w-full rounded-lg {{ $bar }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="h-9 w-32 rounded-lg {{ $bar }}"></div>
            </div>
            @break

        {{-- connection / inventory / anything new: titled group, two full-width
             rows (name, tags), a two-up row, then Save. --}}
        @default
            <div class="space-y-5 px-5 py-4 sm:px-6">
                <div class="space-y-1">
                    <div class="h-3 w-24 {{ $bar }}"></div>
                    <div class="h-2.5 w-3/4 {{ $bar }}"></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (range(1, 2) as $wide)
                        <div class="space-y-2 sm:col-span-2">
                            <div class="h-2.5 w-28 {{ $bar }}"></div>
                            <div class="h-10 w-full rounded-lg {{ $bar }}"></div>
                        </div>
                    @endforeach
                    @foreach (range(1, 2) as $field)
                        <div class="space-y-2">
                            <div class="h-2.5 w-24 {{ $bar }}"></div>
                            <div class="h-10 w-full rounded-lg {{ $bar }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="h-9 w-28 rounded-lg {{ $bar }}"></div>
            </div>
    @endswitch
</div>
