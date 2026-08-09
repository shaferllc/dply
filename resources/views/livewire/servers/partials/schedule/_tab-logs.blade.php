<div class="{{ $card }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-document-text"
        :title="__('Cron daemon log')"
        :note="__('Tail of the system cron daemon (journalctl -u cron, falling back to syslog). Confirms cron itself is invoking scheduler entries. Loads the last 200 lines.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="loadCronDaemonLog"
                wire:loading.attr="disabled"
                wire:target="loadCronDaemonLog"
                @disabled(! $opsReady)
                class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="loadCronDaemonLog" class="inline-flex items-center gap-1">
                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Load cron log') }}
                </span>
                <span wire:loading wire:target="loadCronDaemonLog" class="inline-flex items-center gap-1">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Loading…') }}
                </span>
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>
    <div class="px-3 py-2.5 sm:px-4">
        <pre class="max-h-[min(55vh,28rem)] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-950 px-3 py-2.5 font-mono text-[11px] leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">@if ($cron_daemon_log_body !== null){{ $cron_daemon_log_body }}@else{{ __('Click "Load cron log".') }}@endif</pre>
    </div>
</div>

{{-- Per-scheduler output history — analog to the daemons per-program log card. --}}
<div class="{{ $card }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-command-line"
        :title="__('Scheduler output history')"
        :note="__('Recent runs per scheduler. Failures are always recorded; successful-run output is kept only when capture is enabled. Run-now invocations appear tagged Manual.')"
        class="border-b border-brand-ink/10"
    />

    @if ($logSchedulers->isEmpty())
        <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-2 px-3 py-5 text-center sm:px-4">
            <p class="text-xs text-brand-moss">{{ __('No schedulers yet. Enable one to start recording output.') }}</p>
        </div>
    @else
        <div class="flex flex-col gap-2 border-b border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5 sm:flex-row sm:items-end sm:justify-between sm:px-4">
            <div class="min-w-0 flex-1">
                <x-input-label for="log_scheduler_id" value="{{ __('Scheduler') }}" class="!text-[11px]" />
                <select id="log_scheduler_id" wire:model.live="log_scheduler_id" class="{{ $input }} mt-1 !py-1.5 !text-xs">
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
                        'inline-flex h-7 shrink-0 items-center gap-1 rounded-md border px-2 text-[11px] font-semibold shadow-sm transition disabled:opacity-50',
                        'border-brand-forest bg-brand-sage/10 text-brand-forest hover:bg-brand-sage/20' => $logSelectedHeartbeat->output_capture_enabled,
                        'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $logSelectedHeartbeat->output_capture_enabled,
                    ])
                    title="{{ __('Capture successful-run stdout/stderr on the server. Off by default to avoid hoarding customer output.') }}"
                >
                    @if ($logSelectedHeartbeat->output_capture_enabled)
                        <x-heroicon-o-check-circle class="h-3.5 w-3.5" />
                        {{ __('Capture on') }}
                    @else
                        <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
                        {{ __('Capture off') }}
                    @endif
                </button>
            @endif
        </div>

        @if ($logTickOutputs->isEmpty())
            <div class="px-3 py-5 text-center sm:px-4">
                <p class="text-xs text-brand-moss">{{ __('No recorded runs for this scheduler yet.') }}</p>
                <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Failures record automatically; enable capture above to also keep successful output.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($logTickOutputs as $tick)
                    @php $failed = $tick->exit_code !== null && $tick->exit_code !== 0; @endphp
                    <li class="px-3 py-2.5 sm:px-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span @class([
                                    'inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1',
                                    'bg-red-50 text-red-800 ring-red-200' => $failed,
                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => ! $failed,
                                ])>{{ $failed ? __('exit '.$tick->exit_code) : __('ok') }}</span>
                                @if ($tick->trigger === \App\Models\SchedulerTickOutput::TRIGGER_MANUAL)
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Manual') }}</span>
                                @endif
                                @if ($tick->duration_ms !== null)
                                    <span class="text-[11px] text-brand-mist">{{ $tick->duration_ms }} ms</span>
                                @endif
                            </div>
                            <time class="text-[11px] text-brand-mist" datetime="{{ optional($tick->ran_at)->toIso8601String() }}" title="{{ optional($tick->ran_at)->toDayDateTimeString() }}">{{ optional($tick->ran_at)->diffForHumans() ?? '—' }}</time>
                        </div>
                        @if ($tick->stderr_excerpt)
                            <pre class="mt-1.5 max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-950 px-2.5 py-2 font-mono text-[11px] leading-relaxed text-rose-200">{{ $tick->stderr_excerpt }}</pre>
                        @endif
                        @if ($tick->stdout_excerpt)
                            <pre class="mt-1.5 max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-950 px-2.5 py-2 font-mono text-[11px] leading-relaxed text-zinc-200">{{ $tick->stdout_excerpt }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
