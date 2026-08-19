@php
    $done = (int) $completedCount;
    $total = (int) $totalCount;
@endphp

<div @if ($shouldPoll) wire:poll.4s @endif class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Provision path') }}</p>
        @if ($total > 0)
            <span class="text-xs font-medium text-brand-moss">{{ __(':done / :total steps', ['done' => $done, 'total' => $total]) }}</span>
        @endif
    </div>

    <ol class="overflow-hidden rounded-lg border border-brand-ink/10">
        @foreach ($steps as $step)
            <li
                wire:key="worker-path-{{ $server->id }}-{{ $step['key'] }}"
                @class([
                    'flex gap-3 border-b border-brand-ink/10 px-3 py-2.5 last:border-b-0',
                    'bg-rose-50' => $step['state'] === 'failed',
                    'bg-brand-sand/30' => $step['state'] === 'active',
                    'bg-white' => ! in_array($step['state'], ['failed', 'active'], true),
                ])
            >
                <span class="mt-0.5 shrink-0" aria-hidden="true">
                    @if ($step['state'] === 'completed')
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200">
                            <x-heroicon-s-check class="h-3 w-3" />
                        </span>
                    @elseif ($step['state'] === 'failed')
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-rose-100 text-rose-800 ring-1 ring-rose-200">
                            <x-heroicon-s-x-mark class="h-3 w-3" />
                        </span>
                    @elseif ($step['state'] === 'active')
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-sky-100 text-sky-800 ring-1 ring-sky-200">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-sky-500"></span>
                        </span>
                    @else
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-brand-sand/70 text-brand-mist ring-1 ring-brand-ink/10">
                            <span class="h-1.5 w-1.5 rounded-full bg-brand-mist"></span>
                        </span>
                    @endif
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                        <p @class([
                            'text-sm font-medium',
                            'text-rose-900' => $step['state'] === 'failed',
                            'text-brand-ink' => $step['state'] !== 'failed',
                        ])>{{ $step['label'] }}</p>
                        @if ($step['duration'])
                            <span class="text-xs text-brand-moss">{{ $step['duration'] }}</span>
                        @endif
                    </div>
                    @if ($step['detail'] && in_array($step['state'], ['active', 'failed'], true))
                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $step['detail'] }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    @if ($failedStep && filled($failedStep['output']))
        <pre class="max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-rose-100">{{ $failedStep['output'] }}</pre>
    @elseif ($liveTaskOutput)
        <div>
            <p class="text-xs font-medium text-brand-moss">
                {{ __('Setup output') }}
                @if ($liveTaskOutputLineCount > 0)
                    <span class="font-normal">{{ __(':count lines', ['count' => $liveTaskOutputLineCount]) }}</span>
                @endif
            </p>
            <pre class="mt-1.5 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $liveTaskOutput }}</pre>
        </div>
    @elseif ($activeStep && filled($activeStep['output']))
        <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $activeStep['output'] }}</pre>
    @endif

    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Site release') }}</p>
        @if ($deploy)
            <div class="mt-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                <p class="text-sm font-medium text-brand-ink">
                    {{ __('Status') }}
                    <span class="font-normal text-brand-moss">{{ $deploy->status }}</span>
                    @if ($deploy->git_sha)
                        <span class="font-mono text-xs text-brand-mist">{{ \Illuminate\Support\Str::limit((string) $deploy->git_sha, 7, '') }}</span>
                    @endif
                </p>
                @if (filled($deploy->log_output))
                    <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $deploy->log_output }}</pre>
                @else
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Waiting for deploy output…') }}</p>
                @endif
            </div>
        @else
            <p class="mt-1.5 text-sm text-brand-moss">{{ $server->isProvisioningComplete()
                ? __('No deploy recorded on this worker yet.')
                : __('This site’s release deploys after the worker VM finishes installing.') }}</p>
        @endif
    </div>
</div>
