{{-- The assign form as a stepper: one decision on screen at a time, finished
     steps collapsed to a summary line you can click back into. Replaces three
     stacked full-height sections — the page is never taller than the step you
     are on, and the order (channels → events → targets) is now explicit. --}}
@include('livewire.settings.partials._bna-shared')

@php
    $eventTotal = collect($eventCatalog)->sum(fn ($c) => count($c['events'] ?? []));
    $targetTotal = count($selected_server_ids) + count($selected_site_ids);
    $chosenChannels = $assignableChannels->whereIn('id', $selected_channel_ids)->pluck('label')->take(3);
    $link = 'text-xs font-semibold text-brand-sage transition-colors hover:text-brand-ink';
@endphp

<div x-data="{ step: 1 }" class="divide-y divide-brand-ink/10">
    {{-- Step 1 --}}
    <div>
        <button type="button" x-on:click="step = 1" class="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-brand-sand/20 sm:px-4">
            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-2xs font-bold" :class="step === 1 ? 'bg-brand-ink text-brand-cream' : 'bg-brand-sand/60 text-brand-moss'">1</span>
            <span class="text-sm font-semibold text-brand-ink">{{ __('Channels') }}</span>
            <span class="min-w-0 flex-1 truncate text-xs text-brand-moss">
                {{ $selected_channel_ids === [] ? __('none selected') : $chosenChannels->join(', ').(count($selected_channel_ids) > 3 ? ' +'.(count($selected_channel_ids) - 3) : '') }}
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-mist transition-transform" ::class="step === 1 && 'rotate-180'" aria-hidden="true" />
        </button>
        {{-- No x-cloak here: step 1 is the open one on load, so it should
             paint immediately. Steps 2 and 3 are cloaked instead. --}}
        <div x-show="step === 1" x-collapse>
            <div class="flex items-center justify-end gap-2 px-3 pb-1 sm:px-4">
                <button type="button" wire:click="selectAllChannels" class="{{ $link }}">{{ __('Select all') }}</button>
                <button type="button" wire:click="deselectAllChannels" class="{{ $link }} text-brand-moss">{{ __('Clear') }}</button>
            </div>
            <div class="max-h-64 space-y-1 overflow-y-auto px-3 pb-3 sm:px-4">
                @forelse ($assignableChannels as $ch)
                    <label class="flex cursor-pointer items-center gap-2 rounded px-1 py-1 text-sm hover:bg-brand-sand/30">
                        <input type="checkbox" wire:model.live="selected_channel_ids" value="{{ $ch->id }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                        {{-- Type and destination ride WITH the label, not flushed to
                             the far edge: two channels both called "test" are only
                             tellable apart by what comes after the name, and at row
                             width that was 1500px away from it. --}}
                        <span class="shrink-0 font-medium text-brand-ink">{{ $ch->label }}</span>
                        <span class="shrink-0 rounded bg-brand-sand/55 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($ch->type) }}</span>
                        @php
                            $destination = $ch->describeDestination();
                        @endphp
                        @if ($destination !== '')
                            <span class="min-w-0 truncate font-mono text-2xs text-brand-mist" title="{{ $destination }}">{{ $destination }}</span>
                        @endif
                    </label>
                @empty
                    <p class="text-xs text-brand-moss">{{ __('No channels yet.') }}</p>
                    @if ($quickAddTypes !== [])
                        <button type="button" wire:click="openQuickNotificationChannelModal" class="mt-1 inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ __('Add channel') }}
                        </button>
                    @endif
                @endforelse
            </div>
            <div class="flex justify-end px-3 pb-3 sm:px-4">
                <button type="button" x-on:click="step = 2" class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">{{ __('Next: events') }} →</button>
            </div>
        </div>
    </div>

    {{-- Step 2 --}}
    <div>
        <button type="button" x-on:click="step = 2" class="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-brand-sand/20 sm:px-4">
            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-2xs font-bold" :class="step === 2 ? 'bg-brand-ink text-brand-cream' : 'bg-brand-sand/60 text-brand-moss'">2</span>
            <span class="text-sm font-semibold text-brand-ink">{{ __('Events') }}</span>
            <span class="min-w-0 flex-1 truncate text-xs text-brand-moss">{{ trans_choice(':n selected|:n selected', count($selected_event_keys), ['n' => count($selected_event_keys)]) }} {{ __('of :total', ['total' => $eventTotal]) }}</span>
            <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-mist transition-transform" ::class="step === 2 && 'rotate-180'" aria-hidden="true" />
        </button>
        <div x-show="step === 2" x-cloak x-collapse>
            <div class="flex items-center justify-end gap-2 px-3 pb-1 sm:px-4">
                <button type="button" wire:click="selectAllEvents" class="{{ $link }}">{{ __('Select all') }}</button>
                <button type="button" wire:click="deselectAllEvents" class="{{ $link }} text-brand-moss">{{ __('Clear') }}</button>
            </div>
            {{-- One block per category, hairline-separated, with its label pinned
                 in a left column. Flowing every category into the same two-column
                 grid ran them together: a one-event category looked like a stray
                 row of the category above it, and reading order was ambiguous. --}}
            <div class="max-h-72 divide-y divide-brand-ink/10 overflow-y-auto border-y border-brand-ink/10">
                @foreach ($eventCatalog as $cat)
                    @php
                        $catKeys = array_keys($cat['events'] ?? []);
                        $catChosen = count(array_intersect($selected_event_keys, $catKeys));
                    @endphp
                    <div class="grid gap-x-4 px-3 py-2.5 sm:grid-cols-[11rem_minmax(0,1fr)] sm:px-4">
                        <div class="mb-1 flex items-baseline gap-1.5 sm:mb-0">
                            <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $cat['label'] }}</p>
                            <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $catChosen }}/{{ count($catKeys) }}</span>
                        </div>
                        <div class="grid gap-x-6 gap-y-0.5 lg:grid-cols-2">
                            @foreach ($cat['events'] as $eventKey => $eventLabel)
                                @php
                                    $needsAction = str_contains($eventLabel, '(action required)');
                                @endphp
                                <label class="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-brand-sand/30">
                                    <input type="checkbox" wire:model.live="selected_event_keys" value="{{ $eventKey }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                                    <span class="min-w-0 truncate text-brand-ink">{{ trim(str_replace('(action required)', '', $eventLabel)) }}</span>
                                    @if ($needsAction)
                                        {{-- The suffix repeated on a third of the rows and pushed
                                             the part you read off the end. Same signal, one glyph. --}}
                                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0 text-amber-500" aria-hidden="true" title="{{ __('Action required') }}" />
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-end gap-2 px-3 pb-3 sm:px-4">
                <button type="button" x-on:click="step = 1" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">← {{ __('Back') }}</button>
                <button type="button" x-on:click="step = 3" class="inline-flex h-6 items-center rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">{{ __('Next: targets') }} →</button>
            </div>
        </div>
    </div>

    {{-- Step 3 --}}
    <div>
        <button type="button" x-on:click="step = 3" class="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-brand-sand/20 sm:px-4">
            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-2xs font-bold" :class="step === 3 ? 'bg-brand-ink text-brand-cream' : 'bg-brand-sand/60 text-brand-moss'">3</span>
            <span class="text-sm font-semibold text-brand-ink">{{ __('Targets') }}</span>
            <span class="min-w-0 flex-1 truncate text-xs text-brand-moss">{{ $apply_org_wide ? __('everything in the organization') : trans_choice(':n selected|:n selected', $targetTotal, ['n' => $targetTotal]) }}</span>
            <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-mist transition-transform" ::class="step === 3 && 'rotate-180'" aria-hidden="true" />
        </button>
        <div x-show="step === 3" x-cloak x-collapse>
            <div class="px-3 pb-3 sm:px-4">
                <label class="flex cursor-pointer items-start gap-2 rounded-md bg-brand-sand/25 px-2 py-1.5">
                    <input type="checkbox" wire:model.live="apply_org_wide" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-brand-ink">{{ __('Everything in :org', ['org' => $currentOrganization?->name ?? __('this organization')]) }}</span>
                        <span class="block text-2xs text-brand-moss">{{ __('Including servers and sites added later.') }}</span>
                    </span>
                </label>

                {{-- Two bounded panels, not two columns of one list: a bare
                     two-column grid read as a single wide list, so which side a
                     name belonged to was guesswork. Each has its own header,
                     count, All/None, and scroll box. --}}
                <div @class(['mt-2 grid gap-3 lg:grid-cols-2', 'hidden' => $apply_org_wide])>
                    @foreach ([
                        ['label' => __('Servers'), 'icon' => 'heroicon-o-server', 'rows' => $servers, 'model' => 'selected_server_ids', 'chosen' => count($selected_server_ids), 'all' => 'selectAllServers', 'none' => 'deselectAllServers', 'empty' => __('No servers in this organization.')],
                        ['label' => __('Sites'), 'icon' => 'heroicon-o-globe-alt', 'rows' => $sites, 'model' => 'selected_site_ids', 'chosen' => count($selected_site_ids), 'all' => 'selectAllSites', 'none' => 'deselectAllSites', 'empty' => __('No sites in this organization.')],
                    ] as $pane)
                        <div class="overflow-hidden rounded-lg border border-brand-ink/12 bg-white">
                            <div class="flex items-center gap-1.5 border-b border-brand-ink/10 bg-brand-sand/30 px-2 py-1.5">
                                <x-dynamic-component :component="$pane['icon']" class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                                <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $pane['label'] }}</span>
                                <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $pane['chosen'] }}/{{ $pane['rows']->count() }}</span>
                                @if ($pane['rows']->isNotEmpty())
                                    <span class="ms-auto flex gap-2">
                                        <button type="button" wire:click="{{ $pane['all'] }}" class="{{ $link }}">{{ __('All') }}</button>
                                        <button type="button" wire:click="{{ $pane['none'] }}" class="{{ $link }} text-brand-moss">{{ __('None') }}</button>
                                    </span>
                                @endif
                            </div>
                            <div class="max-h-44 overflow-y-auto px-2 py-1.5">
                                @forelse ($pane['rows'] as $row)
                                    <label class="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-brand-sand/30">
                                        <input type="checkbox" wire:model.live="{{ $pane['model'] }}" value="{{ $row->id }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                                        <span class="min-w-0 truncate text-brand-ink">{{ $row->name }}</span>
                                    </label>
                                @empty
                                    <p class="px-1 py-1 text-xs text-brand-mist">{{ $pane['empty'] }}</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('selected_server_ids')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                @error('selected_site_ids')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                @error('apply_org_wide')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
    </div>
</div>

<div class="flex items-center justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
    <button type="button" wire:click="assign" wire:loading.attr="disabled" wire:target="assign" @disabled(! $this->canSubmitAssign())
        class="inline-flex h-7 items-center gap-1.5 rounded-md bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-40">
        <span wire:loading.remove wire:target="assign">{{ __('Assign notifications') }}</span>
        <span wire:loading wire:target="assign" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Assigning…') }}</span>
    </button>
</div>
