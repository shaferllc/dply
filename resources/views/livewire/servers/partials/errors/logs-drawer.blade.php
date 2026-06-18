{{--
    dply Logs correlation drawer — the host log slice surrounding an error
    (Tier-1: jump from the errors stream straight into the logs around it).
    State + data come from WorkspaceErrors::openLogsForError(); $errorLogsResult
    is {instant, from, to, logs[]} or null.
--}}
@if ($errorLogsOpen)
    <div class="fixed inset-0 z-40" role="dialog" aria-modal="true" wire:key="error-logs-drawer">
        <div class="absolute inset-0 bg-brand-ink/30" wire:click="closeLogsForError"></div>

        <div class="absolute inset-y-0 right-0 flex w-full max-w-3xl flex-col bg-white shadow-xl">
            <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 px-5 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Logs around this error') }}</h2>
                    <p class="mt-0.5 text-xs text-brand-mist">
                        @if ($errorLogsLabel)
                            {{ __('Error at :at', ['at' => $errorLogsLabel]) }} ·
                        @endif
                        @if ($errorLogsResult)
                            {{ __('window :from → :to (UTC)', ['from' => $errorLogsResult['from'], 'to' => $errorLogsResult['to']]) }}
                        @endif
                    </p>
                </div>
                <button type="button" wire:click="closeLogsForError" class="rounded-lg px-2 py-1 text-brand-mist hover:text-brand-ink" title="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                @php($logs = $errorLogsResult['logs'] ?? [])
                @if (empty($logs))
                    <x-empty-state
                        icon="heroicon-o-bars-3-bottom-left"
                        :title="__('No shipped logs in this window')"
                        :description="__('Nothing was shipped from this server around the time of the error — the agent may have been off, or the lines fell outside retention.')"
                    />
                @else
                    <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                        @foreach ($logs as $line)
                            <div class="flex items-start gap-3 border-b border-brand-ink/5 px-3 py-2 font-mono text-xs last:border-b-0">
                                <span class="shrink-0 tabular-nums text-brand-mist">{{ $line['timestamp'] ?? '' }}</span>
                                @if (! empty($line['source']))
                                    <span class="shrink-0 rounded bg-brand-sand/50 px-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ $line['source'] }}</span>
                                @endif
                                @if (! empty($line['level']))
                                    <span class="shrink-0 text-brand-mist">{{ $line['level'] }}</span>
                                @endif
                                <span class="whitespace-pre-wrap break-all text-brand-ink">{{ $line['message'] ?? '' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
