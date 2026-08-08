{{--
    Lazy-load skeleton for Runtime (PHP). Mirrors the merged page (hide-hero +
    single card with identity, a hairline stat strip, and version row stubs), so
    the geometry matches what replaces it instead of collapsing on resolve.
--}}
@php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp

<x-server-workspace-layout
    :server="$server"
    active="php"
    :title="__('Runtime')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading runtime…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-command-line"
            :title="__('Runtime')"
            :note="__('PHP inventory, CLI default, and new-site default for this server.')"
            class="border-b border-brand-ink/10"
        />

        {{-- Three-cell stat strip: installed versions, CLI default, new-site default. --}}
        <dl class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-3" aria-hidden="true">
            @foreach (range(0, 2) as $cell)
                <div @class([
                    'space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5',
                    'border-l' => $cell % 2 !== 0,
                    'sm:border-l' => $cell % 3 !== 0,
                    'sm:border-l-0' => $cell % 3 === 0,
                    'border-t' => $cell >= 2,
                    'sm:border-t-0' => true,
                ])>
                    <div class="h-2 w-24 {{ $bar }}"></div>
                    <div class="h-3 w-16 {{ $bar }}"></div>
                </div>
            @endforeach
        </dl>

        {{-- "Versions on this server" head stub. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-8 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 4) as $row)
                <li class="flex items-center gap-3 px-4 py-3 sm:px-5">
                    <span class="hidden h-9 w-9 shrink-0 rounded-xl {{ $bar }} sm:block"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="h-3 w-24 max-w-full {{ $bar }}"></span>
                            <span class="h-4 w-16 rounded-full {{ $bar }}"></span>
                        </div>
                        <div class="h-2.5 w-44 max-w-full {{ $bar }}"></div>
                    </div>
                    <span class="h-6 w-16 shrink-0 rounded-md {{ $bar }}"></span>
                    <span class="h-6 w-6 shrink-0 rounded-md {{ $bar }}"></span>
                </li>
            @endforeach
        </ul>
    </section>
</x-server-workspace-layout>
