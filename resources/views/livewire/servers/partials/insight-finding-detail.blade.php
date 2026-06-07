@php
    /**
     * Body of the per-finding detail modal. Receives:
     *   $detail = WorkspaceInsights::selectedFindingDetail (decorated array, see component).
     *
     * Rendered inside an x-modal-style wrapper that owns the chrome (backdrop,
     * close-on-escape). Keep this partial focused on content only.
     */
    $f = $detail['finding'];
    $cfg = $detail['config'];
    $signalRows = $detail['signalRows'];
    $fixHistory = $detail['fixHistory'];
    $correlationFindings = $detail['correlationFindings'];
    $actions = $detail['actions'];
    // All datetime renders below funnel through ServerDateFormatter so the operator's
    // server-level format + timezone preference (Settings → Reference) wins over raw UTC.
    $fmt = fn ($v) => \App\Support\Servers\ServerDateFormatter::format($v, $server ?? null);

    [$severityChipClass, $severityIcon, $severityLabel] = match ($f->severity) {
        'critical' => ['bg-red-100 text-red-900 ring-red-300', 'heroicon-s-exclamation-triangle', __('Critical')],
        'warning' => ['bg-amber-100 text-amber-900 ring-amber-300', 'heroicon-s-exclamation-circle', __('Warning')],
        'info' => ['bg-sky-100 text-sky-900 ring-sky-300', 'heroicon-s-information-circle', __('Info')],
        default => ['bg-brand-sand text-brand-ink ring-brand-ink/15', 'heroicon-s-bell', __('Notice')],
    };

    $statusChipClass = match ($f->status) {
        'open' => 'bg-amber-100 text-amber-900 ring-amber-300',
        'resolved' => 'bg-emerald-100 text-emerald-900 ring-emerald-300',
        'ignored' => 'bg-slate-100 text-slate-700 ring-slate-300',
        default => 'bg-brand-sand text-brand-ink ring-brand-ink/15',
    };

    $kindChipClass = $f->kind === 'suggestion'
        ? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
        : 'bg-brand-sand text-brand-ink ring-brand-ink/15';
@endphp

