{{--
    Lazy-load skeleton for Access graph. Mirrors the merged page (hide-hero +
    single card with identity and section stubs).

    Section stubs track x-workspace-panel-head dense — icon, title, optional
    count pill, hairline divider, note, actions — so the card doesn't resize
    when the real render swaps in.
--}}
@php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp

<x-server-workspace-layout
    :server="$server"
    active="ssh-access"
    :title="__('Access graph')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading access graph…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-finger-print"
            :title="__('Access graph')"
            :note="__('Who had SSH access on this server over time — your keys, temporary sessions, and when Dply accessed the server to run jobs.')"
            class="border-b border-brand-ink/10"
        />

        {{-- Access map: dense head, column labels, three node columns. --}}
        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                <span class="h-3.5 w-24 shrink-0 {{ $bar }}"></span>
                <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            </div>
            <div class="px-4 py-4 sm:px-5">
                <div class="mb-2 grid grid-cols-3 gap-2">
                    @foreach (range(1, 3) as $col)
                        <div class="h-2 w-20 justify-self-center {{ $bar }}"></div>
                    @endforeach
                </div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (range(1, 3) as $col)
                        <div class="space-y-2.5">
                            @foreach (range(1, 3) as $row)
                                {{-- Node cards are a fixed 58px in the real map. --}}
                                <div class="h-[58px] rounded-xl {{ $bar }}"></div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Timeline: dense head with the three range pills, then chart + lanes. --}}
        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                <span class="h-3.5 w-32 shrink-0 {{ $bar }}"></span>
                <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                @foreach (range(1, 3) as $pill)
                    <span class="h-6 w-14 shrink-0 rounded-full {{ $bar }}"></span>
                @endforeach
            </div>
            <div class="space-y-3.5 px-4 py-3.5 sm:px-5">
                <div class="flex flex-wrap items-center gap-3">
                    @foreach (range(1, 3) as $legend)
                        <span class="h-2 w-24 {{ $bar }}"></span>
                    @endforeach
                </div>
                <div class="h-32 w-full rounded-lg {{ $bar }}"></div>
                <div class="space-y-1.5">
                    <div class="h-2 w-24 {{ $bar }}"></div>
                    <div class="space-y-1">
                        @foreach (range(1, 4) as $lane)
                            <div class="grid grid-cols-[minmax(0,8rem)_1fr] items-center gap-2.5">
                                <div class="h-2.5 w-24 {{ $bar }}"></div>
                                <div class="h-4 rounded {{ $bar }}"></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Authorized keys: dense head with count pill and two actions, then the
             key table. --}}
        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
                <span class="h-4 w-8 shrink-0 rounded-full {{ $bar }}"></span>
                <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                <span class="h-6 w-24 shrink-0 rounded-md {{ $bar }}"></span>
                <span class="h-6 w-20 shrink-0 rounded-md {{ $bar }}"></span>
            </div>
            <div class="divide-y divide-brand-ink/5">
                <div class="flex items-center gap-3 bg-brand-sand/30 px-3 py-2 sm:px-5">
                    @foreach (range(1, 5) as $th)
                        <span class="h-2 min-w-0 flex-1 {{ $bar }}"></span>
                    @endforeach
                </div>
                @foreach (range(1, 3) as $keyRow)
                    <div class="flex items-center gap-3 px-3 py-2 sm:px-5">
                        @foreach (range(1, 5) as $td)
                            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</x-server-workspace-layout>
