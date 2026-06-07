<div class="{{ $card }}">
    <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-clipboard-document-list class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Activity') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Scheduler actions on this server — enable, pause/resume, cadence changes, run-now, and stop-monitoring.') }}
            </p>
        </div>
    </div>

    @if ($auditLogs->isEmpty())
        <div class="px-6 py-10 text-center sm:px-7">
            <p class="text-sm text-brand-moss">{{ __('No scheduler activity recorded yet.') }}</p>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($auditLogs as $log)
                @php
                    $label = \Illuminate\Support\Str::of($log->action)
                        ->after('server.scheduler.')
                        ->replace('_', ' ')
                        ->title();
                @endphp
                <li class="px-6 py-4 sm:px-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-brand-ink">{{ $label }}</span>
                                @if ($log->user)
                                    <span class="text-[11px] text-brand-mist">{{ $log->user->name }}</span>
                                @endif
                            </div>
                            @if ($log->new_values)
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-[11px] font-medium text-brand-sage hover:underline">{{ __('Details') }}</summary>
                                    <pre class="mt-1.5 max-h-40 overflow-auto rounded-lg bg-zinc-950 px-3 py-2 font-mono text-[11px] leading-relaxed text-zinc-300">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </div>
                        <time
                            class="shrink-0 text-[11px] text-brand-mist"
                            datetime="{{ $log->created_at->toIso8601String() }}"
                            title="{{ $log->created_at->toDayDateTimeString() }}"
                        >{{ $log->created_at->diffForHumans() }}</time>
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($auditLogs->hasPages())
            <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                {{ $auditLogs->links() }}
            </div>
        @endif
    @endif
</div>
