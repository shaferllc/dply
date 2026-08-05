{{--
    Lazy-load skeleton for Logs. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and viewer stubs) — same compact head and tab
    metrics as the real page so nothing jumps when it swaps in.
--}}
<x-server-workspace-layout
    :server="$server"
    active="logs"
    :title="__('Logs')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading logs…') }}</span>

        <x-workspace-panel-head
            icon="heroicon-o-document-text"
            :title="__('Logs')"
            :note="__('Dply activity and system log tailing for this server — live SSH reads.')"
            class="border-b border-brand-ink/10"
            aria-hidden="true"
        />

        <div class="flex flex-wrap gap-1 border-b border-brand-ink/10 px-4 py-2" aria-hidden="true">
            @foreach ([__('Viewer'), __('Overview'), __('Sources'), __('dply Logs'), __('Alerts'), __('Activity'), __('Related')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-[11px] font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-4 py-2.5 sm:px-5" aria-hidden="true">
            <div class="space-y-2">
                <div class="h-8 w-full max-w-xl animate-pulse rounded-lg bg-brand-ink/10"></div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (range(1, 4) as $chip)
                        <span class="h-8 w-20 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    @endforeach
                </div>
                <div class="h-64 w-full animate-pulse rounded-xl bg-brand-ink/10"></div>
            </div>
        </div>
    </section>
</x-server-workspace-layout>
