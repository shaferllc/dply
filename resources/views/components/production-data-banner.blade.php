@props([
    'connection',
    'writesUnlocked' => false,
])

<div {{ $attributes->merge(['class' => 'border-b border-amber-600/40 bg-amber-500 text-amber-950']) }}>
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-2.5 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-start gap-2.5">
            <x-heroicon-s-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
            <div class="min-w-0 text-sm">
                <p class="font-bold tracking-wide">{{ __('PRODUCTION DATA') }}</p>
                <p class="mt-0.5 text-amber-950/90">
                    @if (filled($connection->base_url))
                        {{ __('Connected to :host', ['host' => $connection->hostLabel()]) }}
                        @if ($connection->remote_organization_name)
                            · {{ $connection->remote_organization_name }}
                        @endif
                        —
                    @endif
                    {{ __('mutations affect live sites') }}
                    @if ($writesUnlocked)
                        · <span class="font-semibold">{{ __('writes unlocked this session') }}</span>
                    @endif
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions ?? '' }}
        </div>
    </div>
</div>
