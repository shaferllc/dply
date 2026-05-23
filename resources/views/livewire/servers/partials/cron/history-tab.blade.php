<div class="{{ $card }}">
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/60 text-brand-ink ring-1 ring-brand-ink/10">
                <x-heroicon-o-clock class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent run history') }}</h2>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Recent manual and queued runs — retention :days days.', ['days' => config('cron_workspace.run_retention_days', 90)]) }}</p>
            </div>
        </div>
    </div>
    <div class="overflow-x-auto">
        @if ($recentCronRuns->isEmpty())
            <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">{{ __('No recorded runs yet. Use the per-job “Run now” to create history.') }}</p>
        @else
            <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                <thead class="bg-brand-sand/30 text-brand-moss">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ __('When') }}</th>
                        <th class="px-4 py-2 font-medium">{{ __('Job') }}</th>
                        <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                        <th class="px-4 py-2 font-medium">{{ __('Exit') }}</th>
                        <th class="px-4 py-2 font-medium">{{ __('Duration') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                    @foreach ($recentCronRuns as $run)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-[11px]">{{ $run->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                            <td class="max-w-xs truncate px-4 py-2">{{ $run->cronJob?->description ?: \Illuminate\Support\Str::limit($run->cronJob?->command ?? '—', 48) }}</td>
                            <td class="px-4 py-2">{{ $run->status }}</td>
                            <td class="px-4 py-2 font-mono">{{ $run->exit_code ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $run->duration_ms !== null ? $run->duration_ms.' ms' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
