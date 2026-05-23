            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                        {{ __('Recent daemon-related actions on this server (program changes, sync, restarts, copies).') }}
                    </p>
                </div>
                <div class="divide-y divide-brand-ink/10">
                    @forelse ($auditLogs as $log)
                        <div class="px-6 py-4 sm:px-8">
                            <p class="text-xs text-brand-mist">{{ $log->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}
                                @if ($log->user)
                                    · {{ $log->user->name }}
                                @endif
                            </p>
                            <p class="mt-1 font-mono text-sm text-brand-ink">{{ $log->action }}</p>
                            @if ($log->properties)
                                <pre class="mt-2 max-h-32 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-[11px] text-zinc-300">{{ json_encode($log->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif
                        </div>
                    @empty
                        <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">{{ __('No activity recorded yet.') }}</p>
                    @endforelse
                </div>
            </div>
