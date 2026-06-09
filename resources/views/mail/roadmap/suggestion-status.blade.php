<x-mail::message>
# {{ __('Roadmap suggestion update') }}

@if ($event === 'promoted')
{{ __('Thanks for the idea — we added **:title** to the product roadmap as a planned item.', ['title' => $suggestion->title]) }}

@if ($roadmapItem && $roadmapItem->is_published)
{{ __('It is visible on the public roadmap now.') }}
@else
{{ __('We are shaping it internally first; it may appear on the public board once we publish it.') }}
@endif
@elseif ($event === 'declined')
{{ __('Thanks for suggesting **:title**. We reviewed it and will not pursue it on the roadmap right now.', ['title' => $suggestion->title]) }}

{{ __('We still appreciate the feedback — it helps us prioritize.') }}
@else
{{ __('We reviewed your suggestion **:title** and noted it for prioritization.', ['title' => $suggestion->title]) }}
@endif

<x-mail::button :url="$roadmapUrl">
{{ __('View the roadmap') }}
</x-mail::button>

{{ __('— The :app team', ['app' => config('app.name')]) }}
</x-mail::message>
