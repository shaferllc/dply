{{--
    Lazy-load skeleton for Databases. Mirrors the merged page (hide-hero + single
    card with the dense identity head, tabs, and dense row stubs) so the skeleton
    is the same height as what replaces it — no header collapse on boot.
--}}
<x-server-workspace-layout
    :server="$server"
    active="databases"
    :title="__('Databases')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading databases…') }}</span>

        <x-workspace-panel-head
            dense
            icon="heroicon-o-circle-stack"
            :title="__('Databases')"
            :note="__('Create databases on this server, then reveal credentials and copy connection details for your apps.')"
            class="border-b border-brand-ink/10"
            aria-hidden="true"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Basics'), __('MySQL'), __('PostgreSQL'), __('Advanced'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-4 py-2.5 sm:px-5" aria-hidden="true">
            <div class="h-2.5 w-72 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 3) as $row)
                <li class="flex items-center gap-3 py-3 pl-5 pr-3 sm:pl-6 sm:pr-4">
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                    <div class="flex shrink-0 gap-1.5">
                        @foreach (range(1, 3) as $btn)
                            <span class="h-7 w-16 animate-pulse rounded-md bg-brand-ink/10"></span>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
</x-server-workspace-layout>
