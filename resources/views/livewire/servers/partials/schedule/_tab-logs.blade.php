<div class="{{ $card }}">
    <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Logs') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cron daemon log') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Tail of the system cron daemon (journalctl -u cron, falling back to syslog). Confirms cron itself is invoking the scheduler entries — independent of what schedule:run prints.') }}
            </p>
        </div>
    </div>
    <div class="space-y-4 p-6 sm:p-7">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-brand-moss">{{ __('Loads the last 200 lines.') }}</p>
            <button
                type="button"
                wire:click="loadCronDaemonLog"
                wire:loading.attr="disabled"
                wire:target="loadCronDaemonLog"
                @disabled(! $opsReady)
                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="loadCronDaemonLog" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4" />
                    {{ __('Load cron log') }}
                </span>
                <span wire:loading wire:target="loadCronDaemonLog" class="inline-flex items-center gap-2">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Loading…') }}
                </span>
            </button>
        </div>
        <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">@if ($cron_daemon_log_body !== null){{ $cron_daemon_log_body }}@else{{ __('Click "Load cron log".') }}@endif</pre>
    </div>
</div>

{{-- Per-scheduler output history — analog to the daemons per-program log card. --}}
<div class="{{ $card }}">
    <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Output') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Scheduler output history') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Recent runs per scheduler. Failures are always recorded; successful-run output is kept only when capture is enabled. Run-now invocations appear tagged Manual.') }}
            </p>
        </div>
    </div>

    @if ($logSchedulers->isEmpty())
        <div class="px-6 py-10 text-center sm:px-7">
            <p class="text-sm text-brand-moss">{{ __('No schedulers yet. Enable one to start recording output.') }}</p>
        </div>
    @else
        <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/10 px-6 py-4 sm:flex-row sm:items-end sm:justify-between sm:px-7">
            <div class="min-w-0 flex-1">
                <x-input-label for="log_scheduler_id" value="{{ __('Scheduler') }}" />
                <select id="log_scheduler_id" wire:model.live="log_scheduler_id" class="{{ $input }} mt-1">
                    @foreach ($logSchedulers as $hb)
                        <option value="{{ $hb->id }}">{{ $hb->site?->name ?? $hb->site_id }} · {{ $hb->scheduler_kind }}</option>
                    @endforeach
                </select>
            </div>

            @if ($logSelectedHeartbeat)
                <button
                    type="button"
                    wire:click="toggleOutputCapture('{{ $logSelectedHeartbeat->id }}')"
                    wire:loading.attr="disabled"
                    wire:target="toggleOutputCapture"
                    @class([
                        'inline-flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold shadow-sm transition disabled:opacity-50',
                        'border-brand-forest bg-brand-sage/10 text-brand-forest hover:bg-brand-sage/20' => $logSelectedHeartbeat->output_capture_enabled,
                        'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $logSelectedHeartbeat->output_capture_enabled,
                    ])
                    title="{{ __('Capture successful-run stdout/stderr on the server. Off by default to avoid hoarding customer output.') }}"
                >
                    @if ($logSelectedHeartbeat->output_capture_enabled)
                        <x-heroicon-o-check-circle class="h-4 w-4" />
                        {{ __('Capture on') }}
                    @else
                        <x-heroicon-o-no-symbol class="h-4 w-4" />
                        {{ __('Capture off') }}
                    @endif
                </button>
            @endif
        </div>

        @if ($logTickOutputs->isEmpty())
            <div class="px-6 py-10 text-center sm:px-7">
                <p class="text-sm text-brand-moss">{{ __('No recorded runs for this scheduler yet.') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Failures record automatically; enable capture above to also keep successful output.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($logTickOutputs as $tick)
                    @php $failed = $tick->exit_code !== null && $tick->exit_code !== 0; @endphp
                    <li class="px-6 py-4 sm:px-7">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1',
                                    'bg-red-50 text-red-800 ring-red-200' => $failed,
                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => ! $failed,
                                ])>{{ $failed ? __('exit '.$tick->exit_code) : __('ok') }}</span>
                                @if ($tick->trigger === \App\Models\SchedulerTickOutput::TRIGGER_MANUAL)
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Manual') }}</span>
                                @endif
                                @if ($tick->duration_ms !== null)
                                    <span class="text-[11px] text-brand-mist">{{ $tick->duration_ms }} ms</span>
                                @endif
                            </div>
                            <time class="text-[11px] text-brand-mist" datetime="{{ optional($tick->ran_at)->toIso8601String() }}" title="{{ optional($tick->ran_at)->toDayDateTimeString() }}">{{ optional($tick->ran_at)->diffForHumans() ?? '—' }}</time>
                        </div>
                        @if ($tick->stderr_excerpt)
                            <pre class="mt-2 max-h-60 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-950 px-3 py-2 font-mono text-[11px] leading-relaxed text-rose-200">{{ $tick->stderr_excerpt }}</pre>
                        @endif
                        @if ($tick->stdout_excerpt)
                            <pre class="mt-2 max-h-60 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-950 px-3 py-2 font-mono text-[11px] leading-relaxed text-zinc-200">{{ $tick->stdout_excerpt }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
