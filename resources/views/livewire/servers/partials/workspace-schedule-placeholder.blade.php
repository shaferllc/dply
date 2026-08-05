{{--
    Lazy-load skeleton for Schedule. Mirrors the merged page (hide-hero + single
    card with identity, glance stubs, and tabs).
--}}
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
            :note="__('Framework schedulers running on this server. Tracks tick health for each scheduler; nudges you when one stops firing.')"
            class="border-b border-brand-ink/10"
        />

        {{-- Geometry tracks the real card: compact panel head (4x4 icon, one
             title line, one note line) then two-line stat tiles at px-3 py-2.
             A placeholder that is taller than what replaces it just makes the
             page jump on resolve. --}}
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3.5 sm:px-6" aria-hidden="true">
            <div class="flex items-start gap-2">
                <span class="mt-0.5 h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-1.5">
                    <div class="h-3.5 w-44 max-w-full animate-pulse rounded bg-brand-ink/15"></div>
                    <div class="h-2.5 w-60 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-2 px-5 py-3 sm:grid-cols-4 sm:px-6" aria-hidden="true">
            @foreach (range(1, 4) as $tile)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                    <div class="h-2.5 w-16 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="mt-1 h-4 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            @endforeach
        </dl>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2" aria-hidden="true">
            @foreach ([__('Schedulers'), __('Overview'), __('Logs'), __('Activity')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3.5 sm:px-6">
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/15"></div>
                        <div class="h-2.5 w-64 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                <span class="h-7 w-24 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
            </div>
            <ul class="divide-y divide-brand-ink/10">
                @foreach (range(1, 3) as $row)
                    <li class="flex items-center gap-4 px-5 py-3 sm:px-6">
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-36 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-52 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                        <span class="h-7 w-20 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
</x-server-workspace-layout>
