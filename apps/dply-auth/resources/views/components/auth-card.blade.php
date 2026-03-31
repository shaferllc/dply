@props([
    'title',
    'subtitle' => null,
])

<div class="flex-1 flex flex-col justify-center px-4 py-12 sm:py-16">
    <div class="mx-auto w-full max-w-md">
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-semibold tracking-tight text-brand-forest">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ $subtitle }}</p>
            @endif
        </div>
        <div class="rounded-2xl border border-brand-ink/10 bg-white/85 backdrop-blur-md shadow-lg shadow-brand-ink/[0.04] p-8 sm:p-9">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="mt-6 text-center text-sm text-brand-moss">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
