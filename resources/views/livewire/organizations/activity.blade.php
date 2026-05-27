@php
    // Tone → tile / dot styles. Centralized here so every row uses the
    // same vocabulary instead of inline conditionals on every entry.
    $tonePalette = [
        'success' => ['tile' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25', 'dot' => 'bg-brand-sage'],
        'info' => ['tile' => 'bg-sky-50 text-sky-700 ring-sky-200', 'dot' => 'bg-sky-500'],
        'warning' => ['tile' => 'bg-amber-50 text-amber-900 ring-amber-200', 'dot' => 'bg-amber-500'],
        'danger' => ['tile' => 'bg-red-50 text-red-700 ring-red-200', 'dot' => 'bg-red-500'],
        'neutral' => ['tile' => 'bg-brand-sand/45 text-brand-moss ring-brand-ink/10', 'dot' => 'bg-brand-mist'],
    ];
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="activity">
            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Activity'), 'icon' => 'archive-box'],
            ]" />

            <x-page-header
                :title="__('Activity')"
                :description="__('Audit trail for this organization. Every meaningful change is logged here — filter by family or search by action / subject.')"
                doc-route="docs.index"
                toolbar
                flush
            >
                <x-slot name="leading">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                        <x-heroicon-o-archive-box class="h-7 w-7 text-brand-ink" aria-hidden="true" />
                    </span>
                </x-slot>
                <x-slot name="actions">
                    @if ($family !== '' || $search !== '')
                        <button
                            type="button"
                            wire:click="clearFilters"
                            class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-x-mark class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Clear filters') }}
                        </button>
                    @endif
                </x-slot>
            </x-page-header>

            {{-- Filter bar: family pills with per-family counts + search box. --}}
            <div class="mt-6 space-y-4">
                <nav class="flex flex-wrap items-center gap-2" aria-label="{{ __('Family filter') }}">
                    @php $allTotal = $this->familyTotals[''] ?? 0; @endphp
                    <button
                        type="button"
                        wire:click="setFamily('')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition shadow-sm',
                            'border-brand-ink bg-brand-ink text-brand-cream' => $family === '',
                            'border-brand-ink/15 bg-white text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink' => $family !== '',
                        ])
                    >
                        <x-heroicon-o-squares-2x2 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('All') }}
                        <span @class([
                            'ms-1 rounded-full px-1.5 py-0.5 text-[10px] tabular-nums',
                            'bg-brand-cream/20 text-brand-cream' => $family === '',
                            'bg-brand-sand/60 text-brand-moss' => $family !== '',
                        ])>{{ $allTotal }}</span>
                    </button>
                    @foreach ($families as $f)
                        @php $count = $this->familyTotals[$f['id']] ?? 0; @endphp
                        <button
                            type="button"
                            wire:click="setFamily('{{ $f['id'] }}')"
                            @disabled($count === 0 && $family !== $f['id'])
                            @class([
                                'inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition shadow-sm',
                                'border-brand-ink bg-brand-ink text-brand-cream' => $family === $f['id'],
                                'border-brand-ink/15 bg-white text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink' => $family !== $f['id'] && $count > 0,
                                'border-brand-ink/10 bg-white text-brand-mist cursor-not-allowed opacity-60' => $count === 0 && $family !== $f['id'],
                            ])
                        >
                            <x-dynamic-component :component="$f['icon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ $f['label'] }}
                            <span @class([
                                'ms-1 rounded-full px-1.5 py-0.5 text-[10px] tabular-nums',
                                'bg-brand-cream/20 text-brand-cream' => $family === $f['id'],
                                'bg-brand-sand/60 text-brand-moss' => $family !== $f['id'],
                            ])>{{ $count }}</span>
                        </button>
                    @endforeach
                </nav>

                <div class="relative max-w-md">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search action or subject…') }}"
                        class="block w-full rounded-xl border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    />
                </div>
            </div>

            {{-- Timeline. Each row is a stretched flex with the icon tile,
                 a primary line (label + relative time), a secondary line
                 (user · subject), and an optional expanded diff panel. --}}
            <div class="mt-6 dply-card overflow-hidden">
                @if ($this->auditLogs->isEmpty())
                    <div class="px-6 py-16 text-center">
                        <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-o-inbox class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <p class="mt-4 text-sm font-medium text-brand-ink">
                            @if ($family !== '' || $search !== '')
                                {{ __('No activity matches the current filters.') }}
                            @else
                                {{ __('No activity yet.') }}
                            @endif
                        </p>
                        @if ($family !== '' || $search !== '')
                            <button type="button" wire:click="clearFilters" class="mt-3 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('Clear filters') }}
                            </button>
                        @endif
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($this->auditLogs as $log)
                            @php
                                $meta = \App\Support\AuditActionMeta::meta((string) $log->action);
                                $palette = $tonePalette[$meta['tone']] ?? $tonePalette['neutral'];
                                $expanded = in_array($log->id, $expandedIds, true);
                                $hasDiff = ! empty($log->old_values ?? []) || ! empty($log->new_values ?? []);
                            @endphp
                            <li wire:key="log-{{ $log->id }}" class="group">
                                <button
                                    type="button"
                                    @if ($hasDiff) wire:click="toggleRow({{ $log->id }})" @endif
                                    @disabled(! $hasDiff)
                                    class="flex w-full items-start gap-3 px-4 py-3.5 text-left transition-colors hover:bg-brand-sand/15 disabled:cursor-default sm:px-5"
                                >
                                    {{-- Icon tile + tone dot on the rail. --}}
                                    <span class="relative shrink-0">
                                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl ring-1 {{ $palette['tile'] }}">
                                            <x-dynamic-component :component="$meta['icon']" class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <span class="absolute -end-0.5 -bottom-0.5 inline-block h-2 w-2 rounded-full ring-2 ring-white {{ $palette['dot'] }}" aria-hidden="true"></span>
                                    </span>

                                    {{-- Primary + secondary lines. --}}
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                            <p class="text-sm font-semibold text-brand-ink">
                                                {{ $meta['label'] }}
                                            </p>
                                            <p class="shrink-0 text-[11px] text-brand-mist tabular-nums" title="{{ $log->created_at->toDayDateTimeString() }}">
                                                {{ $log->created_at->diffForHumans() }}
                                            </p>
                                        </div>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-brand-moss">
                                            @if ($log->user)
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-user-circle class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    {{ $log->user->name }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 text-brand-mist">
                                                    <x-heroicon-m-bolt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('System') }}
                                                </span>
                                            @endif
                                            @if ($log->subject_summary)
                                                <span class="text-brand-mist">·</span>
                                                <span class="truncate font-mono text-[11px] text-brand-ink/85">{{ $log->subject_summary }}</span>
                                            @endif
                                            <span class="text-brand-mist">·</span>
                                            <code class="font-mono text-[10.5px] text-brand-mist">{{ $log->action }}</code>
                                        </p>
                                    </div>

                                    @if ($hasDiff)
                                        <span class="shrink-0 self-center text-brand-mist transition group-hover:text-brand-moss">
                                            @if ($expanded)
                                                <x-heroicon-m-chevron-up class="h-4 w-4" aria-hidden="true" />
                                            @else
                                                <x-heroicon-m-chevron-down class="h-4 w-4" aria-hidden="true" />
                                            @endif
                                        </span>
                                    @endif
                                </button>

                                @if ($expanded && $hasDiff)
                                    <div class="border-t border-brand-ink/10 bg-brand-cream/40 px-4 py-3 sm:px-5">
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Before') }}</p>
                                                @if (! empty($log->old_values))
                                                    <pre class="mt-1 max-h-48 overflow-auto rounded-lg border border-brand-ink/10 bg-white p-3 font-mono text-[11px] leading-relaxed text-brand-ink">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                @else
                                                    <p class="mt-1 rounded-lg border border-dashed border-brand-ink/10 bg-white/60 p-3 text-[11px] text-brand-mist">{{ __('—') }}</p>
                                                @endif
                                            </div>
                                            <div>
                                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('After') }}</p>
                                                @if (! empty($log->new_values))
                                                    <pre class="mt-1 max-h-48 overflow-auto rounded-lg border border-brand-ink/10 bg-white p-3 font-mono text-[11px] leading-relaxed text-brand-ink">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                @else
                                                    <p class="mt-1 rounded-lg border border-dashed border-brand-ink/10 bg-white/60 p-3 text-[11px] text-brand-mist">{{ __('—') }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($this->auditLogs->count() >= 200)
                        <p class="border-t border-brand-ink/10 bg-brand-cream/30 px-5 py-3 text-center text-[11px] text-brand-mist">
                            {{ __('Showing the 200 most recent entries. Refine with filters above to see older activity.') }}
                        </p>
                    @endif
                @endif
            </div>
        </x-organization-shell>
    </div>
</div>
