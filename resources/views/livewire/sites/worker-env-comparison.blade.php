{{-- "Compare with workers" panel — main site .env vs each pool member's.
     Renders from the encrypted per-site cache (instant); the Read-live button
     queues an SSH read per box and the table refills as the jobs land. --}}
<section
    class="dply-card overflow-hidden"
    @if ($refreshing) wire:poll.4s @endif
>
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-gradient-to-br from-brand-sand/25 via-white to-brand-cream/30 px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-6">
        <div class="flex items-start gap-3">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest ring-1 ring-brand-forest/20">
                <x-heroicon-o-scale class="h-6 w-6" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Compare with workers') }}</h3>
                <p class="mt-0.5 text-sm text-brand-moss">
                    @if ($driftRowCount > 0)
                        {{ trans_choice('{1} :count key drifts from this site across the pool — workers should match.|[2,*] :count keys drift from this site across the pool — workers should match.', $driftRowCount, ['count' => $driftRowCount]) }}
                    @else
                        {{ __('All :total keys match across every pool member.', ['total' => $totalKeys]) }}
                    @endif
                </p>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="toggleReveal"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                <x-dynamic-component :component="$reveal ? 'heroicon-m-eye-slash' : 'heroicon-m-eye'" class="h-4 w-4" aria-hidden="true" />
                {{ $reveal ? __('Hide values') : __('Reveal values') }}
            </button>
            @if ($canRefresh)
                <button
                    type="button"
                    wire:click="refreshFromWorkers"
                    wire:loading.attr="disabled"
                    wire:target="refreshFromWorkers"
                    @disabled($refreshing)
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <x-heroicon-m-arrow-path class="h-4 w-4 {{ $refreshing ? 'animate-spin' : '' }}" aria-hidden="true" />
                    {{ $refreshing ? __('Reading live…') : __('Read live from workers') }}
                </button>
            @endif
        </div>
    </div>

    {{-- Column drift summary + only-differences toggle. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/5 px-5 py-3 sm:px-6">
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($columns as $col)
                @continue($col['is_main'])
                @php $d = $perColumnDrift[$col['id']] ?? 0; @endphp
                <span @class([
                    'inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium',
                    'border-amber-200 bg-amber-50 text-amber-800' => $d > 0,
                    'border-emerald-200 bg-emerald-50 text-emerald-700' => $d === 0,
                ])>
                    @if ($d > 0)
                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __(':name: :count off', ['name' => $col['label'], 'count' => $d]) }}
                    @else
                        <x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __(':name: in sync', ['name' => $col['label']]) }}
                    @endif
                </span>
            @endforeach
        </div>
        <button
            type="button"
            wire:click="toggleOnlyDrift"
            class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss transition hover:text-brand-ink"
        >
            <x-dynamic-component :component="$onlyDrift ? 'heroicon-m-funnel' : 'heroicon-m-list-bullet'" class="h-4 w-4" aria-hidden="true" />
            {{ $onlyDrift ? __('Showing differences only') : __('Showing all keys') }}
        </button>
    </div>

    @if ($rows === [])
        <div class="px-5 py-8 text-center sm:px-6">
            <x-heroicon-o-check-circle class="mx-auto h-8 w-8 text-emerald-500" aria-hidden="true" />
            <p class="mt-2 text-sm font-medium text-brand-ink">
                {{ $onlyDrift ? __('No drift — every worker matches this site.') : __('No environment variables to compare.') }}
            </p>
            @if ($onlyDrift && $totalKeys > 0)
                <button type="button" wire:click="toggleOnlyDrift" class="mt-1 text-xs font-semibold text-brand-forest hover:underline">
                    {{ __('Show all :total keys', ['total' => $totalKeys]) }}
                </button>
            @endif
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[36rem] border-collapse text-sm">
                <thead>
                    <tr class="border-b border-brand-ink/10 bg-brand-sand/20 text-left">
                        <th class="sticky left-0 z-10 bg-brand-sand/20 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Key') }}</th>
                        @foreach ($columns as $col)
                            <th class="px-4 py-2.5 align-bottom">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-semibold text-brand-ink">{{ $col['label'] }}</span>
                                    @if ($col['is_main'])
                                        <span class="rounded bg-brand-forest/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('This site') }}</span>
                                    @elseif ($col['role'])
                                        <span class="rounded bg-brand-ink/5 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-brand-moss">{{ $col['role'] }}</span>
                                    @endif
                                    @if ($col['syncing'])
                                        <span class="inline-flex items-center gap-1 text-[10px] font-medium text-brand-moss">
                                            <x-heroicon-m-arrow-path class="h-3 w-3 animate-spin" aria-hidden="true" />
                                            {{ __('reading') }}
                                        </span>
                                    @endif
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @foreach ($rows as $row)
                        <tr @class(['transition', 'bg-amber-50/30' => $row['drift']])>
                            <td class="sticky left-0 z-10 max-w-[14rem] truncate bg-white px-4 py-2 font-mono text-xs font-medium text-brand-ink" title="{{ $row['key'] }}">
                                {{ $row['key'] }}
                            </td>
                            @foreach ($row['cells'] as $cell)
                                <td class="px-4 py-2 align-top">
                                    @switch($cell['state'])
                                        @case('missing')
                                            <span class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-rose-200">
                                                <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" />{{ __('missing') }}
                                            </span>
                                            @break
                                        @case('main-absent')
                                            <span class="text-xs text-brand-mist">{{ __('not set') }}</span>
                                            @break
                                        @case('differ')
                                            <span class="block max-w-[16rem] truncate rounded-md bg-amber-50 px-2 py-0.5 font-mono text-xs text-amber-800 ring-1 ring-amber-200" title="{{ $reveal ? $cell['display'] : '' }}">{{ $cell['display'] }}</span>
                                            @break
                                        @case('extra')
                                            <span class="block max-w-[16rem] truncate rounded-md bg-sky-50 px-2 py-0.5 font-mono text-xs text-sky-800 ring-1 ring-sky-200" title="{{ __('Only on this worker — not on the main site') }}">{{ $cell['display'] }}</span>
                                            @break
                                        @case('match')
                                            <span class="block max-w-[16rem] truncate font-mono text-xs text-emerald-700" title="{{ $reveal ? $cell['display'] : '' }}">{{ $cell['display'] }}</span>
                                            @break
                                        @default
                                            {{-- main column present value --}}
                                            <span class="block max-w-[16rem] truncate font-mono text-xs text-brand-ink" title="{{ $reveal ? $cell['display'] : '' }}">{{ $cell['display'] }}</span>
                                    @endswitch
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="border-t border-brand-ink/5 px-5 py-2.5 text-[11px] text-brand-mist sm:px-6">
        {{ __('Compared from dply\'s encrypted cache. Use “Read live from workers” to re-read each box over SSH.') }}
    </div>
</section>
