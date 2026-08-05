{{--
    Lazy-load skeleton for Configuration. Mirrors the merged page (hide-hero +
    single card with identity, search, and editor panes).
--}}
<x-server-workspace-layout
    :server="$server"
    active="configuration"
    :title="__('Configuration')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading configuration…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-document-text"
            :title="__('Configuration')"
            :note="__('Load → edit → validate → review diff → save. Saves snapshot the live file, atomically install, re-validate, and auto-restore when validation rejects the new file.')"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6" aria-hidden="true">
            <div class="h-9 w-full max-w-md animate-pulse rounded-lg bg-brand-ink/10"></div>
        </div>

        <div class="grid gap-5 px-5 py-5 md:grid-cols-[280px_minmax(0,1fr)] sm:px-6" aria-hidden="true">
            <div class="space-y-2">
                @foreach (range(1, 6) as $row)
                    <div class="h-8 animate-pulse rounded-lg bg-brand-ink/10"></div>
                @endforeach
            </div>
            <div class="min-h-[20rem] animate-pulse rounded-xl bg-brand-ink/10"></div>
        </div>
    </section>
</x-server-workspace-layout>
