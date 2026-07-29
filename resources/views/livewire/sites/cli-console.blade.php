@php
    $apiHostLabel = parse_url($apiHost, PHP_URL_HOST) ?: $apiHost;
@endphp
<div
    wire:key="cli-console-{{ $site->id }}"
    class="space-y-4"
    x-data="{ scrollToBottom() { const el = $refs.scroll; if (el) el.scrollTop = el.scrollHeight; } }"
    x-on:cli-console-ran.window="$nextTick(() => scrollToBottom())"
    x-init="scrollToBottom()"
>
    <x-console-terminal-shell tone="dark" prompt-user="you" prompt-host="dply" class="min-h-[22rem]">
        <x-slot:toolbar>
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1.5">
                <div class="flex items-center gap-1.5" aria-hidden="true">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#ff5f57]"></span>
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#febc2e]"></span>
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#28c840]"></span>
                </div>

                <div class="flex min-w-0 items-center gap-2">
                    <span class="truncate font-mono text-[11px] font-medium text-slate-300">dply · {{ $site->slug }}</span>
                    @if ($isProductionMirror)
                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-400/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-200 ring-1 ring-inset ring-amber-300/25">
                            {{ __('Prod') }}
                        </span>
                    @endif
                </div>

                <div class="ms-auto flex items-center gap-2 text-[11px]">
                    @if ($cliReady)
                        <span class="inline-flex items-center gap-1.5 text-emerald-300/90">
                            <span class="relative flex h-1.5 w-1.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/50 opacity-75"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                            </span>
                            {{ __('Ready') }}
                        </span>
                    @else
                        <span class="font-medium text-rose-300">{{ __('CLI missing') }}</span>
                    @endif
                    <span class="hidden text-slate-500 sm:inline" aria-hidden="true">·</span>
                    <span class="hidden font-mono text-slate-400 sm:inline" title="{{ $apiHost }}">{{ $apiHostLabel }}</span>
                    @if (! empty($history))
                        <span class="hidden text-slate-500 sm:inline" aria-hidden="true">·</span>
                        <button type="button" wire:click="clearHistory" class="font-medium text-slate-400 transition hover:text-slate-200">
                            {{ __('Clear') }}
                        </button>
                    @endif
                </div>
            </div>
        </x-slot:toolbar>

        <x-slot:body>
            <div x-ref="scroll" class="min-h-[14rem] space-y-4">
                @if (empty($history))
                    <div class="space-y-2 text-slate-400">
                        <p>{{ __('Run a command against this site. Try one of the shortcuts below, or type:') }}</p>
                        <p class="font-mono text-slate-300">
                            <span class="text-emerald-400/90">$</span>
                            <span class="text-slate-500">dply</span>
                            site show --site {{ $site->id }}
                        </p>
                    </div>
                @endif

                @foreach ($history as $entry)
                    <div class="space-y-1.5">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <span class="select-none text-emerald-400/90">$</span>
                            <span class="select-none text-slate-500">dply</span>
                            <span class="break-all text-slate-100">{{ $entry['cmd'] }}</span>
                        </div>
                        @if ($entry['error'])
                            <pre class="whitespace-pre-wrap break-words text-rose-300/95">{{ $entry['error'] }}</pre>
                        @endif
                        @if ($entry['out'] !== '')
                            <pre class="whitespace-pre-wrap break-words text-slate-300">{{ $entry['out'] }}</pre>
                        @endif
                        @if (! is_null($entry['exit']) && $entry['exit'] !== 0)
                            <p class="text-[11px] text-amber-300/90">{{ __('exit :code', ['code' => $entry['exit']]) }}</p>
                        @endif
                    </div>
                @endforeach

                <div wire:loading wire:target="run" class="flex items-center gap-2 text-slate-400">
                    <span class="select-none text-emerald-400/90">$</span>
                    <x-spinner variant="slate" size="sm" />
                    <span class="animate-pulse">{{ __('running…') }}</span>
                </div>
            </div>
        </x-slot:body>

        <x-slot:footer>
            <form
                wire:submit="run"
                class="flex items-center gap-2.5"
            >
                <div class="flex min-w-0 flex-1 items-center gap-2 font-mono text-[12px]">
                    <span class="shrink-0 select-none text-emerald-400/90">$</span>
                    <span class="shrink-0 select-none text-slate-500">dply</span>
                    <input
                        type="text"
                        wire:model="input"
                        @disabled(! $cliReady)
                        placeholder="site show --site {{ $site->id }}"
                        autocomplete="off"
                        spellcheck="false"
                        class="min-w-0 flex-1 border-0 bg-transparent p-0 font-mono text-[12px] text-slate-100 caret-emerald-300 placeholder:text-slate-600 focus:outline-none focus:ring-0 disabled:cursor-not-allowed disabled:opacity-40"
                        x-on:cli-console-ran.window="$nextTick(() => $el.focus())"
                    />
                </div>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="run"
                    @disabled(! $cliReady)
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-emerald-400 px-3 py-1.5 text-[11px] font-semibold text-[#0b1020] shadow-sm transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <span wire:loading.remove wire:target="run">{{ __('Run') }}</span>
                    <span wire:loading wire:target="run" class="inline-flex items-center gap-1">
                        <x-spinner variant="ink" size="sm" />
                        {{ __('…') }}
                    </span>
                </button>
            </form>
            @if (! $cliReady)
                <p class="mt-2 font-mono text-[10px] leading-relaxed text-amber-300/90">
                    {{ __('Expected packages/dply-cli/bin/dply.mjs with Node 18+, or set DPLY_CLI_BINARY in .env.') }}
                </p>
            @endif
        </x-slot:footer>
    </x-console-terminal-shell>

    <div class="flex flex-wrap items-center gap-2">
        <span class="me-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Shortcuts') }}</span>
        @foreach ($presetCommands as $preset)
            <button
                type="button"
                wire:click="prefill(@js($preset['command']))"
                title="dply {{ $preset['command'] }}"
                class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/40 px-2.5 py-1 text-[11px] font-medium text-brand-ink transition hover:border-brand-ink/20 hover:bg-brand-sand"
            >
                {{ $preset['label'] }}
            </button>
        @endforeach
    </div>
</div>
