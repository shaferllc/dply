@php
    $promptUser = $server?->ssh_user ?: 'root';
    $promptHost = $server?->name ?: ($server?->ip_address ?: '—');
    $prompt = $promptUser.'@'.$promptHost;
@endphp

<div
    x-data="{ pickerSearch: '' }"
    x-on:dply-console-drawer-opened.window="$nextTick(() => { $refs.scroll && ($refs.scroll.scrollTop = $refs.scroll.scrollHeight); $refs.prompt && $refs.prompt.focus(); })"
    {{-- The drawer lives in the persistent layout, so on wire:navigate it can
         survive the page swap with last page's command output. Reset it on
         navigation so each page starts with a clean console (guarded to only
         round-trip when there's actually output to clear). --}}
    x-on:livewire:navigated.window="$wire.history?.length && $wire.clearHistory()"
    class="flex h-full min-h-0 flex-col bg-gradient-to-b from-brand-cream/80 to-white"
>
    @if (! $server)
        <div class="flex h-full min-h-0 flex-col p-3 sm:p-4">
            <div class="dply-card flex min-h-0 flex-1 flex-col overflow-hidden p-0">
                <div class="border-b border-brand-ink/10 bg-brand-cream/50 px-4 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                    <x-heroicon-o-command-line class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div>
                                    <p class="text-sm font-semibold text-brand-ink">{{ __('Pick a server') }}</p>
                                    <p class="text-[11px] text-brand-moss">{{ __('Ready servers with SSH keys in this organization.') }}</p>
                                </div>
                            </div>
                        </div>
                        <button
                            type="button"
                            wire:click="refreshAvailableServers"
                            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-[11px] font-medium text-brand-moss shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
                            title="{{ __('Refresh server list') }}"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                            {{ __('Refresh') }}
                        </button>
                    </div>
                    <input
                        type="text"
                        x-model="pickerSearch"
                        placeholder="{{ __('Search by name or IP…') }}"
                        class="mt-3 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    />
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    @if ($serverLoading)
                        <div class="flex items-center justify-center gap-2 px-4 py-10 text-sm text-brand-moss">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Loading servers…') }}
                        </div>
                    @elseif ($availableServers->isEmpty())
                        <p class="px-4 py-8 text-center text-sm text-brand-moss">{{ __('No console-eligible servers in this organization yet.') }}</p>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($availableServers as $s)
                                <li
                                    x-show="(@js((string) $s->name).toLowerCase() + ' ' + @js((string) $s->ip_address).toLowerCase()).includes(pickerSearch.trim().toLowerCase())"
                                >
                                    <button
                                        type="button"
                                        wire:click="selectServer('{{ $s->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="selectServer"
                                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-brand-sand/30 disabled:opacity-50"
                                    >
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-brand-ink">{{ $s->name }}</span>
                                            <span class="mt-0.5 block font-mono text-[11px] text-brand-moss">{{ $s->ip_address }}</span>
                                        </span>
                                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="flex min-h-0 flex-1 flex-col p-3 sm:p-4">
            <x-console-terminal-shell :prompt-user="$promptUser" :prompt-host="$promptHost" class="min-h-0 flex-1">
                <x-slot:toolbar>
                    <div class="flex items-center gap-1.5">
                        <span class="inline-flex h-2 w-2 rounded-full bg-red-400/80" aria-hidden="true"></span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-amber-300/80" aria-hidden="true"></span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-brand-sage/80" aria-hidden="true"></span>
                    </div>
                    <span class="font-mono text-[11px] font-medium text-brand-forest">{{ $prompt }}</span>
                    @if (! $serverReady)
                        <span class="inline-flex items-center gap-1 rounded-full border border-amber-300/70 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-900">
                            {{ __('Unavailable') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full border border-brand-sage/30 bg-brand-sage/10 px-2 py-0.5 text-[10px] font-semibold text-brand-forest">
                            <span class="h-1.5 w-1.5 rounded-full bg-brand-forest" aria-hidden="true"></span>
                            {{ __('Connected') }}
                        </span>
                    @endif
                    <div class="ml-auto flex flex-wrap items-center gap-2 text-[11px]">
                        @if (! empty($history))
                            <span class="text-brand-moss">{{ trans_choice('{1} :count entry|[2,*] :count entries', count($history), ['count' => count($history)]) }}</span>
                            <button type="button" wire:click="clearHistory" class="font-semibold text-brand-moss hover:text-brand-ink">{{ __('Clear') }}</button>
                        @endif
                        <button type="button" wire:click="clearActiveServer" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-arrows-right-left class="h-3 w-3" aria-hidden="true" />
                            {{ __('Switch') }}
                        </button>
                        <a href="{{ route('servers.console', $server) }}" wire:navigate class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                            {{ __('Open full') }}
                        </a>
                    </div>
                </x-slot:toolbar>

                <x-slot:body>
                    <div x-ref="scroll" class="space-y-3">
                        @if (! $serverReady && ! $error)
                            <div class="rounded-lg border border-amber-300/50 bg-amber-500/10 px-3 py-2">
                                <p class="text-[11px] leading-relaxed text-amber-100">
                                    {{ __('Server is not ready. Commands may fail while provisioning finishes or SSH reconnects.') }}
                                </p>
                            </div>
                        @endif

                        @if (empty($history) && $serverReady)
                            <p class="text-slate-400 italic">{{ __('Type a command below and press Enter.') }}</p>
                        @endif

                        @foreach ($history as $entry)
                            <div>
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="text-brand-sage">{{ $prompt }}</span><span class="text-slate-500">:~$</span>
                                    <span class="break-all text-slate-100">{{ $entry['cmd'] }}</span>
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
                            <span class="text-brand-sage">{{ $prompt }}</span><span class="text-slate-500">:~$</span>
                            <span class="ml-1 inline-flex items-center gap-1.5 animate-pulse">
                                <x-spinner variant="slate" size="sm" />
                                {{ __('running…') }}
                            </span>
                        </div>

                        <div wire:loading wire:target="selectServer" class="inline-flex items-center gap-1.5 text-[11px] text-slate-400">
                            <x-spinner variant="slate" size="sm" />
                            {{ __('Connecting to server…') }}
                        </div>
                    </div>
                </x-slot:body>

                <x-slot:footer>
                    @include('livewire.servers.partials.console-prompt-form', [
                        'promptUser' => $promptUser,
                        'promptHost' => $promptHost,
                        'serverReady' => $serverReady,
                        'error' => $error,
                        'showRetry' => ! $serverReady,
                        'compact' => true,
                    ])
                </x-slot:footer>
            </x-console-terminal-shell>
        </div>
    @endif
</div>
