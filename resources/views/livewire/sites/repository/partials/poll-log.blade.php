@php
    /** @var list<array{at?: string, sha?: string|null, sha_short?: string|null, outcome?: string, message?: string}> $pollLog */
    $pollLog = is_array($pollLog ?? null) ? $pollLog : [];
    $showPollLog = ($isPoll ?? false) || $pollLog !== [];
    $outcomeStyles = [
        'unchanged' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
        'deploy_queued' => 'bg-emerald-50 text-emerald-800 ring-emerald-200/70',
        'skipped_in_progress' => 'bg-amber-50 text-amber-900 ring-amber-200/70',
        'error' => 'bg-rose-50 text-rose-900 ring-rose-200/70',
    ];
    $outcomeLabels = [
        'unchanged' => __('Unchanged'),
        'deploy_queued' => __('Deploy queued'),
        'skipped_in_progress' => __('Skipped'),
        'error' => __('Error'),
    ];
@endphp
@if ($showPollLog)
    <div class="rounded-lg border border-brand-ink/10 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-3 py-2">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Poll log') }}</p>
            @if ($isPoll ?? false)
                <button
                    type="button"
                    wire:click="checkQuickDeployPollNow"
                    wire:loading.attr="disabled"
                    wire:target="checkQuickDeployPollNow"
                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="checkQuickDeployPollNow" />
                    <span wire:loading.remove wire:target="checkQuickDeployPollNow">{{ __('Check now') }}</span>
                    <span wire:loading wire:target="checkQuickDeployPollNow">{{ __('Checking…') }}</span>
                </button>
            @endif
        </div>
        @if ($pollLog === [])
            <p class="px-3 py-2.5 text-[11px] leading-relaxed text-brand-mist">
                {{ __('No checks yet — waiting for the next poll tick, or use Check now.') }}
            </p>
        @else
            <ul class="divide-y divide-brand-ink/5 max-h-56 overflow-y-auto">
                @foreach ($pollLog as $entry)
                    @php
                        $outcome = is_string($entry['outcome'] ?? null) ? (string) $entry['outcome'] : 'unchanged';
                        $style = $outcomeStyles[$outcome] ?? $outcomeStyles['unchanged'];
                        $label = $outcomeLabels[$outcome] ?? ucfirst(str_replace('_', ' ', $outcome));
                        $atRaw = is_string($entry['at'] ?? null) ? (string) $entry['at'] : null;
                        $atLabel = $atRaw;
                        if ($atRaw) {
                            try {
                                $atLabel = \Illuminate\Support\Carbon::parse($atRaw)->timezone(config('app.timezone'))->format('M j, g:i A');
                            } catch (\Throwable) {
                                $atLabel = $atRaw;
                            }
                        }
                        $shaShort = is_string($entry['sha_short'] ?? null) && $entry['sha_short'] !== ''
                            ? (string) $entry['sha_short']
                            : (is_string($entry['sha'] ?? null) && $entry['sha'] !== '' ? \Illuminate\Support\Str::substr((string) $entry['sha'], 0, 7) : '—');
                        $msg = is_string($entry['message'] ?? null) ? (string) $entry['message'] : '';
                    @endphp
                    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-3 py-1.5 text-[11px] leading-snug">
                        <span class="shrink-0 tabular-nums text-brand-mist">{{ $atLabel }}</span>
                        <span class="shrink-0 font-mono text-brand-ink">{{ $shaShort }}</span>
                        <span class="inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $style }}">{{ $label }}</span>
                        @if ($msg !== '')
                            <span class="min-w-0 flex-1 text-brand-moss">{{ $msg }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
