<div class="{{ $card }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-clock"
        :title="__('Recent run history')"
        :count="$recentCronRuns->isEmpty() ? null : trans_choice('{1} :count run|[2,*] :count runs', $recentCronRuns->count(), ['count' => $recentCronRuns->count()])"
        :note="__('Recent manual and queued runs — retention :days days.', ['days' => config('cron_workspace.run_retention_days', 90)])"
        class="border-b border-brand-ink/10"
    />
    <div class="overflow-x-auto">
        @if ($recentCronRuns->isEmpty())
            <div class="flex flex-col items-center justify-center px-6 py-14 text-center sm:px-8">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-clock class="h-6 w-6" aria-hidden="true" />
                </span>
                <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No runs recorded yet') }}</p>
                <p class="mx-auto mt-1 max-w-sm text-xs leading-relaxed text-brand-moss">
                    {{ __('When a cron job runs manually via “Run now” or on its schedule, its output and exit code show up here.') }}
                </p>
            </div>
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
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">{{ $run->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
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
    @if (! $recentCronRuns->isEmpty() && $recentCronRuns->hasPages())
        <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-8">
            {{ $recentCronRuns->links() }}
        </div>
    @endif
</div>
