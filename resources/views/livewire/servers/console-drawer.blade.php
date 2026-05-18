@php
    $promptUser = $server?->ssh_user ?: 'root';
    $promptHost = $server?->name ?: ($server?->ip_address ?: '—');
@endphp

<div
    x-data="{ pickerSearch: '' }"
    x-on:dply-console-drawer-opened.window="$nextTick(() => { $refs.scroll && ($refs.scroll.scrollTop = $refs.scroll.scrollHeight); $refs.prompt && $refs.prompt.focus(); })"
    class="flex h-full flex-col"
>
    @if (! $server)
        {{-- Picker: no server in context yet. List the org's ready+ssh-keyed
             servers and let the operator choose. Search is purely client-side
             — server count caps at 100 in the component so DOM stays small. --}}
        <div class="flex h-full flex-col bg-[#0b1020] text-slate-100">
            <div class="border-b border-slate-800 bg-slate-900/60 px-3 py-2">
                <p class="text-xs font-semibold text-slate-100">{{ __('Pick a server to console into') }}</p>
                <p class="mt-0.5 text-[11px] text-slate-400">{{ __('Only servers that are ready and have an SSH key are listed.') }}</p>
                <input
                    type="text"
                    x-model="pickerSearch"
                    placeholder="{{ __('Search by name or IP…') }}"
                    class="mt-2 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-100 placeholder-slate-500 focus:border-emerald-500/60 focus:outline-none"
                />
            </div>
            <div class="flex-1 overflow-y-auto">
                @if ($availableServers->isEmpty())
                    <p class="px-3 py-4 text-xs italic text-slate-400">{{ __('No console-eligible servers in this organization yet.') }}</p>
                @else
                    <ul class="divide-y divide-slate-800">
                        @foreach ($availableServers as $s)
                            <li
                                x-show="(@js((string) $s->name).toLowerCase() + ' ' + @js((string) $s->ip_address).toLowerCase()).includes(pickerSearch.trim().toLowerCase())"
                            >
                                <button
                                    type="button"
                                    wire:click="selectServer('{{ $s->id }}')"
                                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-800/60"
                                >
                                    <span class="font-medium text-slate-100">{{ $s->name }}</span>
                                    <span class="font-mono text-[11px] text-slate-400">{{ $s->ip_address }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @else
        {{-- Active console view. --}}
        <div class="flex items-center gap-2 border-b border-slate-800 bg-[#0b1020] px-3 py-1.5 text-[11px] text-slate-300">
            <span class="font-mono text-emerald-400">{{ $promptUser.'@'.$promptHost }}</span>
            <span class="ml-auto flex items-center gap-3">
                @if (! empty($history))
                    <span class="text-slate-500">{{ count($history) }} {{ Str::plural('entry', count($history)) }}</span>
                    <button type="button" wire:click="clearHistory" class="text-slate-400 hover:text-slate-100 underline-offset-2 hover:underline">{{ __('Clear') }}</button>
                @endif
                <button type="button" wire:click="clearActiveServer" class="text-slate-400 hover:text-slate-100 underline-offset-2 hover:underline" title="{{ __('Pick a different server') }}">
                    {{ __('Switch') }}
                </button>
                <a href="{{ route('servers.console', $server) }}" wire:navigate class="text-slate-400 hover:text-slate-100 underline-offset-2 hover:underline" title="{{ __('Open full Console page') }}">
                    {{ __('Open full') }}
                </a>
            </span>
        </div>

        <div
            x-ref="scroll"
            class="flex-1 overflow-y-auto bg-[#0b1020] font-mono text-[12px] leading-relaxed text-slate-100"
        >
            <div class="px-3 py-2 space-y-2">
                @if (empty($history))
                    <p class="text-slate-400 italic">{{ __('Type a command below and press Enter.') }}</p>
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
                                <p class="mt-1 text-[11px] text-amber-300">{{ __('exit :code', ['code' => $entry['exit']]) }}</p>
                            @endif
                        @endif
                    </div>
                @endforeach
                <div wire:loading wire:target="run" class="text-slate-400">
                    <span class="text-emerald-400">{{ $promptUser.'@'.$promptHost }}</span><span class="text-slate-400">:~$</span>
                    <span class="ml-1 animate-pulse">{{ __('running…') }}</span>
                </div>
            </div>
        </div>

        <form wire:submit.prevent="run" class="border-t border-slate-800 bg-[#0b1020] px-3 py-2">
            @if ($error)
                <p class="mb-1 text-[11px] text-rose-300">{{ $error }}</p>
            @endif
            <div class="flex items-center gap-2 font-mono text-[12px]">
                <span class="shrink-0 text-emerald-400">{{ $promptUser.'@'.$promptHost }}</span><span class="text-slate-400">:~$</span>
                <input
                    type="text"
                    wire:model="command"
                    x-ref="prompt"
                    autocomplete="off"
                    autocorrect="off"
                    spellcheck="false"
                    placeholder="{{ __('type a command and press Enter') }}"
                    class="flex-1 bg-transparent text-slate-100 placeholder-slate-500 caret-emerald-400 focus:outline-none"
                    wire:loading.attr="disabled"
                    wire:target="run"
                />
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="run"
                    class="shrink-0 rounded-md bg-emerald-500/80 px-2.5 py-1 text-[11px] font-semibold text-slate-900 hover:bg-emerald-400 disabled:opacity-50"
                >
                    {{ __('Run') }}
                </button>
            </div>
            @error('command')
                <p class="mt-1 text-[11px] text-rose-300">{{ $message }}</p>
            @enderror
        </form>
    @endif
</div>
