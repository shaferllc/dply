@php
    $card = 'dply-card overflow-hidden';
    $events = $this->events;
    $trends = $this->trends;
    $actors = $this->actors;
    $categories = \App\Livewire\Servers\WorkspaceActivity::CATEGORIES;
    $rangeOptions = ['24h' => __('24 hours'), '7d' => __('7 days'), '30d' => __('30 days'), '90d' => __('90 days')];

    // Stable per-category swatches so the trend bar matches its filter chip.
    $categoryColor = [
        'insights' => 'bg-amber-500',
        'firewall' => 'bg-rose-500',
        'ssh' => 'bg-violet-500',
        'caches' => 'bg-cyan-500',
        'databases' => 'bg-blue-500',
        'deploys' => 'bg-emerald-500',
        'server' => 'bg-slate-500',
        'site' => 'bg-teal-500',
        'other' => 'bg-zinc-400',
    ];

    $chartMax = max(1, ...array_map(fn (array $b): int => $b['total'], $trends['buckets']));
    $hasFilters = $category !== '' || $userId !== '' || $range !== '30d';
    $eventTotal = $events->total();
    $latestEventAt = $events->isNotEmpty() ? $events->first()->created_at : null;
    $rangeDays = \App\Livewire\Servers\WorkspaceActivity::rangeDays($range);
@endphp

{{-- Single root: this component is nested inside the Logs page's Activity tab,
     so it must not emit page chrome (no <x-server-workspace-layout>). --}}
