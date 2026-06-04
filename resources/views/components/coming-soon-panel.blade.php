@props([
    'title',
    'description' => null,
    'icon' => 'heroicon-o-sparkles',
    /** @var array<int,string> optional bullet list of what's coming */
    'points' => [],
])

<section class="dply-card overflow-hidden">
    <div class="flex flex-col items-center gap-4 px-6 py-14 text-center sm:px-10">
        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-dynamic-component :component="$icon" class="h-7 w-7" aria-hidden="true" />
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/70 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
            <x-heroicon-m-clock class="h-3.5 w-3.5" aria-hidden="true" />
            {{ __('Coming soon') }}
        </span>
        <h2 class="max-w-xl text-xl font-semibold text-brand-ink">{{ $title }}</h2>
        @if ($description)
            <p class="max-w-xl text-sm leading-relaxed text-brand-moss">{{ $description }}</p>
        @endif
        @if (! empty($points))
            <ul class="mt-2 grid max-w-xl gap-2 text-left text-sm text-brand-moss sm:grid-cols-1">
                @foreach ($points as $point)
                    <li class="flex items-start gap-2">
                        <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        <span>{{ $point }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="mt-1 text-xs text-brand-mist">{{ __('We’re building this — it’ll light up here when it’s ready.') }}</p>
    </div>
</section>
