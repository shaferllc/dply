{{-- Detail modal for one tick-history entry. Shared by the Schedule and
     Workers pages — both expose `$selectedTick` (the full row) plus the
     `closeTick` action. The history table truncates the body to 120 chars;
     this shows the whole captured preview (up to 1500 bytes). --}}
@if ($selectedTick)
    @php
        $tick = $selectedTick;
        $tickOk = ($tick['status'] ?? '') === 'ok';
        $tickBody = trim((string) ($tick['body_preview'] ?? ''));
        $tickError = trim((string) ($tick['error'] ?? ''));
    @endphp
    <div class="fixed inset-0 z-[100] flex items-center justify-center p-4"
         x-data
         x-on:keydown.escape.window="$wire.closeTick()">
        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeTick"></div>

        <div class="relative flex max-h-[85vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-xl">
            <header class="flex items-start justify-between gap-4 border-b border-brand-ink/10 p-5">
                <div class="min-w-0">
                    <h3 class="text-base font-bold text-brand-ink">{{ __('Tick detail') }}</h3>
                    @if (! empty($tick['at']))
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ \Illuminate\Support\Carbon::parse($tick['at'])->format('M j, Y g:i:s A') }}
                            <span class="text-brand-moss/60">· {{ \Illuminate\Support\Carbon::parse($tick['at'])->diffForHumans() }}</span>
                        </p>
                    @endif
                </div>
                <button type="button" wire:click="closeTick"
                        class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                        aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </header>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span @class([
                        'inline-flex items-center rounded-full px-2 py-0.5 font-semibold uppercase tracking-[0.12em]',
                        'bg-emerald-100 text-emerald-900' => $tickOk,
                        'bg-rose-100 text-rose-900' => ! $tickOk,
                    ])>{{ $tick['status'] ?? 'unknown' }}</span>
                    @if (! empty($tick['http_status']))
                        <span class="rounded-md bg-brand-sand/60 px-2 py-0.5 font-mono text-brand-moss">HTTP {{ $tick['http_status'] }}</span>
                    @endif
                    <span class="rounded-md bg-brand-sand/60 px-2 py-0.5 font-mono text-brand-moss">{{ (int) ($tick['duration_ms'] ?? 0) }}ms</span>
                    @if (! empty($tick['task']))
                        <span class="rounded-md bg-brand-sand/60 px-2 py-0.5 font-mono text-brand-moss">{{ $tick['task'] }}</span>
                    @endif
                </div>

                @if ($tickError !== '')
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Error') }}</p>
                        <pre class="mt-1.5 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-rose-200 bg-rose-50 p-3 font-mono text-[11px] leading-relaxed text-rose-900">{{ $tickError }}</pre>
                    </div>
                @endif

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Response body') }}</p>
                    @if ($tickBody !== '')
                        <pre class="mt-1.5 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-900 p-4 font-mono text-[11px] leading-relaxed text-slate-100">{{ $tickBody }}</pre>
                    @else
                        <p class="mt-1.5 text-xs text-brand-moss">{{ __('No response body captured.') }}</p>
                    @endif
                </div>
            </div>

            <footer class="flex justify-end border-t border-brand-ink/10 p-4">
                <button type="button" wire:click="closeTick"
                        class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                    {{ __('Close') }}
                </button>
            </footer>
        </div>
    </div>
@endif