<div class="space-y-6">

    {{-- Inline header replaces the page-layout title now that Activity is a tab. --}}
    <div>
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Activity') }}</h2>
        <p class="mt-0.5 max-w-2xl text-sm leading-relaxed text-brand-moss">
            {{ __('Audit events for this server and its sites — who did what, when, and what changed.') }}
        </p>
    </div>

    {{-- Feed / Trends switch — a light segmented control so it reads as a sub-view
         of the Logs › Activity tab rather than a second, peer-level tab bar. --}}
    <div class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-brand-sand/30 p-1" role="tablist" aria-label="{{ __('Activity sections') }}">
        <button type="button" role="tab" id="activity-tab-feed" wire:click="setTab('feed')"
            aria-selected="{{ $tab === 'feed' ? 'true' : 'false' }}"
            @class([
                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors',
                'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' => $tab === 'feed',
                'text-brand-moss hover:text-brand-ink' => $tab !== 'feed',
            ])>
            <x-heroicon-o-list-bullet class="h-4 w-4" aria-hidden="true" />
            {{ __('Feed') }}
        </button>
        <button type="button" role="tab" id="activity-tab-trends" wire:click="setTab('trends')"
            aria-selected="{{ $tab === 'trends' ? 'true' : 'false' }}"
            @class([
                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors',
                'bg-white text-brand-ink shadow-sm ring-1 ring-brand-ink/10' => $tab === 'trends',
                'text-brand-moss hover:text-brand-ink' => $tab !== 'trends',
            ])>
            <x-heroicon-o-chart-bar class="h-4 w-4" aria-hidden="true" />
            {{ __('Trends') }}
        </button>
    </div>

    {{-- Filter card — shared across both subtabs so the URL state and the visual range stay coherent. --}}
    <div class="{{ $card }}">
        <div class="flex flex-col gap-4 px-6 py-5 sm:px-8">
            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <div>
                    <label for="activity_range" class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Range') }}</label>
                    <select id="activity_range" wire:model.live="range" class="mt-1 rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                        @foreach ($rangeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="activity_actor" class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Actor') }}</label>
                    <select id="activity_actor" wire:model.live="userId" class="mt-1 rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                        <option value="">{{ __('Anyone') }}</option>
                        @foreach ($actors as $actor)
                            <option value="{{ $actor['id'] }}">{{ $actor['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($hasFilters)
                    <button type="button" wire:click="clearFilters"
                        class="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                        {{ __('Clear filters') }}
                    </button>
                @endif
            </div>

            <div class="flex flex-col gap-2">
                <span class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Category') }}</span>
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" wire:click="setCategory('')" @class([
                        'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                        'border-brand-forest bg-brand-forest text-brand-cream' => $category === '',
                        'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $category !== '',
                    ])>{{ __('All') }}</button>
                    @foreach ($categories as $key => $label)
                        <button type="button" wire:click="setCategory('{{ $key }}')" @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            'border-brand-forest bg-brand-forest text-brand-cream' => $category === $key,
                            'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $category !== $key,
                        ])>
                            <span @class(['h-2 w-2 rounded-full', $categoryColor[$key] ?? 'bg-zinc-400'])></span>
                            {{ __($label) }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @if ($tab === 'feed')
        <x-server-workspace-tab-panel
            id="activity-panel-feed"
            labelled-by="activity-tab-feed"
            :hidden="false"
        >
            <div class="{{ $card }}">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Feed') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent activity') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Server, site, deploy, and insight events — chronologically. Click "Show" on a row to see the before/after diff.') }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                {{ trans_choice('{0} no events in this range|{1} :count event|[2,*] :count events', $eventTotal, ['count' => $eventTotal]) }}
                            </span>
                            @if ($latestEventAt)
                                <span class="text-brand-mist/60">·</span>
                                <span>{{ __('latest :time', ['time' => $latestEventAt->diffForHumans()]) }}</span>
                            @endif
                            <span class="text-brand-mist/60">·</span>
                            <span>{{ trans_choice('{1} last :count day|[2,*] last :count days', $rangeDays, ['count' => $rangeDays]) }}</span>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-6 sm:px-7">
                @if ($events->isEmpty())
                    <div class="mt-6 flex flex-col items-center gap-2 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-10 text-center">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <p class="text-sm font-medium text-brand-ink">{{ __('Nothing recorded in this range.') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Firewall edits, cron saves, SSH key changes, insight fixes, and deploys will all show up here.') }}</p>
                    </div>
                @else
                    <div class="mt-6 space-y-2" x-data="{ openId: null }">
                        @foreach ($events as $event)
                            @php
                                $cat = \App\Livewire\Servers\WorkspaceActivity::categorize((string) $event->action);
                                $hasDiff = ! empty($event->old_values) || ! empty($event->new_values);
                                $actorName = $event->user?->name ?? $event->user?->email ?? __('System');
                                $subject = $event->subject_summary;
                            @endphp
                            <div wire:key="activity-{{ $event->id }}">
                                <div class="overflow-hidden rounded-xl border border-brand-ink/8 bg-white">
                                    <div class="flex flex-wrap items-start gap-x-3 gap-y-1 px-3 py-2.5 text-sm sm:px-4">
                                        <span @class([
                                            'mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-1 ring-brand-ink/10 text-white',
                                            $categoryColor[$cat] ?? 'bg-zinc-400',
                                        ])>
                                            <x-heroicon-m-bolt class="h-4 w-4" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm font-medium text-brand-ink">{{ $event->action_summary }}</span>
                                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/30 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wide text-brand-moss">
                                                    {{ __($categories[$cat] ?? $cat) }}
                                                </span>
                                                <span class="ml-auto text-[11px] text-brand-mist" title="{{ $event->created_at?->toIso8601String() }}">
                                                    {{ $event->created_at?->diffForHumans() }}
                                                </span>
                                            </div>
                                            <p class="mt-1 text-[11px] text-brand-moss">
                                                {{ __('by :actor', ['actor' => $actorName]) }}
                                                @if ($subject)
                                                    <span class="ml-1 text-brand-mist">· {{ $subject }}</span>
                                                @endif
                                                @if ($event->ip_address)
                                                    <span class="ml-1 text-brand-mist">· {{ $event->ip_address }}</span>
                                                @endif
                                                @if ($hasDiff)
                                                    <button type="button" class="ml-1 text-brand-sage hover:text-brand-forest underline decoration-brand-sage/30 hover:decoration-brand-forest"
                                                        @click="openId = (openId === '{{ $event->id }}' ? null : '{{ $event->id }}')">
                                                        <span x-text="openId === '{{ $event->id }}' ? @js(__('Hide diff')) : @js(__('Show diff'))"></span>
                                                    </button>
                                                @endif
                                            </p>
                                        </div>
                                    </div>

                                    @if ($hasDiff)
                                        <div x-show="openId === '{{ $event->id }}'" x-cloak class="border-t border-brand-ink/8 bg-brand-sand/15 px-4 py-4 sm:px-5">
                                            <div class="grid gap-3 md:grid-cols-[1fr_auto_1fr] md:items-stretch">
                                                <div class="overflow-hidden rounded-lg border border-rose-200/70 bg-rose-50/40">
                                                    <div class="flex items-center gap-1.5 border-b border-rose-200/60 bg-rose-50/60 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-rose-800">
                                                        <x-heroicon-m-minus-circle class="h-3 w-3" />
                                                        {{ __('Before') }}
                                                    </div>
                                                    <div class="p-3">
                                                        @if ($event->old_values)
                                                            <pre class="overflow-x-auto font-mono text-[11px] leading-snug text-rose-950">{{ json_encode($event->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                        @else
                                                            <p class="italic text-[11px] text-rose-900/60">{{ __('No prior values — this was created.') }}</p>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="hidden md:flex items-center justify-center px-1">
                                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white text-brand-moss ring-1 ring-brand-ink/10 shadow-sm">
                                                        <x-heroicon-m-arrow-long-right class="h-4 w-4" aria-hidden="true" />
                                                    </span>
                                                </div>

                                                <div class="overflow-hidden rounded-lg border border-emerald-200/70 bg-emerald-50/40">
                                                    <div class="flex items-center gap-1.5 border-b border-emerald-200/60 bg-emerald-50/60 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800">
                                                        <x-heroicon-m-plus-circle class="h-3 w-3" />
                                                        {{ __('After') }}
                                                    </div>
                                                    <div class="p-3">
                                                        @if ($event->new_values)
                                                            <pre class="overflow-x-auto font-mono text-[11px] leading-snug text-emerald-950">{{ json_encode($event->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                        @else
                                                            <p class="italic text-[11px] text-emerald-900/60">{{ __('No new values — this was removed.') }}</p>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-brand-mist">
                                                <span>{{ __('Action key') }}</span>
                                                <code class="inline-flex items-center rounded-md border border-brand-ink/10 bg-white px-1.5 py-0.5 font-mono text-[10px] text-brand-moss">{{ $event->action }}</code>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6">
                        {{ $events->links() }}
                    </div>
                @endif
                </div>
            </div>
        </x-server-workspace-tab-panel>
    @else
        <x-server-workspace-tab-panel
            id="activity-panel-trends"
            labelled-by="activity-tab-trends"
            :hidden="false"
        >
            <div class="{{ $card }}">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Trends') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Events per day') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Stacked by category. Hover any bar for the per-category breakdown.') }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                {{ trans_choice('{1} last :count day|[2,*] last :count days', $rangeDays, ['count' => $rangeDays]) }}
                            </span>
                            <span class="text-brand-mist/60">·</span>
                            <span>{{ __('peak :max events', ['max' => $chartMax]) }}</span>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-6 sm:px-7">
                @if (array_sum(array_column($trends['buckets'], 'total')) === 0)
                    <div class="mt-6 flex flex-col items-center gap-2 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-10 text-center">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-chart-bar class="h-5 w-5" />
                        </span>
                        <p class="text-sm font-medium text-brand-ink">{{ __('No events in this range yet.') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Widen the range or change the category filter to see more.') }}</p>
                    </div>
                @else
                    <div class="mt-8 px-1 pt-12">
                        <div class="flex items-end gap-1 h-48 sm:h-56" role="img" aria-label="{{ __('Stacked bar chart of events per day') }}">
                            @foreach ($trends['buckets'] as $bucket)
                                @php
                                    $bucketDate = \Illuminate\Support\Carbon::parse($bucket['date']);
                                    $isFirstHalf = $loop->index < (int) floor($loop->count / 2);
                                @endphp
                                <div class="group relative flex-1 min-w-[6px] h-full">
                                    <div class="h-full w-full flex flex-col-reverse rounded-sm overflow-hidden">
                                        @foreach ($categories as $key => $label)
                                            @php $count = $bucket['by_category'][$key] ?? 0; @endphp
                                            @if ($count > 0)
                                                @php $segPct = ($count / $chartMax) * 100; @endphp
                                                <div @class(['w-full', $categoryColor[$key] ?? 'bg-zinc-400']) style="height: {{ $segPct }}%"></div>
                                            @endif
                                        @endforeach
                                    </div>

                                    <div @class([
                                        'pointer-events-none absolute bottom-full mb-2 hidden group-hover:block z-20 whitespace-nowrap',
                                        'left-0' => $isFirstHalf,
                                        'right-0' => ! $isFirstHalf,
                                    ])>
                                        <div class="rounded-md border border-brand-ink/15 bg-white px-3 py-2 shadow-md">
                                            <p class="text-xs font-semibold text-brand-ink">{{ $bucketDate->format('D, M j') }}</p>
                                            <p class="text-[11px] text-brand-moss">{{ trans_choice('{0} No events|{1} :count event|[2,*] :count events', $bucket['total'], ['count' => $bucket['total']]) }}</p>
                                            @if ($bucket['total'] > 0)
                                                <div class="mt-1.5 space-y-0.5 min-w-[150px]">
                                                    @foreach ($categories as $key => $label)
                                                        @php $count = $bucket['by_category'][$key] ?? 0; @endphp
                                                        @if ($count > 0)
                                                            <div class="flex items-center gap-1.5 text-[11px]">
                                                                <span @class(['h-2 w-2 rounded-full', $categoryColor[$key] ?? 'bg-zinc-400'])></span>
                                                                <span class="text-brand-moss">{{ __($label) }}</span>
                                                                <span class="ml-auto font-semibold text-brand-ink">{{ $count }}</span>
                                                            </div>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-2 flex items-center justify-between text-[10px] uppercase tracking-[0.12em] text-brand-mist">
                            <span>{{ \Illuminate\Support\Carbon::parse($trends['buckets'][0]['date'])->format('M j') }}</span>
                            <span>{{ \Illuminate\Support\Carbon::parse($trends['buckets'][count($trends['buckets']) - 1]['date'])->format('M j') }}</span>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($trends['totals'] as $key => $total)
                            <div class="flex items-center gap-2 rounded-lg border border-brand-ink/8 bg-white px-3 py-2 text-sm">
                                <span @class(['h-2.5 w-2.5 rounded-full', $categoryColor[$key] ?? 'bg-zinc-400'])></span>
                                <span class="text-brand-moss">{{ __($categories[$key] ?? $key) }}</span>
                                <span class="ml-auto font-semibold text-brand-ink">{{ $total }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                </div>
            </div>
        </x-server-workspace-tab-panel>
    @endif
</div>
