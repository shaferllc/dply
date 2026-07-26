<div class="contents">
    <x-production-data-banner :connection="$connection" :writes-unlocked="$writesUnlocked">
        <x-slot:actions>
            <a href="{{ route('live.sites.index') }}" wire:navigate class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                {{ __('Back to sites') }}
            </a>
        </x-slot:actions>
    </x-production-data-banner>
    <x-production-data-nav :connection="$connection" />

    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        @if ($error)
            <x-alert tone="danger">{{ $error }}</x-alert>
            <div class="mt-6">
                <a href="{{ route('live.sites.index') }}" wire:navigate class="text-sm font-semibold text-brand-forest hover:underline">
                    {{ __('← Production sites') }}
                </a>
            </div>
        @else
            <div class="flex flex-col items-center justify-center gap-3 text-center">
                <x-spinner class="h-8 w-8 text-brand-forest" />
                <p class="text-sm font-semibold text-brand-ink">{{ __('Opening production site…') }}</p>
                <p class="max-w-md text-sm text-brand-moss">
                    {{ __('Loading this site from the Production API into your local workspace.') }}
                </p>
            </div>
        @endif
    </div>
</div>
