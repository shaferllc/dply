@php
    /**
     * Shared deployment phase/step timeline — rendered identically on the deploy
     * hub's Deploy tab and the deployment-detail permalink so the two reflect
     * each other.
     *
     * Rendered as a connected vertical rail: each phase is a node on the rail,
     * the segment below a node is tinted by that node's outcome (green = done,
     * amber = running, gray = upcoming) so the eye follows the pipeline's
     * progress top-to-bottom. Steps hang off each node as compact sub-rows.
     *
     * @var array<int, array<string, mixed>> $timelinePhases  from SiteDeployTimeline::forDeployment()
     * @var \App\Models\SiteDeployment $deployment
     */

    $phaseTotal = count($timelinePhases);
    $phaseDone = 0;
    $phaseFailed = false;
    $currentPhaseLabel = null;
    foreach ($timelinePhases as $p) {
        if ($p['status'] === 'success' || $p['status'] === 'skipped') {
            $phaseDone++;
        } elseif ($p['status'] === 'failed') {
            $phaseFailed = true;
        } elseif ($p['status'] === 'running' && $currentPhaseLabel === null) {
            $currentPhaseLabel = $p['label'];
        }
    }
    $isRunningOverall = $deployment->status === 'running';
    $pct = $phaseTotal > 0 ? (int) round(($phaseDone / $phaseTotal) * 100) : 0;
@endphp

{{-- Progress meter — a single glance at how far the pipeline has gotten and
     what it's doing right now, above the detailed rail. --}}
<div class="mb-4 flex items-center gap-3">
    <div class="relative h-1.5 flex-1 overflow-hidden rounded-full bg-brand-ink/[0.07]">
        <div @class([
            'h-full rounded-full transition-[width] duration-500 ease-out',
            'bg-emerald-500' => ! $phaseFailed && ! $isRunningOverall,
            'bg-rose-500' => $phaseFailed,
            'bg-amber-400' => $isRunningOverall && ! $phaseFailed,
        ]) style="width: {{ $phaseFailed ? max($pct, 8) : $pct }}%"></div>
    </div>
    <p class="shrink-0 text-[11px] font-medium text-brand-moss">
        @if ($phaseFailed)
            <span class="font-semibold text-rose-700">{{ __('Failed') }}</span>
        @elseif ($currentPhaseLabel)
            <span class="font-semibold text-amber-700">{{ $currentPhaseLabel }}</span> · {{ __('running') }}
        @elseif ($phaseDone === $phaseTotal && $phaseTotal > 0)
            <span class="font-semibold text-emerald-700">{{ __('Complete') }}</span>
        @else
            {{ __('Pending') }}
        @endif
        <span class="ml-1 tabular-nums text-brand-mist">{{ $phaseDone }}/{{ $phaseTotal }}</span>
    </p>
</div>

<ol class="relative">
    @foreach ($timelinePhases as $phase)
        @php
            $st = $phase['status'];
            $stepCount = count($phase['steps']);
            $durTxt = $phase['duration_ms'] > 0 ? number_format($phase['duration_ms'] / 1000, 1).'s' : null;
        @endphp
        <li class="relative pl-12">
            {{-- Rail segment beneath this node, tinted by this phase's outcome so
                 the line reads as "done" (green) up to the active node, then fades
                 to gray for what's still ahead. Hidden on the last phase. --}}
            @unless ($loop->last)
                <span aria-hidden="true" @class([
                    'absolute left-[15px] top-8 bottom-0 w-0.5 -translate-x-1/2 rounded-full',
                    'bg-emerald-400/70' => $st === 'success',
                    'bg-rose-400/70' => $st === 'failed',
                    'bg-gradient-to-b from-amber-400 to-brand-ink/10' => $st === 'running',
                    'bg-brand-ink/[0.08]' => in_array($st, ['skipped', 'pending'], true),
                ])></span>
            @endunless

            <div class="flex min-h-8 flex-col justify-center pb-5">
                {{-- Phase node --}}
                <span @class([
                    'absolute left-0 top-0 flex h-[30px] w-[30px] items-center justify-center rounded-full text-[11px] font-bold shadow-sm',
                    'bg-emerald-500 text-white' => $st === 'success',
                    'bg-rose-500 text-white' => $st === 'failed',
                    'bg-amber-400 text-white ring-4 ring-amber-200/60' => $st === 'running',
                    'bg-brand-sand/70 text-brand-moss ring-1 ring-inset ring-brand-ink/10' => $st === 'skipped',
                    'bg-white text-brand-mist ring-1 ring-inset ring-brand-ink/15' => $st === 'pending',
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
                            <x-heroicon-m-minus class="h-3.5 w-3.5" aria-hidden="true" />
                            @break
                        @default
                            {{ $loop->iteration }}
                    @endswitch
                </span>

                {{-- Phase header --}}
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span @class([
                        'text-sm font-semibold',
                        'text-brand-ink' => $st !== 'pending' && $st !== 'skipped',
                        'text-brand-mist' => $st === 'pending' || $st === 'skipped',
                    ])>{{ $phase['label'] }}</span>
                    <span class="text-[11px] text-brand-moss">
                        @switch ($st)
                            @case('success')
                                {{ trans_choice('{1} :count step|[2,*] :count steps', $stepCount, ['count' => $stepCount]) }}@if ($durTxt) · <span class="font-mono tabular-nums">{{ $durTxt }}</span>@endif
                                @break
                            @case('failed')
                                <span class="font-semibold text-rose-700">{{ __('Failed') }}</span>@if ($durTxt) · <span class="font-mono tabular-nums">{{ $durTxt }}</span>@endif
                                @break
                            @case('running')
                                <span class="font-semibold text-amber-700">{{ __('Running…') }}</span>
                                @break
                            @case('skipped')
                                {{ __('No steps') }}
                                @break
                            @default
                                {{ __('Not started') }}
                        @endswitch
                    </span>
                </div>

                {{-- Steps --}}
                @if ($phase['steps'] !== [])
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($phase['steps'] as $step)
                            @include('livewire.sites.partials.deployments._phase-timeline-step', [
                                'step' => $step,
                                'stepKeyBase' => 'step-out-'.($step['id'] ?? $loop->index),
                            ])
                        @endforeach
                    </ul>
                @endif

                {{-- Inline guided fix, hung off the failed phase. Hosts opt in by
                     passing a $dbFix payload (server + site) — only when this is
                     the latest, still-failed deploy and the failure matched the
                     database-connection remediation. --}}
                @if (($dbFix ?? null) && $st === 'failed')
                    <div class="mt-3">
                        @livewire('sites.deploy-database-fix', [
                            'server' => $dbFix['server'],
                            'site' => $dbFix['site'],
                            'deployment' => $deployment,
                        ], key('deploy-db-fix-'.$deployment->id))
                    </div>
                @endif
            </div>
        </li>
    @endforeach
</ol>

@if ($deployment->exit_code !== null && $deployment->exit_code !== 0)
    <div class="mt-4 space-y-2 rounded-xl border border-rose-200 bg-rose-50/50 p-3">
        <p class="font-mono text-xs font-semibold text-rose-700">{{ __('exit :code', ['code' => $deployment->exit_code]) }}</p>
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
