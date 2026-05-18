@php
    $searchQuery = request()->input('q') ?? '';
@endphp

<div class="mt-6">
    <form action="{{ route('fleet.health') }}" method="GET" class="flex items-center gap-2 max-w-md mx-auto">
        <div class="relative flex-1">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <x-heroicon-o-magnifying-glass class="h-5 w-5 text-brand-moss" />
            </div>
            <input
                type="text"
                name="q"
                value="{{ $searchQuery }}"
                placeholder="{{ __('Search servers, sites, or domains...') }}"
                class="block w-full pl-10 pr-3 py-2.5 border border-brand-ink/15 rounded-lg bg-white text-sm text-brand-ink placeholder:text-brand-moss/60 focus:outline-none focus:ring-2 focus:ring-brand-gold/40 focus:border-brand-gold"
            />
        </div>
        <button
            type="submit"
            class="inline-flex items-center px-4 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-medium hover:bg-brand-forest transition-colors"
        >
            {{ __('Search') }}
        </button>
    </form>
    <p class="mt-2 text-xs text-brand-moss">
        {{ __('Search across your fleet to find what you were looking for.') }}
    </p>
</div>
