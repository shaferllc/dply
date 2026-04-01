<div class="py-12">
    <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Notifications') }}</h1>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ $notificationsReady
                        ? __('Unread: :count', ['count' => $unreadCount])
                        : __('Run the latest database migrations to enable the shared inbox.') }}
                </p>
            </div>
            @if ($notificationsReady)
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        wire:click="$set('filter', 'unread')"
                        class="rounded-lg border px-3 py-2 text-sm {{ $filter === 'unread' ? 'border-brand-ink bg-brand-ink text-brand-cream' : 'border-brand-ink/15 bg-white text-brand-ink' }}"
                    >
                        {{ __('Unread') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('filter', 'all')"
                        class="rounded-lg border px-3 py-2 text-sm {{ $filter === 'all' ? 'border-brand-ink bg-brand-ink text-brand-cream' : 'border-brand-ink/15 bg-white text-brand-ink' }}"
                    >
                        {{ __('All') }}
                    </button>
                    @if ($unreadCount > 0)
                        <button
                            type="button"
                            wire:click="markAllAsRead"
                            class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink"
                        >
                            {{ __('Mark all read') }}
                        </button>
                    @endif
                </div>
            @endif
        </div>

        <div class="space-y-3">
            @forelse ($items as $item)
                <article class="rounded-2xl border {{ $item->read_at ? 'border-brand-ink/10 bg-white' : 'border-brand-gold/50 bg-brand-sand/25' }} p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ $item->title }}</h2>
                            @if ($item->event?->category)
                                <p class="mt-1 text-xs uppercase tracking-wide text-brand-mist">{{ $item->event->category }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if (! $item->read_at)
                                <button
                                    type="button"
                                    wire:click="markAsRead('{{ $item->id }}')"
                                    class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink"
                                >
                                    {{ __('Mark read') }}
                                </button>
                            @endif
                            <span class="text-xs text-brand-mist">{{ $item->created_at?->diffForHumans() }}</span>
                        </div>
                    </div>
                    @if ($item->body)
                        <p class="mt-3 text-sm leading-6 text-brand-moss">{{ $item->body }}</p>
                    @endif
                    @if ($item->url)
                        <div class="mt-4">
                            <a href="{{ $item->url }}" class="text-sm font-medium text-brand-forest hover:text-brand-ink">
                                {{ __('Open') }}
                            </a>
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-8 text-sm text-brand-moss shadow-sm">
                    {{ $notificationsReady
                        ? __('No notifications yet.')
                        : __('Notifications will appear here after the latest database migrations are applied.') }}
                </div>
            @endforelse
        </div>
    </div>
</div>
