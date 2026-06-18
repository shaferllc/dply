@props([
    // Stable seed for the gradient + initials (e.g. a server/site name or id).
    'seed' => '',
    // Corner rounding — override per surface (rounded-md for small chips, etc.).
    'rounded' => 'rounded-2xl',
    // Optional custom logo URL (e.g. Site::logoUrl()). When set, the uploaded
    // image is shown and the gradient is used only as the fallback.
    'image' => null,
])
@php
    // Deterministic gradient + initials avatar from a seed string. Two hue stops
    // pulled from a stable hash so the same seed always renders the same swatch —
    // no external service, no network roundtrip. Mirrors the server workspace
    // header so list rows, breadcrumbs, and headers share one identity.
    $avatarSeed = (string) ($seed !== '' ? $seed : 'S');
    $avatarHash = hexdec(substr(sha1($avatarSeed), 0, 12));
    $avatarHueA = $avatarHash % 360;
    $avatarHueB = ($avatarHueA + 60 + ((int) (($avatarHash >> 4) % 120))) % 360;
    $avatarInitials = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $avatarSeed) ?: 'S', 0, 2));
    $avatarStyle = "background-image: linear-gradient(135deg, hsl({$avatarHueA}deg 65% 56%) 0%, hsl({$avatarHueB}deg 65% 42%) 100%);";
@endphp
@if (filled($image))
    {{-- Uploaded/custom logo. Size + rounding come from the caller's classes. --}}
    <img
        src="{{ $image }}"
        alt="{{ $avatarSeed }}"
        {{ $attributes->merge(['class' => "shrink-0 bg-white object-cover $rounded shadow-sm ring-1 ring-brand-ink/10"]) }}
    />
@else
    <span
        {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center $rounded font-semibold text-white shadow-sm ring-1 ring-brand-ink/10"]) }}
        style="{{ $avatarStyle }}"
        aria-hidden="true"
    >{{ $avatarInitials }}</span>
@endif
