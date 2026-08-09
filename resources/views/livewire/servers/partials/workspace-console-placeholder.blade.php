{{--
    Lazy-load skeleton for Console. Mirrors the merged page (hide-hero +
    single console card with title/quick chips/dark terminal) so tab switches
    do not flash the split hero + generic cards pattern.
--}}
@php
    $promptUser = trim((string) ($server->ssh_user ?? '')) !== '' ? trim((string) $server->ssh_user) : 'root';
    $promptHost = $server->name ?: ($server->ip_address ?: 'server');
    $prompt = $promptUser.'@'.$promptHost;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="console"
    :title="__('Console')"
    hide-hero
>
    <div class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading console…') }}</span>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 rounded-t-2xl border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
            <h2 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                <x-heroicon-o-command-line class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                {{ __('Console') }}
            </h2>

            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>

            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1">
                @foreach (range(1, 7) as $chip)
                    <span
                        @class([
                            'inline-flex h-5 animate-pulse rounded-full bg-brand-ink/10',
                            'w-14' => $chip % 3 === 1,
                            'w-16' => $chip % 3 === 2,
                            'w-24' => $chip % 3 === 0,
                        ])
                    ></span>
                @endforeach
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <span class="inline-flex h-5 w-16 animate-pulse rounded-full bg-brand-ink/10"></span>
                <span class="inline-flex h-5 w-14 animate-pulse rounded-md bg-brand-ink/10"></span>
            </div>
        </div>

        <x-console-terminal-shell
            tone="dark"
            :prompt-user="$promptUser"
            :prompt-host="$promptHost"
            class="rounded-none border-0 shadow-none ring-0"
            max-height="520px"
        >
            <x-slot:toolbar>
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1.5" aria-hidden="true">
                    <div class="flex items-center gap-1.5">
                        <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#ff5f57]"></span>
                        <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#febc2e]"></span>
                        <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#28c840]"></span>
                    </div>
                    <span class="truncate font-mono text-xs font-medium text-slate-300">{{ $prompt }}</span>
                    <span class="inline-flex items-center gap-1.5 text-emerald-300/90">
                        <span class="relative flex h-1.5 w-1.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/50 opacity-75"></span>
                            <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                        </span>
                        {{ __('Connecting') }}
                    </span>
                </div>
            </x-slot:toolbar>

            <x-slot:body>
                <div class="space-y-3" aria-hidden="true">
                    <p class="flex items-center gap-2 text-slate-400">
                        <x-spinner variant="slate" size="sm" />
                        <span class="animate-pulse">{{ __('Opening console…') }}</span>
                    </p>
                    <div class="space-y-2 pt-1">
                        <div class="h-3 w-2/3 max-w-md animate-pulse rounded bg-white/10"></div>
                        <div class="h-3 w-1/2 max-w-sm animate-pulse rounded bg-white/10"></div>
                        <div class="h-3 w-3/5 max-w-lg animate-pulse rounded bg-white/10"></div>
                    </div>
                </div>
            </x-slot:body>

            <x-slot:footer>
                <div class="flex items-center gap-2" aria-hidden="true">
                    <span class="select-none font-mono text-sm text-emerald-400/90">$</span>
                    <div class="h-8 min-w-0 flex-1 animate-pulse rounded-md bg-white/5"></div>
                    <span class="inline-flex h-8 w-16 animate-pulse rounded-lg bg-emerald-500/30"></span>
                </div>
            </x-slot:footer>
        </x-console-terminal-shell>
    </div>
</x-server-workspace-layout>
