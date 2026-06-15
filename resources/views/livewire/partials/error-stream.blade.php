@php
    /**
     * Shared error-stream UI for the site & server "Errors" views. The host
     * component exposes: $this->events, $this->facets, $this->openCount and the
     * actions dismiss/restore/retry/dismissAll/setCategory + $showDismissed.
     *
     * @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $events
     * @var array<string,int> $facets
     */
    $events = $this->events;
    $facets = $this->facets;
@endphp

{{-- Live tail while open; cheap, scoped to this view only. --}}
<div wire:poll.6s class="space-y-4">
    {{-- Controls --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-1.5">
            <button type="button" wire:click="setCategory('')"
                class="rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $category === '' ? 'bg-brand-ink text-brand-cream ring-brand-ink' : 'bg-white text-brand-moss ring-brand-ink/10 hover:bg-brand-sand/40' }}">
                {{ __('All') }} <span class="opacity-60">{{ array_sum($facets) }}</span>
            </button>
            @foreach ($facets as $cat => $count)
                <button type="button" wire:click="setCategory('{{ $cat }}')"
                    class="rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $category === $cat ? 'bg-brand-ink text-brand-cream ring-brand-ink' : 'bg-white text-brand-moss ring-brand-ink/10 hover:bg-brand-sand/40' }}">
                    {{ \Illuminate\Support\Str::headline(str_replace(['_', '.', ':'], ' ', $cat)) }} <span class="opacity-60">{{ $count }}</span>
                </button>
            @endforeach
        </div>
        <div class="flex items-center gap-3">
            <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-brand-moss">
                <input type="checkbox" wire:model.live="showDismissed" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest">
                {{ __('Show dismissed') }}
            </label>
            @if ($this->openCount > 0)
                {{-- Branded confirm modal (ConfirmsActionWithModal on both host
                     components) instead of the native browser confirm(). --}}
                <button type="button"
                    wire:click="openConfirmActionModal('dismissAll', [], @js(__('Dismiss all open errors?')), @js(__('This dismisses every open error in this list. You can re-show dismissed errors with the filter. This does not fix the underlying issues.')), @js(__('Dismiss all')), false)"
                    class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-moss hover:bg-brand-sand/40">
                    {{ __('Dismiss all') }}
                </button>
            @endif
        </div>
    </div>

    {{-- Stream --}}
    @if ($events->isEmpty())
        <div class="dply-card px-6 py-10">
            <x-empty-state
                borderless
                icon="heroicon-o-check-circle"
                tone="sage"
                :title="$showDismissed ? __('No errors') : __('No open errors')"
                :description="$showDismissed
                    ? __('Nothing has failed in the retained window.')
                    : __('Everything is healthy, or all errors have been dismissed. Toggle “Show dismissed” to review past failures.')"
            />
        </div>
    @else
        <div class="dply-card divide-y divide-brand-ink/5 overflow-hidden">
            @foreach ($events as $event)
                <div class="flex items-start gap-3 px-5 py-4 {{ $event->dismissed_at ? 'opacity-55' : '' }}" wire:key="err-{{ $event->id }}" x-data="{ open: false }">
                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $event->dismissed_at ? 'bg-brand-ink/5 text-brand-mist' : 'bg-rose-50 text-rose-600 ring-1 ring-rose-600/15' }}">
                        <x-heroicon-s-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="text-sm font-semibold text-brand-ink">{{ $event->title }}</span>
                            <span class="rounded-full bg-brand-sand/50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ \Illuminate\Support\Str::headline(str_replace(['_', '.', ':'], ' ', $event->category)) }}</span>
                            @if ($event->dismissed_at)
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dismissed') }}</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-brand-mist">{{ $event->occurred_at?->diffForHumans() }}</p>
                        @if (filled($event->detail))
                            <div class="mt-1.5">
                                <p class="font-mono text-xs leading-relaxed text-brand-moss {{ '' }}" x-bind:class="open ? '' : 'line-clamp-2'">{{ $event->detail }}</p>
                                @if (strlen($event->detail) > 140)
                                    <button type="button" x-on:click="open = !open" class="mt-0.5 text-[11px] font-semibold text-brand-forest hover:underline" x-text="open ? '{{ __('Show less') }}' : '{{ __('Show more') }}'"></button>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        @php
                            $rem = $event->dismissed_at ? null : $event->remediation();
                            $recAction = $rem ? (collect($rem['actions'])->firstWhere('recommended', true) ?? ($rem['actions'][0] ?? null)) : null;
                        @endphp
                        @if ($rem && $recAction)
                            <button type="button"
                                wire:click="openConfirmActionModal('applyRemediation', ['{{ $event->id }}'], @js($rem['title']), @js($rem['explanation']), @js($recAction['label']), false, @js([
                                    ['label' => __('Action'), 'value' => $recAction['label'], 'multiline' => true],
                                    ['label' => __('Runs on'), 'value' => $event->server?->name ?? __('the server')],
                                    ['label' => __('How'), 'value' => __('dply runs this over SSH, then resolves this error if it succeeds. You can re-run the original operation afterward.'), 'multiline' => true],
                                ]))"
                                class="inline-flex items-center gap-1 rounded-lg bg-amber-500 px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-amber-600 disabled:opacity-60"
                                title="{{ $rem['title'] }}">
                                <x-heroicon-o-wrench-screwdriver class="h-4 w-4" aria-hidden="true" /> {{ __('Fix') }}
                            </button>
                        @endif
                        @if ($event->isRetryable() && ! $event->dismissed_at)
                            <button type="button" wire:click="retry('{{ $event->id }}')" wire:loading.attr="disabled" wire:target="retry('{{ $event->id }}')"
                                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60">
                                <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" /> {{ __('Retry') }}
                            </button>
                        @endif
                        @if ($event->link_url)
                            <a href="{{ $event->link_url }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-forest hover:bg-brand-sand/40">
                                {{ __('Open') }} <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                            </a>
                        @endif
                        @if ($event->dismissed_at)
                            <button type="button" wire:click="restore('{{ $event->id }}')" class="rounded-lg px-2 py-1 text-xs font-medium text-brand-mist hover:text-brand-ink" title="{{ __('Restore') }}">
                                <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                            </button>
                        @else
                            <button type="button" wire:click="dismiss('{{ $event->id }}')" class="rounded-lg px-2 py-1 text-xs font-medium text-brand-mist hover:text-brand-ink" title="{{ __('Dismiss') }}">
                                <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div>{{ $events->links() }}</div>
    @endif
</div>
