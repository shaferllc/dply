<div
    wire:key="cli-console-{{ $site->id }}"
    x-data="{ scrollToBottom() { const el = $refs.scroll; if (el) el.scrollTop = el.scrollHeight; } }"
    x-on:cli-console-ran.window="$nextTick(() => scrollToBottom())"
    x-init="scrollToBottom()"
>
    <x-hero-card
        :eyebrow="__('Site')"
        :title="__('CLI Console')"
        :description="__('Run dply commands against this site straight from the browser, with output streamed back inline.')"
        icon="command-line"
    />

    <div class="mt-6"></div>

    <x-console-terminal-shell prompt-user="you" :prompt-host="'dply'">
        <x-slot:toolbar>
            <div class="flex items-center gap-1.5">
                <span class="inline-flex h-2 w-2 rounded-full bg-red-400/80" aria-hidden="true"></span>
                <span class="inline-flex h-2 w-2 rounded-full bg-amber-300/80" aria-hidden="true"></span>
                <span class="inline-flex h-2 w-2 rounded-full bg-brand-sage/80" aria-hidden="true"></span>
            </div>
            <span class="font-mono text-[11px] font-medium text-brand-forest">dply — {{ $site->slug }}</span>
            @if (! $cliBinary)
                <span class="inline-flex items-center gap-1 rounded-full border border-red-300/70 bg-red-50 px-2 py-0.5 text-[10px] font-semibold text-red-800">
                    {{ __('CLI not found') }}
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full border border-brand-sage/30 bg-brand-sage/10 px-2 py-0.5 text-[10px] font-semibold text-brand-forest">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-forest" aria-hidden="true"></span>
                    {{ __('Ready') }}
                </span>
            @endif
            <div class="ml-auto flex items-center gap-2 text-[11px]">
                @if (! empty($history))
                    <span class="text-brand-moss">{{ trans_choice('{1} :count entry|[2,*] :count entries', count($history), ['count' => count($history)]) }}</span>
                    <button type="button" wire:click="clearHistory" class="font-semibold text-brand-moss hover:text-brand-ink">{{ __('Clear') }}</button>
                @endif
            </div>
        </x-slot:toolbar>

        <x-slot:body>
            <div x-ref="scroll" class="space-y-3">
                @if (empty($history))
                    <p class="italic text-slate-400">{{ __('Type a dply command and press Enter — e.g.') }} <code class="rounded bg-slate-700 px-1 text-slate-200">sites:show {{ $site->slug }}</code></p>
                @endif

                @foreach ($history as $entry)
                    <div>
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <span class="text-brand-sage">~</span>
                            <span class="text-slate-500">$</span>
                            <span class="text-slate-300 font-semibold">dply</span>
                            <span class="break-all text-slate-100">{{ $entry['cmd'] }}</span>
                        </div>
                        @if ($entry['error'])
                            <pre class="mt-1 whitespace-pre-wrap break-words text-rose-300">{{ $entry['error'] }}</pre>
                        @endif
                        @if ($entry['out'] !== '')
                            <pre class="mt-1 whitespace-pre-wrap break-words text-slate-200">{{ $entry['out'] }}</pre>
                        @endif
                        @if (! is_null($entry['exit']) && $entry['exit'] !== 0)
                            <p class="mt-1 text-[11px] text-amber-300">{{ __('exit :code', ['code' => $entry['exit']]) }}</p>
                        @endif
                    </div>
                @endforeach

                <div wire:loading wire:target="run" class="text-slate-400">
                    <span class="text-brand-sage">~</span>
                    <span class="text-slate-500">$</span>
                    <span class="ml-1 inline-flex animate-pulse items-center gap-1.5">
                        <x-spinner variant="slate" size="sm" />
                        {{ __('running…') }}
                    </span>
                </div>
            </div>
        </x-slot:body>

        <x-slot:footer>
            <form
                wire:submit="run"
                class="flex items-center gap-2 border-t border-slate-700/60 bg-slate-900/80 px-3 py-2"
            >
                <div class="flex shrink-0 items-center gap-1 font-mono text-[11px]">
                    <span class="text-brand-sage">~</span>
                    <span class="text-slate-500">$</span>
                    <span class="font-semibold text-slate-300">dply</span>
                </div>
                <input
                    type="text"
                    wire:model="input"
                    @disabled(! $cliBinary)
                    placeholder="sites:show {{ $site->slug }}"
                    autocomplete="off"
                    spellcheck="false"
                    class="min-w-0 flex-1 bg-transparent font-mono text-[11px] text-slate-100 placeholder:text-slate-600 focus:outline-none disabled:cursor-not-allowed disabled:opacity-40"
                    x-on:cli-console-ran.window="$nextTick(() => $el.focus())"
                />
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="run"
                    @disabled(! $cliBinary)
                    class="inline-flex shrink-0 items-center gap-1 rounded-md bg-brand-sage/20 px-2 py-1 text-[11px] font-semibold text-brand-forest hover:bg-brand-sage/30 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <span wire:loading.remove wire:target="run">{{ __('Run') }}</span>
                    <span wire:loading wire:target="run">{{ __('…') }}</span>
                </button>
            </form>
            @if (! $cliBinary)
                <p class="border-t border-slate-700/40 bg-slate-900/60 px-3 py-2 font-mono text-[10px] text-amber-400">
                    {{ __('Set DPLY_CLI_BINARY in .env to point to the dply binary, or place dply-cli/ as a sibling of this repo.') }}
                </p>
            @endif
        </x-slot:footer>
    </x-console-terminal-shell>

    {{-- Quick-run buttons for common site commands --}}
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ([
            'sites:show '.$site->slug,
            'sites:deployments '.$site->slug,
            'sites:errors '.$site->slug,
            'sites:uptime '.$site->slug,
            'sites:workers '.$site->slug,
            'sites:schedules '.$site->slug,
            'sites:domains:list '.$site->slug,
            'sites:ssl:status '.$site->slug,
            'sites:commits '.$site->slug,
        ] as $preset)
            <button
                type="button"
                wire:click="prefill('{{ $preset }}')"
                class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[11px] text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ $preset }}
            </button>
        @endforeach
    </div>
</div>
