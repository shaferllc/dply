@props(['rows', 'columns', 'entityLabel', 'scoreLabel' => null, 'note' => null])

{{--
    The coverage grid shared by the Backups Overview, Databases and Files tabs.

    Every tab answers the same question about a different artifact — is this
    thing protected, and against what — so they render through one grid rather
    than three tables that drift apart. Callers supply rows of the shape
    ['key', 'title', 'subtitle', 'url', 'covered', 'applicable', 'cells'] where
    each cell is ['state', 'label', 'note'].

    States are filled when the property is in place and hollow when it is not,
    so the grid reads before any label does. `na` means the property cannot
    apply to this row — a Redis box has no database to dump — and those cells
    are excluded from the score rather than counted as gaps.
--}}
@php
    $states = [
        'scheduled' => ['swatch' => 'bg-brand-sage ring-1 ring-brand-forest/40', 'text' => 'text-brand-forest', 'label' => __('in place')],
        'manual' => ['swatch' => 'bg-brand-gold ring-1 ring-brand-copper/50', 'text' => 'text-brand-copper', 'label' => __('by hand, not repeating')],
        'failed' => ['swatch' => 'bg-brand-rust ring-1 ring-brand-rust', 'text' => 'text-brand-rust', 'label' => __('last attempt failed')],
        'missing' => ['swatch' => 'ring-2 ring-brand-rust/70', 'text' => 'text-brand-rust', 'label' => __('not in place')],
        'na' => ['swatch' => 'bg-brand-ink/[0.03] ring-1 ring-brand-ink/12', 'text' => 'text-brand-mist', 'label' => __('nothing to back up here')],
    ];

    // Only legend the states actually on screen — a key for a colour nobody can
    // see is noise, and "last attempt failed" on a page with no failures reads
    // like a warning.
    $used = collect($rows)
        ->flatMap(fn (array $row) => collect($row['cells'])->pluck('state'))
        ->unique();

    $applicableTotal = collect($rows)->sum('applicable');
    $coveredTotal = collect($rows)->sum('covered');
@endphp

<section {{ $attributes->merge(['class' => 'border-b border-brand-ink/10']) }}>
    <x-workspace-panel-head
        dense
        tone="amber"
        class="border-b border-brand-ink/10"
        icon="heroicon-o-shield-exclamation"
        :title="__('Coverage')"
        :note="$note ?? __(':covered of :total checks passing.', ['covered' => $coveredTotal, 'total' => $applicableTotal])"
    />

    <div class="overflow-x-auto">
        <table class="w-full min-w-[46rem] border-collapse text-left">
            <thead>
                <tr class="border-b border-brand-ink/10">
                    <th scope="col" class="w-1/4 px-3 py-2 text-2xs font-medium uppercase tracking-[0.08em] text-brand-mist sm:px-4">{{ $entityLabel }}</th>
                    @foreach ($columns as $label)
                        <th scope="col" class="px-3 py-2 text-2xs font-medium uppercase tracking-[0.08em] text-brand-mist">{{ $label }}</th>
                    @endforeach
                    <th scope="col" class="px-3 py-2 text-right text-2xs font-medium uppercase tracking-[0.08em] text-brand-mist sm:px-4">{{ $scoreLabel ?? __('Passing') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/8">
                @foreach ($rows as $row)
                    <tr wire:key="coverage-{{ $row['key'] }}" class="group transition-colors hover:bg-brand-sand/20">
                        <td class="px-3 py-2.5 sm:px-4">
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" wire:navigate class="text-sm font-semibold text-brand-ink hover:text-brand-forest hover:underline">{{ $row['title'] }}</a>
                            @else
                                <span class="text-sm font-semibold text-brand-ink">{{ $row['title'] }}</span>
                            @endif
                            <span class="mt-0.5 block truncate font-mono text-2xs text-brand-mist">{{ $row['subtitle'] }}</span>
                        </td>

                        @foreach ($columns as $key => $label)
                            @php $cell = $row['cells'][$key]; $state = $states[$cell['state']]; @endphp
                            <td class="px-3 py-2.5">
                                <span class="inline-flex items-center gap-2">
                                    <span class="h-3.5 w-3.5 shrink-0 rounded ring-inset {{ $state['swatch'] }}" aria-hidden="true"></span>
                                    <span class="truncate font-mono text-2xs {{ $state['text'] }}" @if ($cell['note']) title="{{ $cell['note'] }}" @endif>{{ $cell['label'] }}</span>
                                </span>
                            </td>
                        @endforeach

                        <td class="px-3 py-2.5 text-right sm:px-4">
                            <span class="font-mono text-xs tabular-nums text-brand-moss">
                                {{ $row['applicable'] === 0 ? '—' : $row['covered'].' / '.$row['applicable'] }}
                            </span>
                            @if ($row['url'] && $row['applicable'] > $row['covered'])
                                <a
                                    href="{{ $row['url'] }}"
                                    wire:navigate
                                    class="ml-2 inline-flex items-center gap-1 rounded-md bg-brand-ink px-2 py-1 text-xs font-semibold text-brand-cream opacity-70 transition-all hover:bg-brand-forest group-hover:opacity-100"
                                >
                                    {{ __('Fix') }}
                                    <x-heroicon-m-arrow-right class="h-3 w-3" aria-hidden="true" />
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5 sm:px-4">
        @foreach ($states as $state => $legend)
            @continue (! $used->contains($state))
            <span class="inline-flex items-center gap-2 text-2xs text-brand-moss">
                <span class="h-3 w-3 shrink-0 rounded ring-inset {{ $legend['swatch'] }}" aria-hidden="true"></span>
                {{ $legend['label'] }}
            </span>
        @endforeach
    </div>
</section>
