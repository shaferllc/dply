@props([
    'resource',
    'heading' => __('Recent notifications'),
    'manageUrl' => null,
])

@php
    $notificationTablesReady = \Illuminate\Support\Facades\Schema::hasTable('notification_events');
    $items = $notificationTablesReady
        ? \App\Models\NotificationEvent::query()
            ->forResource($resource::class, (string) $resource->getKey())
            ->latest()
            ->limit(5)
            ->get()
        : collect();
@endphp

<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-base font-semibold text-brand-ink">{{ $heading }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Every resource can now publish into one shared notification stream while still routing copies to subscribed destinations.') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if ($manageUrl)
                <a href="{{ $manageUrl }}" class="text-sm font-medium text-brand-forest hover:text-brand-ink">
                    {{ __('Manage routing') }}
                </a>
            @endif
            @if ($notificationTablesReady)
                <a href="{{ route('notifications.index') }}" class="text-sm font-medium text-brand-forest hover:text-brand-ink">
                    {{ __('Open inbox') }}
                </a>
            @endif
        </div>
    </div>

    <div class="mt-4 space-y-3">
        @forelse ($items as $item)
            <article class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-brand-ink">{{ $item->title }}</p>
                        @if ($item->body)
                            <p class="mt-1 text-sm leading-6 text-brand-moss">{{ $item->body }}</p>
                        @endif
                    </div>
                    <span class="text-xs text-brand-mist">{{ $item->created_at?->diffForHumans() }}</span>
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/10 p-4 text-sm text-brand-moss">
                {{ $notificationTablesReady
                    ? __('No notifications have been published for this resource yet.')
                    : __('Notifications will appear here after the latest database migrations are applied.') }}
            </div>
        @endforelse
    </div>
</section>
