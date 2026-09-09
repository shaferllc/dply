{{--
    Lazy-load skeleton for Errors. Mirrors the merged page (hide-hero + single
    card with identity, tabs, filters, and stream rows).
--}}
<x-server-workspace-layout
    :server="$server"
    active="errors"
    :title="__('Errors')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading errors…') }}</span>

        {{-- Dense head, matching the merged page. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-exclamation-triangle"
            :title="__('Errors')"
            :note="__('Failures on this server and its sites — newest first. Dismiss what you’ve handled; retry where supported.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Stream'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-xs font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-4 py-3 sm:px-5" aria-hidden="true">
            <div class="flex flex-wrap gap-1.5">
                @foreach (range(1, 5) as $chip)
                    <span class="inline-flex h-7 w-16 animate-pulse rounded-full bg-brand-ink/10"></span>
                @endforeach
            </div>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 5) as $row)
                <li class="flex items-start gap-3 px-5 py-4">
                    <span class="mt-0.5 h-7 w-7 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-52 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-3/4 max-w-md animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                    <span class="h-7 w-14 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </li>
            @endforeach
        </ul>
    </section>
</x-server-workspace-layout>
