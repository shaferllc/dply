<div class="py-12">
    <div class="dply-page-shell space-y-6">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Notifications'), 'icon' => 'bell-alert'],
        ]" />

        <x-page-header
            :title="__('Notifications')"
            :description="$notificationsReady ? __('Unread: :count', ['count' => $unreadCount]) : __('Run the latest database migrations to enable the shared inbox.')"
            doc-route="docs.index"
            flush
            compact
        >
            <x-slot name="actions">
                @if ($notificationsReady)
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
                @endif
            </x-slot>
        </x-page-header>

        <div class="space-y-3">
            @forelse ($items as $item)
                @php
                    $meta = is_array($item->event?->metadata ?? null) ? $item->event->metadata : [];
                    $itemSeverity = $item->event?->severity ?? ($meta['severity'] ?? null);
                    $itemResolved = strtolower((string) ($meta['insight_state'] ?? '')) === 'resolved';
                @endphp
                <x-notification-card
                    :severity="$itemSeverity"
                    :is-resolved="$itemResolved"
                    :unread="! $item->read_at"
                    :title="$item->title"
                    :body="$item->body"
                    :category="$item->event?->category"
                    :time="$item->created_at?->diffForHumans()"
                    :url="$item->url"
                >
                    @if (! $item->read_at)
                        <x-slot name="actions">
                            <button
                                type="button"
                                wire:click="markAsRead('{{ $item->id }}')"
                                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ __('Mark read') }}
                            </button>
                        </x-slot>
                    @endif
                </x-notification-card>
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
