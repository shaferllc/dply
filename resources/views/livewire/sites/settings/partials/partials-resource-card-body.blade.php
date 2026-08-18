{{-- Shared body for a resource-palette card on the Resources hub. Expects (from
     the parent @foreach scope): $t, $attached, $binding, $envKeys, $statusBadge.
     Queue has no file/array fallback — show a hint until Redis or a database
     is attached (matches SiteBindingManager::assertDriverDependency). --}}
@php
    $needsRedis = ($t['type'] ?? '') === 'queue'
        && \App\Support\Sites\SiteBindingCatalog::missingNeedsAny($hubBindings, $t['needsAny'] ?? []);
@endphp
<div class="flex items-start justify-between gap-2">
    <div class="flex items-center gap-2">
        <span class="flex h-7 w-7 items-center justify-center rounded-lg {{ $attached ? 'bg-brand-forest/10 text-brand-forest' : 'bg-brand-sand/50 text-brand-moss' }}">
            <x-dynamic-component :component="$t['icon']" class="h-4 w-4" />
        </span>
        <span class="text-sm font-semibold text-brand-ink">{{ $t['label'] }}</span>
    </div>
    @if ($attached)
        <span class="shrink-0 rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $statusBadge[$binding->status] ?? 'bg-brand-sand/60 text-brand-moss' }}">{{ $binding->status }}</span>
    @else
        <span class="inline-flex shrink-0 items-center gap-0.5 text-xs font-semibold text-brand-moss group-hover:text-brand-forest">
            <x-heroicon-o-plus class="h-4 w-4" /> {{ __('Add') }}
        </span>
    @endif
</div>

@if ($attached && $envKeys !== [])
    <p class="truncate font-mono text-2xs text-brand-mist" title="{{ implode(', ', $envKeys) }}">
        {{ collect($envKeys)->take(4)->implode(' · ') }}{{ count($envKeys) > 4 ? ' …' : '' }}
    </p>
@elseif (! $attached)
    <p class="text-xs leading-snug text-brand-moss">{{ $t['purpose'] }}</p>
@endif

@if ($needsRedis)
    <p class="inline-flex w-fit items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-2xs font-medium text-amber-700">
        <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ __('needs Redis or a database') }}
    </p>
@endif
