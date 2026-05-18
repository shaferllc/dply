@php
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $promptUser = $server->ssh_user ?: 'root';
    $promptHost = $server->name ?: $server->ip_address;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="console"
    :title="__('Console')"
    :description="__('Quick read-only SSH console for inspecting the server. For saved scripts or longer jobs use Run.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4" tone="warn">
        <p>{{ __('A lightweight shell prompt for poking at the server: type a command, hit Enter, output appears below. History is kept per session so you can scroll back through recent runs.') }}</p>
        <p>{{ __('Each command runs as the dply SSH user with full shell access — same blast radius as the Run page. Output is captured up to 16KB; for streaming/long-running jobs use Run.') }}</p>
    </x-explainer>

    @if ($opsReady)
        <div class="dply-card overflow-hidden p-0">
            {{-- Quick action chips --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                <span class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Quick') }}</span>
                @foreach ($quickActions as $i => $action)
                    <button
                        type="button"
                        wire:click="runQuickAction({{ $i }})"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                        title="{{ $action['cmd'] }}"
                    >
                        {{ $action['label'] }}
                    </button>
                @endforeach
                <div class="ml-auto flex items-center gap-2">
                    @if (! empty($history))
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('{1} :count entry|[2,*] :count entries', count($history), ['count' => count($history)]) }}</span>
                        <button
                            type="button"
                            wire:click="clearHistory"
                            class="text-xs font-medium text-brand-moss hover:text-brand-ink underline-offset-2 hover:underline"
                        >
                            {{ __('Clear') }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- Terminal-style scrollback --}}
            <div
                class="bg-[#0b1020] font-mono text-[12.5px] leading-relaxed text-slate-100"
                style="max-height: 520px; overflow-y: auto;"
                x-data="{}"
                x-init="$el.scrollTop = $el.scrollHeight"
                x-on:scroll-console-bottom.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
            >
                <div class="px-4 py-3 space-y-3">
                    @if (empty($history))
                        <p class="text-slate-400 italic">{{ __('Type a command below or pick a quick action above. History will appear here.') }}</p>
                    @endif

                    @foreach ($history as $entry)
                        <div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-emerald-400">{{ $promptUser.'@'.$promptHost }}</span><span class="text-slate-400">:~$</span>
                                <span class="text-slate-100 break-all">{{ $entry['cmd'] }}</span>
                            </div>
                            @if ($entry['error'])
                                <pre class="mt-1 whitespace-pre-wrap break-words text-rose-300">{{ $entry['error'] }}</pre>
                            @else
                                @if ($entry['out'] !== '')
                                    <pre class="mt-1 whitespace-pre-wrap break-words text-slate-200">{{ $entry['out'] }}</pre>
                                @endif
                                @if (! is_null($entry['exit']) && $entry['exit'] !== 0)
                                    <p class="mt-1 text-xs text-amber-300">{{ __('exit :code', ['code' => $entry['exit']]) }}</p>
                                @endif
                            @endif
                        </div>
                    @endforeach

                    <div wire:loading wire:target="run,runQuickAction" class="text-slate-400">
                        <span class="text-emerald-400">{{ $promptUser.'@'.$promptHost }}</span><span class="text-slate-400">:~$</span>
                        <span class="ml-1 animate-pulse">{{ __('running…') }}</span>
                    </div>
                </div>
            </div>

            {{-- Prompt --}}
            <form wire:submit.prevent="run" class="border-t border-brand-ink/10 bg-[#0b1020] px-4 py-3">
                @if ($error)
                    <p class="mb-2 text-xs text-rose-300">{{ $error }}</p>
                @endif
                <div class="flex items-center gap-2 font-mono text-[12.5px]">
                    <span class="shrink-0 text-emerald-400">{{ $promptUser.'@'.$promptHost }}</span><span class="text-slate-400">:~$</span>
                    <input
                        type="text"
                        wire:model="command"
                        autocomplete="off"
                        autocorrect="off"
                        spellcheck="false"
                        placeholder="{{ __('type a command and press Enter') }}"
                        class="flex-1 bg-transparent text-slate-100 placeholder-slate-500 caret-emerald-400 focus:outline-none"
                        wire:loading.attr="disabled"
                        wire:target="run,runQuickAction"
                    />
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="run,runQuickAction"
                        class="shrink-0 rounded-md bg-emerald-500/80 px-3 py-1 text-xs font-semibold text-slate-900 hover:bg-emerald-400 disabled:opacity-50"
                    >
                        {{ __('Run') }}
                    </button>
                </div>
                @error('command')
                    <p class="mt-1 text-xs text-rose-300">{{ $message }}</p>
                @enderror
            </form>
        </div>

        <p class="mt-3 text-xs text-brand-moss">
            {{ __('Need to save and re-run? Promote a command into a saved recipe on the') }}
            <a href="{{ route('servers.run', $server) }}" wire:navigate class="font-medium text-brand-ink underline-offset-2 hover:underline">{{ __('Run page') }}</a>.
        </p>
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use the console.') }}
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
