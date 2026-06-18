@php
    /** @var \App\Models\ConsoleAction|null $run */
    /** @var array<string, array{running: string, completed: string, failed: string, stale: string}> $kindLabels */

    $busy = $run !== null && $run->isInFlight() && ! $run->isStale();
    // Keep polling for a short window past terminal state. The host page
    // (e.g. the webserver workspace) reads other state from the server model
    // — active webserver, installed-stack snapshot, switch history — and the
    // job updates that state in the same handle() that flips the
    // ConsoleAction to completed. Without this grace window the page can end
    // up stuck rendering pre-switch data because polling stopped on the very
    // tick that saw "completed", and the parent's render had already resolved
    // the model before the worker's UPDATE landed. A few extra ticks force
    // additional renders that pull fresh data and resolve the race.
    $justFinished = ! $busy
        && $run !== null
        && in_array($run->status, ['completed', 'failed'], true)
        && $run->finished_at !== null
        && $run->finished_at->gt(now()->subSeconds(12));
@endphp

<div wire:key="console-action-banner-static">
    @if ($busy)
        {{-- A wire:poll on a hidden element keeps the parent component re-rendering
             on a 4s cadence while the in-flight run is active, so the banner picks
             up running -> completed/failed transitions without a manual refresh.
             Polling stops automatically once the run goes terminal. --}}
        <div wire:poll.4s="" class="hidden" aria-hidden="true"></div>
    @elseif ($justFinished)
        {{-- Grace window: a couple of quick polls after terminal state give the
             host page time to pick up side-effects the worker wrote alongside
             the ConsoleAction transition (server meta updates, audit rows). --}}
        <div wire:poll.3s="" class="hidden" aria-hidden="true"></div>
    @endif

    @if ($run !== null)
        @php
            $kind = $kindLabels[$run->kind] ?? null;
            $subject = $run->subject;
            $host = (string) ($subject->name ?? 'host');
            $stale = $run->isStale();
            $queuedStalled = $run->isQueuedStalled();
            $effectiveStatus = $stale ? 'failed' : $run->status;

            // Prefer the per-dispatch label (set by the caller, e.g. "Removing
            // credential from :host …") over the generic kind copy ("Applying
            // webserver config to :host …"). Each status (running/completed/failed)
            // gets a derivation of the label, so a "Removing credential" run
            // shows "Removing credential" while busy and "Removed credential"
            // when finished.
            if (! empty($run->label)) {
                $message = match ($effectiveStatus) {
                    'completed' => preg_replace('/\s*…$/u', '', (string) $run->label).' — done.',
                    'failed' => $queuedStalled
                        ? preg_replace('/\s*…$/u', '', (string) $run->label).' — queue worker did not pick this up.'
                        : ($stale
                            ? preg_replace('/\s*…$/u', '', (string) $run->label).' — did not finish.'
                            : preg_replace('/\s*…$/u', '', (string) $run->label).' — failed.'),
                    default => (string) $run->label,
                };
            } else {
                $message = match ($effectiveStatus) {
                    'queued', 'running' => $kind['running'] ?? __('Working …'),
                    'completed' => $kind['completed'] ?? __('Done.'),
                    'failed' => $queuedStalled
                        ? \App\Models\ConsoleAction::queueWorkerStalledMessage()
                        : ($stale
                            ? ($kind['stale'] ?? __('Did not finish.'))
                            : ($kind['failed'] ?? __('Failed.'))),
                    default => $kind['running'] ?? __('Working …'),
                };
            }
            $message = str_replace(':host', $host, $message);

            $banner = match ($effectiveStatus) {
                'failed' => 'border-rose-200 bg-rose-50/80 text-rose-900',
                'completed' => 'border-emerald-200 bg-emerald-50/70 text-emerald-900',
                default => 'border-sky-200 bg-sky-50/80 text-sky-900',
            };

            $busyRow = $run->isInFlight() && ! $stale;
            $lines = $run->lines();
            // Only auto-expand the output drawer when there's something to show —
            // a failed run with zero captured lines (e.g. a queue worker that
            // never picked the job up) otherwise opens an empty "No output
            // recorded." box that's pure noise.
            $defaultExpanded = $busyRow || ($effectiveStatus === 'failed' && ! empty($lines));

            // Embedded mode: render flush (no own border / rounding / shadow /
            // margin) so the banner reads as a row inside a parent card such as
            // the Environment page's "Needs attention" group.
            $embedded = $embedded ?? false;

            // Long failure messages (e.g. cutover diagnostics dumping
            // `systemctl status` + journal + on-disk configs) make the banner
            // unreadable when collapsed to a single wrapped paragraph. Split a
            // short preview (first non-empty line) from the full text; when
            // there's more content past the preview, surface a modal trigger.
            $errorPreview = null;
            $errorIsLong = false;
            if (! $busyRow && $run->error) {
                $errorFull = (string) $run->error;
                $firstLine = strtok($errorFull, "\n");
                $errorPreview = $firstLine === false ? $errorFull : $firstLine;
                // Long when there's >1 line of content, or the single line is
                // itself longer than a banner can comfortably show.
                $errorIsLong = str_contains($errorFull, "\n") || mb_strlen($errorFull) > 240;
            }
            $errorModalName = 'console-action-error-'.$run->id;
            $outputModalName = 'console-action-output-'.$run->id;
            $hasLines = ! empty($lines);

            // Pretty-print any line whose entire content is a valid JSON
            // document (object or array). Tools like `caddy adapt`, `kubectl
            // get -o json`, `aws ... --output json` emit single-line minified
            // JSON that's unreadable in the banner; we re-emit it indented so
            // operators can actually scan the nesting. Anything that doesn't
            // look like JSON, or that is too large to re-format cheaply,
            // returns unchanged.
            $renderLine = function (string $line): string {
                $trimmed = trim($line);
                if ($trimmed === '' || strlen($trimmed) > 64_000) {
                    return $line;
                }
                $first = $trimmed[0];
                $last = $trimmed[strlen($trimmed) - 1];
                if (! (($first === '{' && $last === '}') || ($first === '[' && $last === ']'))) {
                    return $line;
                }
                $decoded = json_decode($trimmed, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    return $line;
                }
                $pretty = json_encode(
                    $decoded,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );

                return $pretty !== false ? $pretty : $line;
            };
        @endphp

        <div
            @class([
                'overflow-hidden text-sm w-full',
                $banner,
                'mb-2 rounded-xl border shadow-sm' => ! $embedded,
            ])
            role="status"
            aria-live="polite"
            wire:key="console-action-run-{{ $run->id }}"
            x-data="{ expanded: @js($defaultExpanded) }"
        >
            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/70 ring-1 ring-current/20">
                        @if ($busyRow)
                            <x-spinner variant="forest" />
                        @elseif ($effectiveStatus === 'completed')
                            <x-heroicon-o-check-circle class="h-4 w-4" />
                        @elseif ($effectiveStatus === 'failed')
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                        @else
                            <x-heroicon-o-information-circle class="h-4 w-4" />
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold leading-tight">{{ $message }}</p>
                        @if ($errorPreview !== null)
                            <p class="mt-0.5 truncate text-xs opacity-80" title="{{ $errorPreview }}">{{ $errorPreview }}</p>
                            @if ($errorIsLong)
                                <button
                                    type="button"
                                    x-on:click.stop="$dispatch('open-modal', @js($errorModalName))"
                                    class="mt-1 inline-flex items-center gap-1 text-xs font-medium underline-offset-2 hover:underline"
                                >
                                    <x-heroicon-o-document-text class="h-4 w-4" />
                                    {{ __('Show details') }}
                                </button>
                            @endif
                        @elseif ($queuedStalled)
                            <p class="mt-0.5 text-xs opacity-70">{{ \App\Models\ConsoleAction::queueWorkerStalledMessage() }}</p>
                        @elseif ($run->status === 'queued')
                            <p class="mt-0.5 text-xs opacity-70">{{ __('Queued — waiting for worker. Expand output below for live SSH steps.') }}</p>
                        @elseif ($run->started_at)
                            <p class="mt-0.5 text-xs opacity-70">{{ __('Started :time', ['time' => $run->started_at->diffForHumans()]) }}</p>
                        @endif
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                    @if (! $busyRow)
                        <button
                            type="button"
                            wire:click="dismissConsoleActionRun('{{ $run->id }}')"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-current/20 bg-white px-2.5 py-1.5 text-xs font-medium shadow-sm hover:bg-white/80"
                        >
                            <x-heroicon-o-x-mark class="h-4 w-4" />
                            {{ __('Dismiss') }}
                        </button>
                    @endif
                    @if ($hasLines)
                        {{-- Roomy-modal escape hatch — the inline drawer caps at
                             max-h-80 which is fine for a few hundred lines but
                             cramped for full diagnostic dumps. "Open" pops the
                             same content in a large modal. --}}
                        <button
                            type="button"
                            x-on:click.stop="$dispatch('open-modal', @js($outputModalName))"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-current/20 bg-white px-2.5 py-1.5 text-xs font-medium shadow-sm hover:bg-white/80"
                            title="{{ __('Open output in a modal') }}"
                        >
                            <x-heroicon-o-arrows-pointing-out class="h-4 w-4" />
                            {{ __('Open') }}
                        </button>
                    @endif
                    @if ($busyRow || $hasLines)
                        <button
                            type="button"
                            x-on:click="expanded = !expanded"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-current/20 bg-white px-2.5 py-1.5 text-xs font-medium shadow-sm hover:bg-white/80"
                            x-bind:aria-expanded="expanded.toString()"
                        >
                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5 transition-transform" x-bind:class="expanded ? 'rotate-180' : ''" />
                            <span x-text="expanded ? @js(__('Hide output')) : @js(__('View output'))"></span>
                        </button>
                    @endif
                </div>
            </div>

            <div x-show="expanded" x-cloak class="border-t border-current/15 bg-white/70 px-4 py-3">
                @if (empty($lines))
                    <p class="text-xs opacity-80">
                        {{ $busyRow
                            ? __('No output yet — the worker may still be picking up the job.')
                            : __('No output recorded.') }}
                    </p>
                @else
                    <div class="relative" x-data="{ copied: false, copy() { navigator.clipboard.writeText(this.$refs.out.innerText).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500); }); } }">
                        <button type="button" x-on:click="copy()" :title="copied ? '{{ __('Copied!') }}' : '{{ __('Copy output') }}'" :aria-label="copied ? '{{ __('Copied!') }}' : '{{ __('Copy output') }}'" class="absolute right-2 top-2 z-10 inline-flex items-center justify-center rounded-md bg-white/10 p-1.5 text-emerald-200/80 backdrop-blur transition hover:bg-white/20 hover:text-emerald-100 focus:outline-none focus:ring-1 focus:ring-emerald-300/60">
                            <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                            <svg x-show="copied" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4 text-emerald-300"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </button>
                        <pre x-ref="out" class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 pr-12 font-mono text-xs leading-relaxed text-emerald-100" x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">@foreach ($lines as $entry)@php
    $tone = match ($entry['level']) {
        'step' => 'text-sky-300',
        'warn' => 'text-amber-300',
        'error' => 'text-rose-300',
        'success' => 'text-emerald-300',
        default => 'text-emerald-100',
    };
    $prefix = $entry['source'] !== null ? '['.$entry['source'].'] ' : '';
