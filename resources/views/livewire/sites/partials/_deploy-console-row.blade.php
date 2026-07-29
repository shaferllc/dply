{{--
    One live deploy-console site card with expandable phase timeline.
    Shared by fleet servers index, workspace console, and sync drawer.

    Expects: $row (DeployConsoleRows entry). Optional: $keyPrefix.
--}}
@php
    $keyPrefix ??= 'deploy';
    $rs = $row['status'];
    $phaseDone = (int) ($row['phase_done'] ?? 0);
    $phaseTotal = (int) ($row['phase_total'] ?? 0);
    $phasePct = $phaseTotal > 0 ? (int) round(($phaseDone / $phaseTotal) * 100) : ($row['in_progress'] ? 8 : 0);

    $failedStep = null;
    $failedPhaseLabel = null;
    foreach ($row['phases'] as $ph) {
        foreach (($ph['steps'] ?? []) as $s) {
            $sFailed = ! ($s['ok'] ?? false) && ! ($s['skipped'] ?? false)
                && ! ($s['pending'] ?? false) && ! ($s['running'] ?? false);
            if ($sFailed) {
                $failedStep = $s;
                $failedPhaseLabel = $ph['label'];
                break 2;
            }
        }
    }

    $fmtMs = fn (int $ms): string => $ms >= 1000 ? number_format($ms / 1000, 1).'s' : $ms.'ms';

    $statusLabel = match (true) {
        $row['in_progress'] && filled($row['current_phase'] ?? null) => (string) $row['current_phase'],
        $row['in_progress'] => __('Starting…'),
        $rs === 'success' => __('Succeeded'),
        $rs === 'failed' => __('Failed'),
        $rs === 'starting' => __('Starting…'),
        default => ucfirst((string) $rs),
    };

    $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $row['name']) ?: 'S', 0, 2));
@endphp

<article
    wire:key="{{ $keyPrefix }}-row-{{ $row['id'] }}-{{ $rs }}"
    x-data="{ open: @js($row['in_progress'] || $rs === 'failed') }"
    @class([
        'overflow-hidden rounded-2xl border bg-white shadow-sm ring-1',
        'border-emerald-200/80 ring-emerald-500/10' => $rs === 'success' && ! $row['in_progress'],
        'border-rose-200/80 ring-rose-500/10' => $rs === 'failed',
        'border-brand-ink/10 ring-brand-ink/[0.04]' => $row['in_progress'] || (! in_array($rs, ['success', 'failed'], true)),
    ])
