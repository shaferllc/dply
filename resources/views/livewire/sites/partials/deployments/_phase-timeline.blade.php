@php
    /**
     * Shared deployment phase/step timeline — rendered identically on the deploy
     * hub's Deploy tab and the deployment-detail permalink so the two reflect
     * each other.
     *
     * @var array<int, array<string, mixed>> $timelinePhases  from SiteDeployTimeline::forDeployment()
     * @var \App\Models\SiteDeployment $deployment
     */
@endphp
<ol class="space-y-2">
    @foreach ($timelinePhases as $phase)
        @php
            $st = $phase['status'];
            $stepCount = count($phase['steps']);
            $durTxt = $phase['duration_ms'] > 0 ? number_format($phase['duration_ms'] / 1000, 1).'s' : null;
        @endphp
        <li @class([
            'rounded-2xl border px-4 py-3 transition-colors',
            'border-emerald-200 bg-emerald-50/50' => $st === 'success',
            'border-rose-200 bg-rose-50/50' => $st === 'failed',
            'border-amber-200 bg-amber-50/50' => $st === 'running',
            'border-brand-ink/10 bg-brand-sand/10' => in_array($st, ['skipped', 'pending'], true),
        ])>
            <div class="flex items-center gap-3">
                <span @class([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-1 ring-inset font-semibold text-xs',
                    'bg-emerald-100 text-emerald-800 ring-emerald-200' => $st === 'success',
                    'bg-rose-100 text-rose-800 ring-rose-200' => $st === 'failed',
                    'bg-amber-100 text-amber-800 ring-amber-200' => $st === 'running',
                    'bg-white text-brand-mist ring-brand-ink/10' => in_array($st, ['skipped', 'pending'], true),
                ])>
                    @switch ($st)
                        @case('success')
                            <x-heroicon-m-check class="h-4 w-4" aria-hidden="true" />
                            @break
                        @case('failed')
                            <x-heroicon-m-x-mark class="h-4 w-4" aria-hidden="true" />
                            @break
                        @case('running')
                            <x-heroicon-m-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                            @break
                        @case('skipped')
                            <x-heroicon-m-minus class="h-4 w-4" aria-hidden="true" />
                            @break
                        @default
                            {{ $loop->iteration }}
                    @endswitch
                </span>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                        <span class="font-semibold text-brand-ink">{{ $phase['label'] }}</span>
                        <span class="text-[11px] text-brand-moss">
                            @switch ($st)
                                @case('success')
                                    {{ trans_choice('{1} :count step|[2,*] :count steps', $stepCount, ['count' => $stepCount]) }}@if ($durTxt) · <span class="font-mono">{{ $durTxt }}</span>@endif
                                    @break
                                @case('failed')
                                    <span class="font-semibold text-rose-700">{{ __('Failed') }}</span>@if ($durTxt) · <span class="font-mono">{{ $durTxt }}</span>@endif
                                    @break
                                @case('running')
                                    {{ __('Running…') }}
                                    @break
                                @case('skipped')
                                    {{ __('No steps') }}
                                    @break
                                @default
                                    {{ __('Not started') }}
                            @endswitch
                        </span>
                    </p>
                </div>
            </div>

            @if ($phase['steps'] !== [])
                <ul class="mt-2 space-y-1 pl-11">
                    @foreach ($phase['steps'] as $step)
                        @php($stepFailed = ! $step['ok'] && ! $step['skipped'] && ! ($step['pending'] ?? false))
                        <li>
                            <div class="flex items-center gap-2 text-xs">
                                <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold {{ $step['glyph_classes'] }}">{{ $step['glyph'] }}</span>
                                <span class="min-w-0 truncate {{ $stepFailed ? 'font-medium text-rose-800' : (($step['pending'] ?? false) ? 'text-brand-mist' : 'text-brand-ink') }}">{{ $step['label'] }}</span>
                                @if ($step['pending'] ?? false)
                                    <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] text-brand-moss">{{ __('queued') }}</span>
                                @elseif ($step['skipped'])
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] text-amber-900">{{ __('skipped') }}</span>
                                @elseif ($step['duration_ms'] > 0)
                                    <span class="font-mono text-brand-mist">{{ $step['duration_ms'] >= 1000 ? number_format($step['duration_ms'] / 1000, 1).'s' : $step['duration_ms'].'ms' }}</span>
                                @endif
                            </div>
                            @if (($step['output'] ?? '') !== '')
                                {{-- Any step with output is expandable (failed steps open by default). --}}
                                <div x-data="{ open: @js($stepFailed) }" class="mt-1">
                                    <button type="button" x-on:click="open = ! open"
                                        class="inline-flex items-center gap-1 text-[10px] font-semibold {{ $stepFailed ? 'text-rose-700' : 'text-brand-moss' }} hover:underline">
                                        <span class="font-mono" x-text="open ? '▾' : '▸'"></span>
                                        <span x-text="open ? @js(__('Hide output')) : @js(__('Show output'))"></span>
                                    </button>
                                    <pre x-show="open" x-cloak class="mt-1.5 max-h-96 overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-[11px] leading-relaxed {{ $stepFailed ? 'text-rose-100/95' : 'text-brand-cream/90' }}">{{ $step['output'] }}</pre>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>

@if ($deployment->exit_code !== null && $deployment->exit_code !== 0)
    <div class="mt-4 space-y-2">
        <p class="font-mono text-xs text-rose-700">{{ __('exit :code', ['code' => $deployment->exit_code]) }}</p>
        {{-- A deploy can fail BETWEEN recorded phases (e.g. a thrown exception that
             never becomes a pipeline step), leaving the timeline with nothing to
             expand. Surface the captured failure reason from the log. --}}
        @php($failLog = trim((string) $deployment->log_output))
        @if ($failLog !== '')
            @php($failTail = mb_strlen($failLog) > 4000 ? '…'.mb_substr($failLog, -4000) : $failLog)
            <pre class="max-h-60 overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-[11px] leading-relaxed text-rose-100/95">{{ $failTail }}</pre>
        @endif
    </div>
@endif
