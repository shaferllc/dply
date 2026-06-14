{{--
    One live deploy-console row: a site's latest deploy with its expandable phase
    timeline and per-step output. Shared by the per-site deploy sidebar
    (deploy-control) and the fleet deploy console (servers/index).

    Expects: $row (a DeployConsoleRows entry). Optional: $keyPrefix (string) to
    keep wire:keys distinct between consoles mounted in different components.
--}}
@php
    $keyPrefix ??= 'deploy';
    $rs = $row['status'];
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
            <ul class="space-y-2">
                @foreach ($row['phases'] as $phase)
                    @php($pst = $phase['status'])
                    <li>
                        <div class="flex items-center gap-2 text-xs">
                            @switch ($pst)
                                @case('success') <x-heroicon-m-check class="h-3.5 w-3.5 shrink-0 text-emerald-600" /> @break
                                @case('failed') <x-heroicon-m-x-mark class="h-3.5 w-3.5 shrink-0 text-rose-600" /> @break
                                @case('running') <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin text-amber-600" /> @break
                                @default <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-brand-ink/20"></span>
                            @endswitch
                            <span @class(['truncate font-semibold', 'text-brand-ink' => $pst === 'running' || $pst === 'success', 'text-rose-800' => $pst === 'failed', 'text-brand-mist' => in_array($pst, ['pending', 'skipped'], true)])>{{ $phase['label'] }}</span>
                            @if ($pst === 'running')<span class="text-[10px] font-semibold text-amber-700">{{ __('running') }}</span>@endif
                        </div>
                        {{-- Per-step output, same auto-expand console as the Deploy tab. --}}
                        @if ($phase['steps'] !== [])
                            <ul class="mt-1.5 space-y-1.5 pl-5">
                                @foreach ($phase['steps'] as $step)
                                    @include('livewire.sites.partials.deployments._phase-timeline-step', [
                                        'step' => $step,
                                        'stepKeyBase' => $keyPrefix.'-step-'.$row['id'].'-'.($step['id'] ?? ($loop->parent->index.'-'.$loop->index)),
                                    ])
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($row['latest'] && $row['latest']->server_id)
                <a href="{{ route('sites.deployments.show', ['server' => $row['latest']->server_id, 'site' => $row['id'], 'deployment' => $row['latest']]) }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-forest hover:underline">
                    {{ __('Full log') }} <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
                </a>
            @endif
        @endif
    </div>
</div>
