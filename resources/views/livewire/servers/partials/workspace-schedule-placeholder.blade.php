{{--
    Lazy-load skeleton for Schedule. Mirrors the merged page (hide-hero + single
    card with identity, glance strip, and tabs), and shares the panel body with
    the tab-switch skeleton so the two can't drift apart.
--}}
@php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp

<x-server-workspace-layout
    :server="$server"
    active="schedule"
    :title="__('Schedule')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading schedule…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-calendar-days"
            :title="__('Schedule')"
            :note="__('Framework schedulers on this server — tick health per site; nudges when one stops firing.')"
            class="border-b border-brand-ink/10"
        />

        {{-- Geometry tracks the real card: dense head stub, then the four-cell
             stat strip. A placeholder taller than what replaces it just makes
             the page jump on resolve. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <dl class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4" aria-hidden="true">
            @foreach (range(0, 3) as $cell)
                <div @class([
                    'space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5',
                    'border-l' => $cell % 2 !== 0,
                    'sm:border-l' => $cell % 4 !== 0,
                    'sm:border-l-0' => $cell % 4 === 0,
                    'border-t' => $cell >= 2,
                    'sm:border-t-0' => true,
                ])>
                    <div class="h-2 w-16 {{ $bar }}"></div>
                    <div class="h-3 w-10 {{ $bar }}"></div>
                </div>
            @endforeach
        </dl>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Schedulers'), __('Overview'), __('Logs'), __('Activity')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-xs font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        @include('livewire.servers.partials.schedule._tab-skeleton', ['tab' => 'schedulers', 'rows' => 3])
    </section>
</x-server-workspace-layout>
