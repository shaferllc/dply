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
    class="flex h-full min-h-0 flex-col bg-[#0b1020]"
>
    {{-- Live output of a queued one-click action (e.g. "Install PHP Redis") that
         was streamed into the drawer. Polls while in-flight; stops when done. --}}
    @if ($watchedAction)
        @php
            $wa = $watchedAction;
            $waStale = $wa->isStale();
            $waBusy = $wa->isInFlight() && ! $waStale;
            $waStatus = $waStale ? 'failed' : $wa->status;
            $waLines = $wa->lines();
        @endphp
        <div class="shrink-0 border-b border-brand-ink/10 bg-brand-ink px-3 py-2.5" wire:key="drawer-watched-action-{{ $wa->id }}">
            @if ($waBusy)
                <div wire:poll.3s="" class="hidden" aria-hidden="true"></div>
            @endif
            <div class="flex items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2 text-emerald-100">
                    @if ($waBusy)
                        <x-spinner variant="forest" size="sm" />
                    @elseif ($waStatus === 'completed')
                        <x-heroicon-o-check-circle class="h-4 w-4 text-emerald-300" />
                    @else
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-rose-300" />
                    @endif
                    <span class="truncate text-xs font-semibold">{{ $wa->label ?? __('Running fix…') }}</span>
                </div>
                <button
                    type="button"
                    wire:click="clearWatchedAction"
                    class="shrink-0 rounded p-0.5 text-emerald-200/70 hover:bg-white/10 hover:text-emerald-100"
                    title="{{ __('Hide') }}"
                >
                    <x-heroicon-o-x-mark class="h-4 w-4" />
                </button>
            </div>
            <pre
                class="mt-2 max-h-44 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-black/40 p-2 font-mono text-xs leading-relaxed text-emerald-100"
                x-data
                x-init="$el.scrollTop = $el.scrollHeight"
                x-effect="$el.scrollTop = $el.scrollHeight"
            >@forelse ($waLines as $entry)@php
    $tone = match ($entry['level'] ?? null) {
        'step' => 'text-sky-300',
        'warn' => 'text-amber-300',
        'error' => 'text-rose-300',
        'success' => 'text-emerald-300',
        default => 'text-emerald-100',
    };
    $prefix = ($entry['source'] ?? null) !== null ? '['.$entry['source'].'] ' : '';
