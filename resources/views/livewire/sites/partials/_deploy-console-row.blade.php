{{--
    One live deploy-console row: a site's latest deploy with its expandable phase
    timeline and per-step output. Shared by the per-site deploy sidebar
    (deploy-control) and the fleet deploy console (servers/index).

    Phases collapse individually so a long success run reads as a short stack of
    phase headers; the running/failed phase auto-opens, and a failure surfaces a
    summary banner up top so you never hunt for the broken step.

    Expects: $row (a DeployConsoleRows entry). Optional: $keyPrefix (string) to
    keep wire:keys distinct between consoles mounted in different components.
--}}
@php
    $keyPrefix ??= 'deploy';
    $rs = $row['status'];

    // First failed step across all phases — drives the error banner.
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
@endphp
<div wire:key="{{ $keyPrefix }}-row-{{ $row['id'] }}-{{ $rs }}" x-data="{ open: @js($row['in_progress'] || $rs === 'failed') }" @class([
    'overflow-hidden rounded-xl border',
    'border-emerald-200 bg-emerald-50/40' => $rs === 'success',
    'border-rose-200 bg-rose-50/40' => $rs === 'failed',
    'border-amber-200 bg-amber-50/40' => $row['in_progress'],
    'border-brand-ink/10 bg-brand-sand/10' => ! $row['in_progress'] && ! in_array($rs, ['success', 'failed'], true),
])>
    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left">
        <span class="flex min-w-0 items-center gap-2">
            @switch (true)
                @case($rs === 'success') <x-heroicon-m-check-circle class="h-4 w-4 shrink-0 text-emerald-600" /> @break
                @case($rs === 'failed') <x-heroicon-m-x-circle class="h-4 w-4 shrink-0 text-rose-600" /> @break
                @case($row['in_progress']) <x-heroicon-m-arrow-path class="h-4 w-4 shrink-0 animate-spin text-amber-600" /> @break
                @default <x-heroicon-m-clock class="h-4 w-4 shrink-0 text-brand-mist" />
            @endswitch
            <span class="min-w-0">
                <span class="flex items-center gap-1.5">
                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $row['name'] }}</span>
                    @if ($row['is_self'])<span class="rounded bg-brand-sand/60 px-1 text-[9px] font-semibold uppercase text-brand-moss">{{ __('this') }}</span>@endif
                    @if ($row['is_worker'])<span class="rounded bg-violet-100 px-1 text-[9px] font-semibold uppercase text-violet-800">{{ __('worker') }}</span>@endif
                    @if ($row['server'])<span class="truncate text-[10px] text-brand-mist">{{ $row['server'] }}</span>@endif
                </span>
                <span class="block truncate text-[11px] text-brand-moss">
                    @if ($row['current_phase']){{ $row['current_phase'] }} · {{ __('running') }}@else{{ ucfirst($rs) }}@endif
                    @if ($row['phase_total'] > 0)<span class="tabular-nums text-brand-mist"> · {{ $row['phase_done'] }}/{{ $row['phase_total'] }}</span>@endif
                </span>
            </span>
        </span>
        <span class="font-mono text-[10px] text-brand-mist" x-text="open ? '▾' : '▸'"></span>
    </button>

    <div x-show="open" x-cloak class="border-t border-brand-ink/10 px-3 py-2">
        @if ($row['phases'] === [])
            <p class="py-2 text-center text-[11px] text-brand-moss">{{ $row['starting_fresh'] ? __('Starting — clearing the previous run…') : __('Queued — waiting for a worker…') }}</p>
        @else
            {{-- Error banner: pull the failed step + its output to the top. --}}
            @if ($rs === 'failed' && $failedStep)
                @php
                    $failOutput = trim((string) ($failedStep['output'] ?? ''));
                    $failOutput = str_replace("\r\n", "\n", $failOutput);
                @endphp
                <div class="mb-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5">
                    <div class="flex items-start gap-2">
                        <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-rose-600" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-rose-800">
                                {{ __('Failed at :phase', ['phase' => $failedPhaseLabel]) }}
                                <span class="font-normal text-rose-700">· {{ $failedStep['label'] }}</span>
                            </p>
                            @if ($failOutput !== '')
                                <pre class="mt-1.5 max-h-48 overflow-auto rounded-md bg-brand-ink p-2.5 font-mono text-[11px] leading-relaxed text-rose-100/95">{{ $failOutput }}</pre>
                            @endif
                            @if ($row['latest'] && $row['latest']->server_id)
                                <a href="{{ route('sites.deployments.show', ['server' => $row['latest']->server_id, 'site' => $row['id'], 'deployment' => $row['latest']]) }}" wire:navigate class="mt-1.5 inline-flex items-center gap-1 text-[10px] font-semibold text-rose-700 hover:underline">
                                    {{ __('Open full deploy log') }} <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Phases, each collapsible. Collapsed by default; the running/failed
                 one auto-opens so a long success run stays a short stack. --}}
            <ul class="space-y-1.5">
                @foreach ($row['phases'] as $phase)
                    @php
                        $pst = $phase['status'];
                        $stepCount = count($phase['steps'] ?? []);
                        $phaseDur = (int) ($phase['duration_ms'] ?? 0);
                        $phaseAutoOpen = in_array($pst, ['running', 'failed'], true);
                    @endphp
                    <li wire:key="{{ $keyPrefix }}-phase-{{ $row['id'] }}-{{ $loop->index }}-{{ $pst }}"
                        x-data="{ open: @js($phaseAutoOpen) }" @class([
                            'rounded-lg border',
                            'border-rose-200/70 bg-rose-50/50' => $pst === 'failed',
                            'border-amber-200/70 bg-amber-50/40' => $pst === 'running',
                            'border-brand-ink/10 bg-white/50' => ! in_array($pst, ['failed', 'running'], true),
                        ])>
                        <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-2 px-2.5 py-1.5 text-left">
                            <span class="flex min-w-0 items-center gap-2 text-xs">
                                @switch ($pst)
                                    @case('success') <x-heroicon-m-check class="h-3.5 w-3.5 shrink-0 text-emerald-600" /> @break
                                    @case('failed') <x-heroicon-m-x-mark class="h-3.5 w-3.5 shrink-0 text-rose-600" /> @break
                                    @case('running') <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin text-amber-600" /> @break
                                    @default <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-brand-ink/20"></span>
                                @endswitch
                                <span @class(['truncate font-semibold', 'text-brand-ink' => in_array($pst, ['running', 'success'], true), 'text-rose-800' => $pst === 'failed', 'text-brand-mist' => in_array($pst, ['pending', 'skipped'], true)])>{{ $phase['label'] }}</span>
                                @if ($pst === 'running')<span class="rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] text-amber-800 ring-1 ring-inset ring-amber-200/70">{{ __('running') }}</span>@endif
                            </span>
                            <span class="flex shrink-0 items-center gap-2 text-[10px] text-brand-mist">
                                @if ($stepCount > 0)<span class="tabular-nums">{{ $stepCount }} {{ trans_choice('step|steps', $stepCount) }}</span>@endif
                                @if ($phaseDur > 0)<span class="font-mono tabular-nums">{{ $fmtMs($phaseDur) }}</span>@endif
                                <span class="font-mono" x-text="open ? '▾' : '▸'"></span>
                            </span>
                        </button>
                        @if ($stepCount > 0)
                            <div x-show="open" x-cloak class="border-t border-brand-ink/10 px-2.5 py-2">
                                <ul class="space-y-1.5 pl-1">
                                    @foreach ($phase['steps'] as $step)
                                        @include('livewire.sites.partials.deployments._phase-timeline-step', [
                                            'step' => $step,
                                            'stepKeyBase' => $keyPrefix.'-step-'.$row['id'].'-'.($step['id'] ?? ($loop->parent->index.'-'.$loop->index)),
                                        ])
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($rs !== 'failed' && $row['latest'] && $row['latest']->server_id)
                <a href="{{ route('sites.deployments.show', ['server' => $row['latest']->server_id, 'site' => $row['id'], 'deployment' => $row['latest']]) }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-forest hover:underline">
                    {{ __('Full log') }} <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
                </a>
            @endif
        @endif
    </div>
</div>