<div class="space-y-5">
    {{-- Header --}}
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $severityChipClass }}">
                <x-dynamic-component :component="$severityIcon" class="h-4 w-4" aria-hidden="true" />
                {{ $severityLabel }}
            </span>
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $kindChipClass }}">
                {{ $f->kind === 'suggestion' ? __('Suggestion') : __('Problem') }}
            </span>
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $statusChipClass }}">
                {{ ucfirst((string) $f->status) }}
            </span>
        </div>
        <h3 class="text-base font-semibold text-brand-ink leading-snug break-words [overflow-wrap:anywhere]">{{ $f->title }}</h3>
        @if ($detail['label'])
            <p class="text-xs text-brand-mist">{{ $detail['label'] }}</p>
        @endif
    </div>

    {{-- What & when --}}
    <dl class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 bg-brand-sand/15 text-sm">
        <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
            <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Insight key') }}</dt>
            <dd>
                <code class="rounded-md bg-white/70 px-1.5 py-0.5 font-mono text-xs text-brand-ink">{{ $f->insight_key }}</code>
            </dd>
        </div>
        @if ($f->detected_at)
            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Detected') }}</dt>
                <dd class="text-sm text-brand-ink">
                    <time datetime="{{ $f->detected_at->toIso8601String() }}" title="{{ $fmt($f->detected_at) }}">
                        {{ $f->detected_at->diffForHumans() }}
                    </time>
                </dd>
            </div>
        @endif
        @if ($f->acknowledged_at)
            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Acknowledged') }}</dt>
                <dd class="text-sm text-brand-ink">
                    <time datetime="{{ $f->acknowledged_at->toIso8601String() }}" title="{{ $fmt($f->acknowledged_at) }}">
                        {{ $f->acknowledged_at->diffForHumans() }}
                    </time>
                    @if ($detail['acknowledgedByName'])
                        <span class="text-brand-mist">{{ __('by') }} {{ $detail['acknowledgedByName'] }}</span>
                    @endif
                </dd>
            </div>
        @endif
        @if ($f->ignored_at)
            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Ignored') }}</dt>
                <dd class="text-sm text-brand-ink">
                    <time datetime="{{ $f->ignored_at->toIso8601String() }}" title="{{ $fmt($f->ignored_at) }}">
                        {{ $f->ignored_at->diffForHumans() }}
                    </time>
                    @if ($detail['ignoredByName'])
                        <span class="text-brand-mist">{{ __('by') }} {{ $detail['ignoredByName'] }}</span>
                    @endif
                </dd>
            </div>
        @endif
        @if ($f->resolved_at)
            <div class="grid grid-cols-1 gap-1 px-3 py-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Resolved') }}</dt>
                <dd class="text-sm text-brand-ink">
                    <time datetime="{{ $f->resolved_at->toIso8601String() }}" title="{{ $fmt($f->resolved_at) }}">
                        {{ $f->resolved_at->diffForHumans() }}
                    </time>
                </dd>
            </div>
        @endif
    </dl>

    {{-- Body / what this means --}}
    @if ($f->body)
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('What this means') }}</p>
            <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-brand-ink [overflow-wrap:anywhere]">{{ $f->body }}</p>
        </div>
    @endif

    {{-- Signal data (raw numbers from the runner) --}}
    @if ($signalRows !== [])
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Signal data') }}</p>
            <p class="mt-0.5 text-xs text-brand-mist">{{ __('Exact values the runner saw when this finding was emitted.') }}</p>
            <dl class="mt-2 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 bg-white text-xs">
                @foreach ($signalRows as $key => $value)
                    <div class="grid grid-cols-1 gap-1 px-3 py-1.5 sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-center sm:gap-3">
                        <dt class="font-mono text-[11px] text-brand-moss">{{ $key }}</dt>
                        <dd class="break-all font-mono text-[11px] text-brand-ink">
                            @if (is_bool($value))
                                {{ $value ? 'true' : 'false' }}
                            @elseif (is_null($value))
                                <span class="text-brand-mist">null</span>
                            @elseif (is_scalar($value))
                                {{ $value }}
                            @else
                                {{ json_encode($value, JSON_UNESCAPED_SLASHES) }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    {{-- Fix run + history --}}
    @php
        $hasFixHistory = $fixHistory['run_status'] !== 'idle'
            || $fixHistory['output']
            || $fixHistory['backup_path'];
        [$runChip, $runLabel, $runIcon] = match ($fixHistory['run_status']) {
            'queued' => ['bg-amber-100 text-amber-900 ring-amber-300', __('Queued · running'), 'heroicon-o-arrow-path'],
            'succeeded' => ['bg-emerald-100 text-emerald-900 ring-emerald-300', __('Succeeded'), 'heroicon-o-check-circle'],
            'failed' => ['bg-red-100 text-red-900 ring-red-300', __('Failed'), 'heroicon-o-x-circle'],
            'refused' => ['bg-amber-100 text-amber-900 ring-amber-300', __('Refused at preflight'), 'heroicon-o-no-symbol'],
            default => ['bg-brand-sand text-brand-ink ring-brand-ink/15', __('Not yet run'), 'heroicon-o-minus-circle'],
        };
    @endphp
    @if ($hasFixHistory)
        <div>
            <div class="flex items-center justify-between gap-2">
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Fix run') }}</p>
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $runChip }}">
                    <x-dynamic-component :component="$runIcon" class="h-4 w-4 {{ $fixHistory['run_status'] === 'queued' ? 'animate-spin' : '' }}" aria-hidden="true" />
                    {{ $runLabel }}
                </span>
            </div>
            <div class="mt-2 space-y-2">
                @if ($fixHistory['run_started_at'] && $fixHistory['run_status'] === 'queued')
                    <p class="text-sm text-brand-moss">
                        {{ __('Dispatched') }}
                        <time datetime="{{ $fixHistory['run_started_at']->toIso8601String() }}" title="{{ $fmt($fixHistory['run_started_at']) }}" class="font-medium text-brand-ink">
                            {{ $fixHistory['run_started_at']->diffForHumans() }}
                        </time>
                        — {{ __('this view auto-refreshes while the job is in flight.') }}
                    </p>
                @endif
                @if ($fixHistory['applied_at'])
                    <p class="text-sm text-brand-ink">
                        {{ __('Applied') }}
                        <time datetime="{{ $fixHistory['applied_at']->toIso8601String() }}" title="{{ $fmt($fixHistory['applied_at']) }}" class="font-medium">
                            {{ $fixHistory['applied_at']->diffForHumans() }}
                        </time>
                        @if ($fixHistory['applied_by'])
                            <span class="text-brand-mist">{{ __('by') }} {{ $fixHistory['applied_by'] }}</span>
                        @endif
                    </p>
                @endif
                @if ($fixHistory['failed_at'])
                    <div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-red-700">
                            {{ __('Failed') }}
                            <time datetime="{{ $fixHistory['failed_at']->toIso8601String() }}" title="{{ $fmt($fixHistory['failed_at']) }}">
                                · {{ $fixHistory['failed_at']->diffForHumans() }}
                            </time>
                        </p>
                        @if ($fixHistory['failed_reason'])
                            <p class="mt-0.5 text-sm text-red-900">{{ $fixHistory['failed_reason'] }}</p>
                        @endif
                    </div>
                @endif
                @if ($fixHistory['refused_at'])
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">
                            {{ __('Refused at preflight') }}
                            <time datetime="{{ $fixHistory['refused_at']->toIso8601String() }}" title="{{ $fmt($fixHistory['refused_at']) }}">
                                · {{ $fixHistory['refused_at']->diffForHumans() }}
                            </time>
                        </p>
                        @if ($fixHistory['refused_reason'])
                            <p class="mt-0.5 text-sm text-amber-900">{{ $fixHistory['refused_reason'] }}</p>
                        @endif
                    </div>
                @endif
                @if ($fixHistory['output'])
                    <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15" {{ $fixHistory['run_status'] === 'failed' ? 'open' : '' }}>
                        <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-brand-moss">{{ __('Fix output') }}</summary>
                        <pre class="overflow-x-auto whitespace-pre-wrap break-words border-t border-brand-ink/10 px-3 py-2 font-mono text-[11px] leading-5 text-brand-ink">{{ $fixHistory['output'] }}</pre>
                    </details>
                @endif
                @if ($fixHistory['backup_path'])
                    <p class="text-xs text-brand-mist">
                        {{ __('Backup') }}: <code class="font-mono">{{ $fixHistory['backup_path'] }}</code>
                    </p>
                @endif
            </div>
        </div>
    @endif

    {{-- Correlation --}}
    @if ($correlationFindings->isNotEmpty())
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Related findings') }}</p>
            <ul class="mt-2 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 bg-white">
                @foreach ($correlationFindings as $related)
                    <li class="px-3 py-2">
                        <button type="button" wire:click="openFindingDetail({{ $related->id }})" class="flex w-full items-center justify-between gap-3 text-left hover:text-brand-forest">
                            <span class="min-w-0 flex-1 truncate text-sm text-brand-ink">{{ $related->title }}</span>
                            <span class="shrink-0 text-[10px] uppercase tracking-wide text-brand-mist">{{ $related->severity }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
