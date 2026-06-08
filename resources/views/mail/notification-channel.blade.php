@php
    // Map notification severity to one of the theme's button colors
    // (primary = forest, error = rust). Default keeps the on-brand forest button.
    $buttonColor = $severity === 'error' ? 'error' : 'primary';

    // Join the detail lines with a Markdown hard break ("  \n" → <br>) so each
    // renders on its own line inside the panel instead of being soft-wrapped
    // into a single run. Escape each line; the trailing-space breaks are kept.
    $panelBody = collect($bodyLines)
        ->map(fn ($line) => e(trim((string) $line)))
        ->filter(fn (string $line) => $line !== '')
        ->implode("  \n");
@endphp
<x-mail::message>
# {{ $heading }}

@if ($panelBody !== '')
<x-mail::panel>
{!! $panelBody !!}
</x-mail::panel>
@endif

@if ($actionUrl)
<x-mail::button :url="$actionUrl" :color="$buttonColor">
{{ $actionLabel }}
</x-mail::button>
@endif

{{ __('You are receiving this because a :app notification channel is routed to this event.', ['app' => config('app.name')]) }}

{{ __('— The :app team', ['app' => config('app.name')]) }}
</x-mail::message>
