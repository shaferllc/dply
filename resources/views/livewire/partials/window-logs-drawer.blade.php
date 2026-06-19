{{--
    dply Logs correlation drawer — the host log slice across an event WINDOW
    (Tier-1: jump from a deploy / uptime incident straight into the logs around
    it). State + data come from the CorrelatesWindowLogs concern; $windowLogsResult
    is {from, to, logs[]} or null. $server (the owning server) must be in scope.
--}}
@if ($windowLogsOpen)
    <div class="fixed inset-0 z-40" role="dialog" aria-modal="true" wire:key="window-logs-drawer">
        <div class="absolute inset-0 bg-brand-ink/30" wire:click="closeWindowLogs"></div>

        <div class="absolute inset-y-0 right-0 flex w-full max-w-3xl flex-col bg-white shadow-xl">
            <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 px-5 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-brand-ink">{{ $windowLogsTitle ?? __('Logs around this event') }}</h2>
                    <p class="mt-0.5 text-xs text-brand-mist">
                        @if ($windowLogsResult)
                            {{ __('window :from → :to (UTC)', ['from' => $windowLogsResult['from'], 'to' => $windowLogsResult['to']]) }}
                        @endif
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if ($windowLogsResult)
                        <a href="{{ route('servers.logs', ['server' => $server, 'tab' => 'shipping', 'from' => $windowLogsResult['from'], 'to' => $windowLogsResult['to']]) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-forest hover:bg-brand-sand/40">
                            {{ __('Open in dply Logs') }} <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                        </a>
                    @endif
                    <button type="button" wire:click="closeWindowLogs" class="rounded-lg px-2 py-1 text-brand-mist hover:text-brand-ink" title="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                @php($logs = $windowLogsResult['logs'] ?? [])
                @if (empty($logs))
                    <x-empty-state
                        icon="heroicon-o-bars-3-bottom-left"
                        :title="__('No shipped logs in this window')"
                        :description="__('Nothing was shipped from this server during this window — the agent may have been off, or the lines fell outside retention.')"
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
