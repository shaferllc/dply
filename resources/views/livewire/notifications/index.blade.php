<div class="py-8">
    <div class="dply-page-shell space-y-6">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Notifications'), 'icon' => 'bell-alert'],
        ]" />

        {{-- Hero: positioning + at-a-glance rollups. --}}
        <section class="dply-card overflow-hidden">
            <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                <div class="lg:col-span-7">
                    <div class="flex items-start gap-3">
                        <x-icon-badge size="md">
                            <x-heroicon-o-bell class="h-6 w-6" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Inbox') }}</p>
                            <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Notifications') }}</h2>
                            <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                {{ $notificationsReady
                                    ? __('Deploys, monitoring alerts, SSL events, and security findings across everything you can access.')
                                    : __('Run the latest database migrations to enable the shared inbox.') }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-docs-link doc-route="docs.index">
                            <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Documentation') }}
                        </x-docs-link>
                        @if ($notificationsReady && $unreadCount > 0)
                            <button
                                type="button"
                                wire:click="markAllAsRead"
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Mark all read') }}
                            </button>
                        @endif
                    </div>
                </div>
                <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-brand-gold/40 bg-brand-gold/8' => $unreadCount > 0,
                        'border-brand-ink/10 bg-white' => $unreadCount === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Unread') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $unreadCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('new|new', $unreadCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ $unreadCount > 0 ? __('Waiting on you') : __('All caught up') }}</p>
                    </div>
                    <div @class([
                        'rounded-2xl border px-4 py-3 shadow-sm',
                        'border-amber-200 bg-amber-50/60' => $attentionCount > 0,
                        'border-brand-ink/10 bg-white' => $attentionCount === 0,
                    ])>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Attention') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums {{ $attentionCount > 0 ? 'text-amber-700' : 'text-brand-ink' }}">{{ $attentionCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('alert|alerts', $attentionCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Warnings & failures') }}</p>
                    </div>
                    <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Total') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $totalCount }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('item|items', $totalCount) }}</span>
                        </dd>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('In your inbox') }}</p>
                    </div>
                </dl>
            </div>
        </section>

        @if ($notificationsReady)
            {{-- Toolbar: filter + mark all read. --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="inline-flex items-center gap-1 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm">
                    <button
                        type="button"
                        wire:click="$set('filter', 'unread')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition',
                            'bg-brand-ink text-brand-cream shadow-sm' => $filter === 'unread',
                            'text-brand-moss hover:text-brand-ink' => $filter !== 'unread',
                        ])
                    >
                        {{ __('Unread') }}
                        @if ($unreadCount > 0)
                            <span @class([
                                'rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                                'bg-brand-cream/20 text-brand-cream' => $filter === 'unread',
                                'bg-brand-sand/60 text-brand-moss' => $filter !== 'unread',
                            ])>{{ $unreadCount }}</span>
                        @endif
                    </button>
                    <button
                        type="button"
                        wire:click="$set('filter', 'all')"
                        @class([
                            'inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold transition',
                            'bg-brand-ink text-brand-cream shadow-sm' => $filter === 'all',
                            'text-brand-moss hover:text-brand-ink' => $filter !== 'all',
                        ])
                    >
                        {{ __('All') }}
                    </button>
                </div>

                @if ($unreadCount > 0)
                    <button
                        type="button"
                        wire:click="markAllAsRead"
                        class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Mark all read') }}
                    </button>
                @endif
            </div>
        @endif

        {{-- Feed. --}}
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
                <div class="dply-card flex flex-col items-center gap-3 px-6 py-14 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-bell-slash class="h-6 w-6" aria-hidden="true" />
                    </span>
                    @if (! $notificationsReady)
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Notifications not ready') }}</p>
                        <p class="max-w-sm text-sm text-brand-moss">{{ __('They’ll appear here after the latest database migrations are applied.') }}</p>
                    @elseif ($filter === 'unread')
                        <p class="text-sm font-semibold text-brand-ink">{{ __('You’re all caught up') }}</p>
                        <p class="max-w-sm text-sm text-brand-moss">{{ __('No unread notifications. Switch to “All” to see your history.') }}</p>
                        <button type="button" wire:click="$set('filter', 'all')" class="mt-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('View all notifications') }}</button>
                    @else
                        <p class="text-sm font-semibold text-brand-ink">{{ __('No notifications yet') }}</p>
                        <p class="max-w-sm text-sm text-brand-moss">{{ __('Deploys, monitoring alerts, and SSL events will show up here.') }}</p>
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</div>
