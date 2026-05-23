<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Build & deploy activity') }}</h3>
        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Recent Edge deployments and build status for this site.') }}</p>
    </div>

    @if ($edgeDeployments->isEmpty())
        <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
            {{ __('No activity yet — trigger a deploy from Overview.') }}
        </div>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($edgeDeployments as $deployment)
                @php
                    $depBadge = match ($deployment->status) {
                        \App\Models\EdgeDeployment::STATUS_LIVE => 'text-emerald-700 dark:text-emerald-400',
                        \App\Models\EdgeDeployment::STATUS_FAILED => 'text-rose-700 dark:text-rose-400',
                        default => 'text-brand-moss',
                    };
                    $failureReason = $deployment->failure_reason;
                @endphp
                <li class="px-6 py-4 sm:px-8">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs text-brand-ink">{{ $deployment->id }}</p>
                            <p class="mt-1 text-sm capitalize {{ $depBadge }}">{{ str_replace('_', ' ', (string) $deployment->status) }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ $deployment->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') ?? '—' }}
                                @if ($deployment->git_commit)
                                    · <span class="font-mono">{{ \Illuminate\Support\Str::limit($deployment->git_commit, 8, '') }}</span>
                                @endif
                            </p>
                        </div>
                        @if ($deployment->status === \App\Models\EdgeDeployment::STATUS_FAILED && is_string($failureReason) && $failureReason !== '')
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">{{ __('Failed') }}</span>
                        @endif
                    </div>
                    @if (is_string($failureReason) && $failureReason !== '')
                        <pre class="mt-3 max-h-48 overflow-auto rounded-xl border border-rose-200/60 bg-rose-50/50 p-3 font-mono text-[11px] text-rose-900 dark:border-rose-900/30 dark:bg-rose-950/20 dark:text-rose-200">{{ $failureReason }}</pre>
                    @elseif ($deployment->build_log_path)
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Build log stored — full log streaming coming soon.') }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>

@include('livewire.sites.partials.edge.deploys-table', ['compact' => false])