@endphp<span class="{{ $tone }}">{{ $prefix }}{{ $entry['line'] ?? '' }}</span>
@empty<span class="text-emerald-200/60">{{ $waBusy ? __('Queued — waiting for the worker to start…') : __('No output recorded.') }}</span>@endforelse</pre>
        </div>
    @endif

    @if (! $server)
        <div class="flex h-full min-h-0 flex-col px-3 pb-3 pt-1 sm:px-4 sm:pb-4">
            <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-white/10 bg-white/[0.03]">
                <div class="border-b border-white/10 px-4 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-100">{{ __('Pick a server') }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">{{ __('Ready servers with SSH keys in this organization.') }}</p>
                        </div>
                        <button
                            type="button"
                            wire:click="refreshAvailableServers"
                            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-white/10 bg-white/5 px-2 py-1.5 text-xs font-medium text-slate-300 transition hover:bg-white/10 hover:text-slate-100"
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
                        class="mt-3 w-full rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600 focus:border-emerald-400/40 focus:outline-none focus:ring-2 focus:ring-emerald-400/20"
                    />
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    @if ($serverLoading)
                        <div class="flex items-center justify-center gap-2 px-4 py-10 text-sm text-slate-400">
                            <x-spinner variant="slate" size="sm" />
                            {{ __('Loading servers…') }}
                        </div>
                    @elseif ($availableServers->isEmpty())
                        <p class="px-4 py-8 text-center text-sm text-slate-400">{{ __('No console-eligible servers in this organization yet.') }}</p>
                    @else
                        <ul class="divide-y divide-white/5">
                            @foreach ($availableServers as $s)
                                <li
                                    x-show="(@js((string) $s->name).toLowerCase() + ' ' + @js((string) $s->ip_address).toLowerCase()).includes(pickerSearch.trim().toLowerCase())"
                                >
                                    <button
                                        type="button"
                                        wire:click="selectServer('{{ $s->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="selectServer"
                                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-white/[0.04] disabled:opacity-50"
                                    >
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-slate-100">{{ $s->name }}</span>
                                            <span class="mt-0.5 block font-mono text-xs text-slate-500">{{ $s->ip_address }}</span>
                                        </span>
                                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-slate-600" aria-hidden="true" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="flex min-h-0 flex-1 flex-col">
            <x-console-terminal-shell
                tone="dark"
                :prompt-user="$promptUser"
                :prompt-host="$promptHost"
                class="min-h-0 flex-1 rounded-none border-0 shadow-none ring-0"
            >
                <x-slot:toolbar>
                    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1.5">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="truncate font-mono text-xs font-medium text-slate-300">{{ $prompt }}</span>
                            @if (! $serverReady)
                                <span class="inline-flex items-center gap-1 rounded-md bg-amber-400/15 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-200 ring-1 ring-inset ring-amber-300/25">
                                    {{ __('Unavailable') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 text-emerald-300/90">
                                    <span class="relative flex h-1.5 w-1.5">
                                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/50 opacity-75"></span>
                                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                    </span>
                                    {{ __('Connected') }}
                                </span>
                            @endif
                        </div>

                        <div class="ms-auto flex flex-wrap items-center gap-2 text-xs">
                            @if (! empty($history))
                                <button type="button" wire:click="clearHistory" class="font-medium text-slate-400 transition hover:text-slate-200">
                                    {{ __('Clear') }}
                                </button>
                                <span class="text-slate-600" aria-hidden="true">·</span>
                            @endif
                            <button type="button" wire:click="clearActiveServer" class="font-medium text-slate-400 transition hover:text-slate-200">
                                {{ __('Switch') }}
                            </button>
                            <span class="text-slate-600" aria-hidden="true">·</span>
                            <a href="{{ route('servers.console', $server) }}" wire:navigate class="font-medium text-slate-400 transition hover:text-slate-200">
                                {{ __('Full') }}
                            </a>
                        </div>
                    </div>
                </x-slot:toolbar>

                <x-slot:body>
                    <div x-ref="scroll" class="min-h-[10rem] space-y-4">
                        @if (! $serverReady && ! $error)
                            <div class="rounded-lg border border-amber-300/25 bg-amber-400/10 px-3 py-2">
                                <p class="text-xs leading-relaxed text-amber-100/90">
                                    {{ __('Server is not ready. Commands may fail while provisioning finishes or SSH reconnects.') }}
                                </p>
                            </div>
                        @endif

                        @if (empty($history) && $serverReady)
                            <div class="space-y-2 text-slate-400">
                                <p>{{ __('Type a command below and press Enter.') }}</p>
                                <p class="font-mono text-slate-300">
                                    <span class="text-emerald-400/90">$</span>
                                    <span class="text-slate-500">{{ $prompt }}</span>
                                    ls -la
                                </p>
                            </div>
                        @endif

                        @foreach ($history as $entry)
                            <div class="space-y-1.5">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="select-none text-emerald-400/90">$</span>
                                    <span class="select-none text-slate-500">{{ $prompt }}</span>
                                    <span class="break-all text-slate-100">{{ $entry['cmd'] }}</span>
                                </div>
                                @if ($entry['error'])
                                    <pre class="whitespace-pre-wrap break-words text-rose-300/95">{{ $entry['error'] }}</pre>
                                @else
                                    @if ($entry['out'] !== '')
                                        <pre class="whitespace-pre-wrap break-words text-slate-300">{{ $entry['out'] }}</pre>
                                    @endif
                                    @if (! is_null($entry['exit']) && $entry['exit'] !== 0)
                                        <p class="text-xs text-amber-300/90">{{ __('exit :code', ['code' => $entry['exit']]) }}</p>
                                    @endif
                                @endif
                            </div>
                        @endforeach

                        <div wire:loading wire:target="run" class="flex items-center gap-2 text-slate-400">
                            <span class="select-none text-emerald-400/90">$</span>
                            <x-spinner variant="slate" size="sm" />
                            <span class="animate-pulse">{{ __('running…') }}</span>
                        </div>

                        <div wire:loading wire:target="selectServer" class="inline-flex items-center gap-1.5 text-xs text-slate-400">
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