@endphp<span class="{{ $tone }}">{{ $prefix }}{{ $renderLine((string) $entry['line']) }}</span>
@endforeach</pre>
                    </div>
                @endif
            </div>
        </div>

        @if ($errorIsLong)
            <x-modal
                :name="$errorModalName"
                :show="false"
                maxWidth="4xl"
                overlayClass="bg-brand-ink/40"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
            >
                <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-700">{{ __('Failure details') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ $message }}</h2>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div class="relative" x-data="{ copied: false, copy() { navigator.clipboard.writeText(this.$refs.out.innerText).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500); }); } }">
                        <button type="button" x-on:click="copy()" :title="copied ? '{{ __('Copied!') }}' : '{{ __('Copy output') }}'" :aria-label="copied ? '{{ __('Copied!') }}' : '{{ __('Copy output') }}'" class="absolute right-2 top-2 z-10 inline-flex items-center justify-center rounded-md bg-white/10 p-1.5 text-emerald-200/80 backdrop-blur transition hover:bg-white/20 hover:text-emerald-100 focus:outline-none focus:ring-1 focus:ring-emerald-300/60">
                            <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                            <svg x-show="copied" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4 text-emerald-300"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </button>
                        <pre x-ref="out" class="max-h-[60vh] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-brand-ink/95 p-4 pr-12 font-mono text-xs leading-relaxed text-emerald-100">{{ $run->error }}</pre>
                    </div>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                    <button
                        type="button"
                        x-on:click="$dispatch('close-modal', @js($errorModalName))"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-cream"
                    >
                        {{ __('Close') }}
                    </button>
                </div>
            </x-modal>
        @endif

        @if ($hasLines)
            {{-- Roomy output viewer. Same tone-coded rendering as the inline
                 drawer, but on a 5xl panel that fills most of the viewport so
                 long diagnostic dumps (journalctl + status + configs) are
                 readable without scrolling a 320 px slice. --}}
            <x-modal
                :name="$outputModalName"
                :show="false"
                maxWidth="5xl"
                overlayClass="bg-brand-ink/40"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(92vh,1080px)] flex-col"
            >
                <div class="shrink-0 flex items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Console output') }}</p>
                        <h2 class="mt-2 truncate text-xl font-semibold text-brand-ink">{{ $message }}</h2>
                        @if ($run->started_at)
                            <p class="mt-0.5 text-xs text-brand-mist">{{ __('Started :time', ['time' => $run->started_at->diffForHumans()]) }}{{ $run->finished_at ? ' — '.__('finished :time', ['time' => $run->finished_at->diffForHumans()]) : '' }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 rounded-full bg-brand-ink/10 px-2 py-0.5 font-mono text-[10px] text-brand-moss">{{ count($lines) }} {{ trans_choice('line|lines', count($lines)) }}</span>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div class="relative" x-data="{ copied: false, copy() { navigator.clipboard.writeText(this.$refs.out.innerText).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500); }); } }">
                        <button type="button" x-on:click="copy()" :title="copied ? '{{ __('Copied!') }}' : '{{ __('Copy output') }}'" :aria-label="copied ? '{{ __('Copied!') }}' : '{{ __('Copy output') }}'" class="absolute right-2 top-2 z-10 inline-flex items-center justify-center rounded-md bg-white/10 p-1.5 text-emerald-200/80 backdrop-blur transition hover:bg-white/20 hover:text-emerald-100 focus:outline-none focus:ring-1 focus:ring-emerald-300/60">
                            <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                            <svg x-show="copied" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4 text-emerald-300"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        </button>
                        <pre x-ref="out" class="overflow-auto whitespace-pre-wrap break-words rounded-lg bg-brand-ink/95 p-4 pr-12 font-mono text-xs leading-relaxed text-emerald-100" x-init="$el.scrollTop = $el.scrollHeight">@foreach ($lines as $entry)@php
    $tone = match ($entry['level']) {
        'step' => 'text-sky-300',
        'warn' => 'text-amber-300',
        'error' => 'text-rose-300',
        'success' => 'text-emerald-300',
        default => 'text-emerald-100',
    };
    $prefix = $entry['source'] !== null ? '['.$entry['source'].'] ' : '';
@endphp<span class="{{ $tone }}">{{ $prefix }}{{ $renderLine((string) $entry['line']) }}</span>
@endforeach</pre>
                    </div>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                    <button
                        type="button"
                        x-on:click="$dispatch('close-modal', @js($outputModalName))"
                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-cream"
                    >
                        {{ __('Close') }}
                    </button>
                </div>
            </x-modal>
        @endif
    @endif
</div>
