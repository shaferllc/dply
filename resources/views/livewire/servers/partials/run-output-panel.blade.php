{{-- Live output panel for queued server command runs.

     A run is dispatched to a queued worker (RunServerCommandJob) that
     flushes stdout/stderr to the ServerCommandRun row incrementally. While
     $isRunning is true the hidden wire:poll pulls the latest output every
     second; it stops itself the moment the row settles. --}}
@if ($activeRunId !== null || $command_output !== null || $command_error !== null)
    @php
        $status = $activeRunStatus ?? ($isRunning ? 'queued' : null);
        $badge = match ($status) {
            'completed' => ['label' => __('Completed'), 'class' => 'bg-emerald-100 text-emerald-800'],
            'failed' => ['label' => __('Failed'), 'class' => 'bg-red-100 text-red-800'],
            'running' => ['label' => __('Running'), 'class' => 'bg-amber-100 text-amber-800'],
            'queued' => ['label' => __('Queued'), 'class' => 'bg-brand-sand text-brand-moss'],
            default => null,
        };
    @endphp

    @if ($isRunning)
        {{-- Self-terminating poll: removed from the DOM as soon as the run settles. --}}
        <div wire:poll.1s="pollActiveRun" class="hidden" aria-hidden="true"></div>
    @endif

    <div class="dply-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3">
            <div class="flex items-center gap-2 text-sm font-medium text-brand-ink">
                @if ($isRunning)
                    <svg class="h-4 w-4 animate-spin text-brand-sage" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z" />
                    </svg>
                @endif
                <span>{{ $activeRunLabel ?? __('Command output') }}</span>
            </div>
            <div class="flex items-center gap-2">
                @if ($badge)
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $badge['class'] }}">
                        {{ $badge['label'] }}
                    </span>
                @endif
                @if ($activeRunExitCode !== null)
                    <span class="font-mono text-[11px] text-brand-moss">{{ __('exit') }} {{ $activeRunExitCode }}</span>
                @endif
            </div>
        </div>

        @if ($isRunning && ($command_output === null || $command_output === '') && ($command_error === null || $command_error === ''))
            <div class="px-5 py-4 text-sm text-brand-moss">{{ __('Waiting for output…') }}</div>
        @endif

        @if ($command_output !== null && $command_output !== '')
            <pre class="max-h-96 overflow-auto bg-brand-ink p-4 text-xs leading-relaxed text-emerald-400/95">{{ $command_output }}</pre>
        @endif

        @if ($command_error !== null && $command_error !== '')
            <pre class="max-h-96 overflow-auto border-t border-white/10 bg-brand-ink p-4 text-xs leading-relaxed text-red-400/95">{{ $command_error }}</pre>
        @endif
    </div>
@endif