>
    <div @class([
        'flex',
        'bg-gradient-to-r from-emerald-50/80 to-white' => $rs === 'success' && ! $row['in_progress'],
        'bg-gradient-to-r from-rose-50/80 to-white' => $rs === 'failed',
        'bg-white' => $row['in_progress'] || (! in_array($rs, ['success', 'failed'], true)),
    ])>
        <div @class([
            'w-1 shrink-0 self-stretch',
            'bg-emerald-500' => $rs === 'success' && ! $row['in_progress'],
            'bg-rose-500' => $rs === 'failed',
            'bg-brand-sage' => $row['in_progress'],
            'bg-brand-ink/15' => ! $row['in_progress'] && ! in_array($rs, ['success', 'failed'], true),
        ]) aria-hidden="true"></div>

        <div class="min-w-0 flex-1">
            <button type="button" x-on:click="open = ! open" class="flex w-full items-start gap-3 px-4 py-3.5 text-left transition hover:bg-brand-sand/20">
                <span @class([
                    'mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-[11px] font-bold tracking-wide ring-1 ring-inset',
                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => $rs === 'success' && ! $row['in_progress'],
                    'bg-rose-50 text-rose-800 ring-rose-200' => $rs === 'failed',
                    'bg-brand-sand/70 text-brand-ink ring-brand-ink/10' => $row['in_progress'] || (! in_array($rs, ['success', 'failed'], true)),
                ])>
                    @if ($row['in_progress'])
                        <x-heroicon-m-arrow-path class="h-4 w-4 animate-spin text-brand-sage" aria-hidden="true" />
                    @elseif ($rs === 'success')
                        <x-heroicon-m-check class="h-4 w-4" aria-hidden="true" />
                    @elseif ($rs === 'failed')
                        <x-heroicon-m-x-mark class="h-4 w-4" aria-hidden="true" />
                    @else
                        {{ $initials }}
                    @endif
                </span>

                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-1.5">
                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $row['name'] }}</span>
                        @if ($row['is_self'])
                            <span class="rounded-md bg-brand-sand px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('this') }}</span>
                        @endif
                        @if ($row['is_worker'])
                            <span class="rounded-md bg-brand-ink/5 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-brand-ink">{{ __('worker') }}</span>
                        @endif
                    </span>

                    {{-- Deploy context: server / branch / commit — always visible, not buried in the expanded timeline. --}}
                    @if ($row['server'] || $row['branch'] || $row['short_sha'])
                        <span class="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[11px] text-brand-moss">
                            @if ($row['server'])
                                <span class="inline-flex min-w-0 items-center gap-1">
                                    <x-heroicon-o-server class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                    <span class="truncate font-medium text-brand-ink/80">{{ $row['server'] }}</span>
                                    @if ($row['server_ip'])
                                        <span class="truncate font-mono text-brand-mist">{{ $row['server_ip'] }}</span>
                                    @endif
                                </span>
                            @endif
                            @if ($row['branch'])
                                <span class="inline-flex min-w-0 items-center gap-1">
                                    <x-heroicon-o-tag class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                    <span class="truncate font-mono text-brand-ink/80">{{ $row['branch'] }}</span>
                                </span>
                            @endif
                            @if ($row['short_sha'])
                                <span class="inline-flex min-w-0 items-center gap-1">
                                    <x-heroicon-o-code-bracket class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                    @if ($row['commit_url'])
                                        <a
                                            href="{{ $row['commit_url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            x-on:click.stop
                                            class="truncate font-mono font-semibold text-brand-forest hover:underline"
                                            title="{{ $row['git_sha'] }}"
                                        >{{ $row['short_sha'] }}</a>
                                    @else
                                        <span class="truncate font-mono font-semibold text-brand-ink/80" title="{{ $row['git_sha'] }}">{{ $row['short_sha'] }}</span>
                                    @endif
                                </span>
                            @elseif ($row['in_progress'] || ($row['starting_fresh'] ?? false))
                                <span class="inline-flex items-center gap-1 text-brand-mist">
                                    <x-heroicon-o-code-bracket class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    {{ __('Commit pending…') }}
                                </span>
                            @endif
                        </span>
                    @endif

                    <span class="mt-1.5 flex flex-wrap items-center gap-2">
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-800 ring-emerald-200/80' => $rs === 'success' && ! $row['in_progress'],
                            'bg-rose-50 text-rose-800 ring-rose-200/80' => $rs === 'failed',
                            'bg-brand-sand/80 text-brand-ink ring-brand-ink/10' => $row['in_progress'] || (! in_array($rs, ['success', 'failed'], true)),
                        ])>{{ $statusLabel }}</span>
                        @if ($phaseTotal > 0)
                            <span class="font-mono text-[11px] tabular-nums text-brand-mist">{{ $phaseDone }}/{{ $phaseTotal }}</span>
                        @endif
                    </span>

                    @if ($phaseTotal > 0 || $row['in_progress'])
                        <span class="mt-2.5 block h-1 overflow-hidden rounded-full bg-brand-ink/10" aria-hidden="true">
                            <span
                                @class([
                                    'block h-full rounded-full transition-all duration-500',
                                    'bg-emerald-500' => $rs === 'success' && ! $row['in_progress'],
                                    'bg-rose-500' => $rs === 'failed',
                                    'bg-brand-sage' => $row['in_progress'] || (! in_array($rs, ['success', 'failed'], true)),
                                ])
                                style="width: {{ min(100, max($phasePct, $row['in_progress'] && $phasePct === 0 ? 12 : $phasePct)) }}%"
                            ></span>
                        </span>
                    @endif
                </span>

                <x-heroicon-m-chevron-down
                    class="mt-1 h-4 w-4 shrink-0 text-brand-mist transition-transform duration-200"
                    x-bind:class="open && 'rotate-180'"
                    aria-hidden="true"
                />
            </button>

            <div x-show="open" x-cloak class="border-t border-brand-ink/10 px-4 pb-4 pt-3">
                @if ($row['phases'] === [])
                    @if ($row['in_progress'] || ($row['starting_fresh'] ?? false))
                        <div class="flex items-center gap-3 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-3.5 py-4">
                            <x-spinner size="sm" />
                            <div>
                                <p class="text-xs font-semibold text-brand-ink">
                                    {{ $row['starting_fresh'] ? __('Starting deploy') : __('Queued') }}
                                </p>
                                <p class="mt-0.5 text-[11px] text-brand-moss">
                                    {{ $row['starting_fresh'] ? __('Clearing the previous run and handing off to a worker…') : __('Waiting for a worker to pick this up…') }}
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-3.5 py-4">
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Deploy finished') }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-moss">
                                {{ __('Phase details stay on the site deploy page — this sidebar keeps the list light.') }}
                            </p>
                            @if ($row['latest'] && $row['server_id'])
                                <a href="{{ route('sites.deployments.show', ['server' => $row['server_id'], 'site' => $row['id'], 'deployment' => $row['latest']]) }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-brand-forest hover:underline">
                                    {{ __('Open deploy log') }}
                                    <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                </a>
                            @endif
                        </div>
                    @endif
                @else
                    @if ($rs === 'failed')
                        @php
                            $failOutput = trim((string) ($failedStep['output'] ?? ''));
                            if ($failOutput === '') {
                                $failOutput = trim((string) ($row['latest']?->log_output ?? ''));
                                if (mb_strlen($failOutput) > 1200) {
                                    $failOutput = '…'.mb_substr($failOutput, -1200);
                                }
                            }
                            $failOutput = str_replace("\r\n", "\n", $failOutput);
                        @endphp
                        <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50/90 px-3.5 py-3">
                            <div class="flex items-start gap-2.5">
                                <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold text-rose-900">
                                        @if ($failedStep)
                                            {{ __('Failed at :phase', ['phase' => $failedPhaseLabel]) }}
                                            <span class="font-normal text-rose-700">· {{ $failedStep['label'] }}</span>
                                        @else
                                            {{ __('Failed before the pipeline phases ran') }}
                                        @endif
                                    </p>
                                    @if ($failOutput !== '')
                                        <pre class="mt-2 max-h-40 overflow-auto rounded-lg bg-brand-ink p-2.5 font-mono text-[11px] leading-relaxed text-rose-100/95">{{ $failOutput }}</pre>
                                    @endif
                                    @if ($row['latest'] && $row['server_id'])
                                        <a href="{{ route('sites.deployments.show', ['server' => $row['server_id'], 'site' => $row['id'], 'deployment' => $row['latest']]) }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-rose-800 hover:underline">
                                            {{ __('Open full deploy log') }}
                                            <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    <ol class="relative space-y-0 ps-2">
                        @foreach ($row['phases'] as $phase)
                            @php
                                $pst = $phase['status'];
                                $stepCount = count($phase['steps'] ?? []);
                                $phaseDur = (int) ($phase['duration_ms'] ?? 0);
                                $phaseAutoOpen = in_array($pst, ['running', 'failed'], true);
                                $isLast = $loop->last;
                            @endphp
                            <li
                                wire:key="{{ $keyPrefix }}-phase-{{ $row['id'] }}-{{ $loop->index }}-{{ $pst }}"
                                x-data="{ open: @js($phaseAutoOpen) }"
                                class="relative flex gap-3 pb-3 last:pb-0"
                            >
                                @unless ($isLast)
                                    <span class="absolute left-[11px] top-6 bottom-0 w-px bg-brand-ink/10" aria-hidden="true"></span>
                                @endunless

                                <span @class([
                                    'relative z-[1] mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full ring-4 ring-white',
                                    'bg-emerald-500 text-white' => $pst === 'success',
                                    'bg-rose-500 text-white' => $pst === 'failed',
                                    'bg-brand-sage text-white' => $pst === 'running',
                                    'bg-brand-ink/10 text-brand-mist' => in_array($pst, ['pending', 'skipped'], true),
                                ])>
                                    @switch ($pst)
                                        @case('success') <x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" /> @break
                                        @case('failed') <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" /> @break
                                        @case('running') <x-heroicon-m-arrow-path class="h-3.5 w-3.5 animate-spin" aria-hidden="true" /> @break
                                        @default <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                    @endswitch
                                </span>

                                <div @class([
                                    'min-w-0 flex-1 rounded-xl border px-3 py-2',
                                    'border-rose-200/70 bg-rose-50/40' => $pst === 'failed',
                                    'border-brand-sage/30 bg-brand-sand/30' => $pst === 'running',
                                    'border-brand-ink/10 bg-brand-sand/10' => ! in_array($pst, ['failed', 'running'], true),
                                ])>
                                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-2 text-left">
                                        <span class="flex min-w-0 items-center gap-2">
                                            <span @class([
                                                'truncate text-xs font-semibold',
                                                'text-brand-ink' => in_array($pst, ['running', 'success'], true),
                                                'text-rose-900' => $pst === 'failed',
                                                'text-brand-mist' => in_array($pst, ['pending', 'skipped'], true),
                                            ])>{{ $phase['label'] }}</span>
                                            @if ($pst === 'running')
                                                <span class="rounded-md bg-brand-ink px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-brand-cream">{{ __('live') }}</span>
                                            @endif
                                        </span>
                                        <span class="flex shrink-0 items-center gap-2 text-[10px] text-brand-mist">
                                            @if ($stepCount > 0)
                                                <span class="tabular-nums">{{ $stepCount }} {{ trans_choice('step|steps', $stepCount) }}</span>
                                            @endif
                                            @if ($phaseDur > 0)
                                                <span class="font-mono tabular-nums">{{ $fmtMs($phaseDur) }}</span>
                                            @endif
                                            @if ($stepCount > 0)
                                                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 transition-transform" x-bind:class="open && 'rotate-180'" aria-hidden="true" />
                                            @endif
                                        </span>
                                    </button>

                                    @if ($stepCount > 0)
                                        <div x-show="open" x-cloak class="mt-2 border-t border-brand-ink/10 pt-2">
                                            <ul class="space-y-1.5">
                                                @foreach ($phase['steps'] as $step)
                                                    @include('livewire.sites.partials.deployments._phase-timeline-step', [
                                                        'step' => $step,
                                                        'stepKeyBase' => $keyPrefix.'-step-'.$row['id'].'-'.($step['id'] ?? ($loop->parent->index.'-'.$loop->index)),
                                                    ])
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @if ($rs !== 'failed' && $row['latest'] && $row['server_id'])
                        <a
                            href="{{ route('sites.deployments.show', ['server' => $row['server_id'], 'site' => $row['id'], 'deployment' => $row['latest']]) }}"
                            wire:navigate
                            class="mt-3 inline-flex items-center gap-1.5 text-[11px] font-semibold text-brand-forest hover:underline"
                        >
                            {{ __('Full deploy log') }}
                            <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    @endif
                @endif
            </div>
        </div>
    </div>
</article>
