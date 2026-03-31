@props([
    'hidden' => false,
    'id',
    'labelledBy',
    /** Extra classes on the panel wrapper (e.g. space-y-8) */
    'panelClass' => '',
])

<div
    role="tabpanel"
    id="{{ $id }}"
    aria-labelledby="{{ $labelledBy }}"
    aria-hidden="{{ $hidden ? 'true' : 'false' }}"
    {{ $attributes->class([$panelClass, 'hidden' => $hidden]) }}
>
    {{ $slot }}
</div>
