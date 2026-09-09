@php
    // Tone → dot styles. One vocabulary for the whole list instead of
    // inline conditionals per row.
    $dotPalette = [
        'success' => 'bg-brand-sage',
        'info' => 'bg-sky-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-red-500',
        'neutral' => 'bg-brand-mist',
    ];

    $eventsTotal = $this->familyTotals[''] ?? 0;
    $selected = $this->selectedLog;
    $selectFieldClass = 'h-7 rounded-md border-brand-ink/15 bg-white py-1 pl-2.5 pr-7 text-xs font-semibold text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage';
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            dense
            :organization="$organization"
            section="activity"
            :title="__('Activity')"
            :description="__('Audit trail — pick an event to see who did it and what changed.')"
            icon="heroicon-o-clock"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Activity'), 'icon' => 'clock'],
            ]"
        >
            <x-slot:actions>
                <a
                    href="{{ route('organizations.compliance-export', $organization) }}"
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-archive-box-arrow-down class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Compliance export') }}
                </a>
                @if ($family !== '' || $search !== '' || $actor !== '')
                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Clear filters') }}
                    </button>
                @endif
            </x-slot:actions>

            {{-- Filters in one line. Thirteen family pills used to wrap across
                 three rows, four of them permanently disabled at zero; the
                 select carries the same counts in a third of the height. --}}
            <x-slot:tabs>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="sr-only" for="activity-actor">{{ __('Filter by actor') }}</label>
                    <select id="activity-actor" wire:model.live="actor" class="{{ $selectFieldClass }}">
                        <option value="">{{ __('Anyone') }}</option>
                        <option value="people">{{ __('People') }}</option>
                        <option value="system">{{ __('System') }}</option>
                    </select>

                    <label class="sr-only" for="activity-family">{{ __('Filter by family') }}</label>
                    <select id="activity-family" wire:model.live="family" class="{{ $selectFieldClass }}">
                        <option value="">{{ __('All families') }} ({{ number_format($eventsTotal) }})</option>
                        @foreach ($families as $f)
                            @php $count = $this->familyTotals[$f['id']] ?? 0; @endphp
                            <option value="{{ $f['id'] }}" @disabled($count === 0 && $family !== $f['id'])>
                                {{ $f['label'] }} ({{ number_format($count) }})
                            </option>
                        @endforeach
                    </select>

                    <div class="relative w-full sm:max-w-xs">
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                            <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                        </span>
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search action or subject…') }}"
                            class="block h-7 w-full rounded-md border-brand-ink/15 bg-white py-1 ps-8 pe-2.5 text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>

                    <span class="ms-auto hidden text-xs tabular-nums text-brand-moss sm:block">
                        {{ __(':n events', ['n' => number_format($this->auditLogs->total())]) }}
                    </span>
                </div>
            </x-slot:tabs>

            @if ($this->auditLogs->isEmpty())
                <div class="px-3 py-10 text-center sm:px-4">
                    <span class="mx-auto inline-flex h-9 w-9 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-moss ring-1 ring-brand-ink/10">
                        <x-heroicon-o-inbox class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-medium text-brand-ink">
                        @if ($family !== '' || $search !== '' || $actor !== '')
                            {{ __('No activity matches the current filters.') }}
                        @else
                            {{ __('No activity yet.') }}
                        @endif
                    </p>
                    @if ($family !== '' || $search !== '' || $actor !== '')
                        <button type="button" wire:click="clearFilters" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                            {{ __('Clear filters') }}
                        </button>
                    @endif
                </div>
            @else
                {{-- List left, record right. Below lg the pane stacks under the
                     list rather than switching to a second interaction mode. --}}
                <div class="grid lg:grid-cols-[1fr_20rem] lg:divide-x lg:divide-brand-ink/10">
                    <ul id="activity-log" class="min-w-0 divide-y divide-brand-ink/10 lg:max-h-[32rem] lg:overflow-y-auto">
                        @foreach ($this->auditLogs as $log)
                            @php
                                $meta = \App\Support\AuditActionMeta::meta((string) $log->action);
                                $isSelected = $selected && $selected->id === $log->id;
                            @endphp
                            <li wire:key="log-{{ $log->id }}">
                                <button
                                    type="button"
                                    wire:click="select('{{ $log->id }}')"
                                    @class([
                                        'flex w-full items-baseline gap-2.5 px-3 py-2 text-left transition-colors sm:px-4',
                                        'bg-brand-sand/40' => $isSelected,
                                        'hover:bg-brand-sand/15' => ! $isSelected,
                                    ])
                                    @if ($isSelected) aria-current="true" @endif
                                >
                                    <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $dotPalette[$meta['tone']] ?? $dotPalette['neutral'] }}" aria-hidden="true"></span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-brand-ink">{{ $meta['label'] }}</span>
                                        <span class="mt-0.5 block truncate text-xs text-brand-moss">
                                            {{ $log->user?->name ?? __('System') }}
                                            @if ($log->subject_summary)
                                                <span class="text-brand-mist">·</span>
                                                <span class="font-mono text-brand-ink/85">{{ $log->subject_summary }}</span>
                                            @endif
                                        </span>
                                    </span>

                                    <time
                                        datetime="{{ $log->created_at?->toIso8601String() }}"
                                        class="shrink-0 font-mono text-2xs tabular-nums text-brand-mist"
                                        title="{{ $log->created_at?->toDayDateTimeString() }}"
                                    >{{ $log->created_at?->format('d M H:i') }}</time>
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    @if ($selected)
                        @php
                            $selectedMeta = \App\Support\AuditActionMeta::meta((string) $selected->action);
                            $hasDiff = ! empty($selected->old_values ?? []) || ! empty($selected->new_values ?? []);
                        @endphp
                        <aside class="border-t border-brand-ink/10 px-3 py-3 sm:px-4 lg:max-h-[32rem] lg:overflow-y-auto lg:border-t-0" aria-label="{{ __('Event detail') }}">
                            <h2 class="text-sm font-semibold text-brand-ink">{{ $selectedMeta['label'] }}</h2>

                            <dl class="mt-3 space-y-2">
                                <div class="grid grid-cols-[4.5rem_1fr] gap-2">
                                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('When') }}</dt>
                                    <dd class="text-xs text-brand-ink">{{ $selected->created_at?->toDayDateTimeString() }}</dd>
                                </div>
                                <div class="grid grid-cols-[4.5rem_1fr] gap-2">
                                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Actor') }}</dt>
                                    <dd class="text-xs text-brand-ink">{{ $selected->user?->name ?? __('System') }}</dd>
                                </div>
                                @if ($selected->ip_address)
                                    <div class="grid grid-cols-[4.5rem_1fr] gap-2">
                                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Source') }}</dt>
                                        <dd class="break-all font-mono text-xs text-brand-ink">{{ $selected->ip_address }}</dd>
                                    </div>
                                @endif
                                @if ($selected->subject_summary)
                                    <div class="grid grid-cols-[4.5rem_1fr] gap-2">
                                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Subject') }}</dt>
                                        <dd class="break-all font-mono text-xs text-brand-ink">{{ $selected->subject_summary }}</dd>
                                    </div>
                                @endif
                                <div class="grid grid-cols-[4.5rem_1fr] gap-2">
                                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Action') }}</dt>
                                    <dd class="break-all font-mono text-xs text-brand-moss">{{ $selected->action }}</dd>
                                </div>
                            </dl>

                            <div class="mt-4">
                                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Change') }}</p>
                                @if ($hasDiff)
                                    <div class="mt-1.5 space-y-2">
                                        @if (! empty($selected->old_values))
                                            <div>
                                                <p class="text-2xs text-brand-mist">{{ __('Before') }}</p>
                                                <pre class="mt-1 whitespace-pre-wrap break-all rounded-md border border-brand-ink/10 bg-brand-cream/50 p-2 font-mono text-2xs leading-relaxed text-brand-ink">{{ json_encode($selected->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        @endif
                                        @if (! empty($selected->new_values))
                                            <div>
                                                <p class="text-2xs text-brand-mist">{{ __('After') }}</p>
                                                <pre class="mt-1 whitespace-pre-wrap break-all rounded-md border border-brand-ink/10 bg-brand-cream/50 p-2 font-mono text-2xs leading-relaxed text-brand-ink">{{ json_encode($selected->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <p class="mt-1.5 text-xs text-brand-moss">{{ __('This event recorded no field changes.') }}</p>
                                @endif
                            </div>
                        </aside>
                    @endif
                </div>

                <div class="flex flex-col items-stretch gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-4">
                    <label class="inline-flex items-center gap-2 text-xs text-brand-moss" for="activity-per-page">
                        <span class="whitespace-nowrap">{{ __('Rows per page') }}</span>
                        <select
                            id="activity-per-page"
                            wire:model.live="perPage"
                            class="h-7 rounded-md border-brand-ink/15 bg-white py-1 pl-2 pr-7 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        >
                            @foreach ([10, 25, 50, 100] as $n)
                                <option value="{{ $n }}">{{ $n }}</option>
                            @endforeach
                        </select>
                    </label>
                    {{-- Prev/next only. The numbered links ran to 19 buttons at
                         25 rows a page and nobody navigates an audit log by
                         page number; the total lives in the header. --}}
                    <div class="flex items-center gap-1.5">
                        <button
                            type="button"
                            wire:click="previousPage"
                            x-on:click="document.getElementById('activity-log')?.scrollTo({ top: 0 })"
                            @disabled($this->auditLogs->onFirstPage())
                            class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-white"
                        >
                            <x-heroicon-m-chevron-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Previous') }}
                        </button>
                        <button
                            type="button"
                            wire:click="nextPage"
                            x-on:click="document.getElementById('activity-log')?.scrollTo({ top: 0 })"
                            @disabled(! $this->auditLogs->hasMorePages())
                            class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-white"
                        >
                            {{ __('Next') }}
                            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </button>
                    </div>
                </div>
            @endif
        </x-organization-shell>
    </div>
</div>
