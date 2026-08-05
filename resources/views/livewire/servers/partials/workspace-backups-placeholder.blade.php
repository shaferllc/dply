{{--
    Lazy-load skeleton for Backups. Mirrors the merged page (hide-hero + single
    card with identity, glance stubs, and tabs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="backups"
    :title="__('Backups')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading backups…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-archive-box"
            :title="__('Backups')"
            :note="__('Recent database and site-files backup runs for this server, plus recurring schedules. Backups write to the destination configured in your account Settings → Backup configurations.')"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex items-start gap-3">
                <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (range(1, 4) as $tile)
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                        <div class="h-2.5 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="mt-2 h-6 w-10 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Overview'), __('Schedules'), __('History'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="h-9 w-56 animate-pulse rounded-xl bg-brand-ink/10"></div>
            <div class="mt-4 space-y-3">
                <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                <div class="h-2.5 w-72 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                <div class="h-24 w-full animate-pulse rounded-xl bg-brand-ink/10"></div>
            </div>
        </div>
    </section>
</x-server-workspace-layout>
