{{-- Section kicker + title + description. Pass: $kicker, $title, optional $description, optional $headingId --}}
@php
    $description = $description ?? null;
    $headingId = $headingId ?? null;
@endphp
<div class="scroll-mt-24 border-b border-brand-ink/10 pb-5">
    @if (! empty($kicker))
        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $kicker }}</p>
    @endif
    <h2 @if ($headingId) id="{{ $headingId }}" @endif class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ $title }}</h2>
    @if ($description)
        <p class="mt-2 max-w-3xl text-sm leading-relaxed text-brand-moss">{{ $description }}</p>
    @endif
</div>
