@php
    /** @var bool $asStrip When true, nest as a hairline strip inside a parent dply-card. */
    $asStrip = (bool) ($asStrip ?? false);
    $rowPad = $asStrip ? 'px-3 py-2 sm:px-4' : 'px-5 py-2.5 sm:px-6';
    $detailPad = $asStrip ? 'px-3 py-2.5 sm:px-4' : 'px-5 py-3 sm:px-6';
@endphp
<section @class([$asStrip ? 'border-b border-brand-ink/10' : 'dply-card overflow-hidden'])>
    <x-workspace-panel-head
        :dense="$asStrip"
        class="border-b border-brand-ink/10"
        icon="heroicon-o-rocket-launch"
        :title="__('Recent deployments')"
        :note="__('Per-phase build → swap → release → restart. Expand a row for step detail.')"
        :count="trans_choice('{1} 1 with phase data|[2,*] :count with phase data', $deployments->count(), ['count' => $deployments->count()])"
    >
        <x-slot:actions>
            <a href="{{ route('sites.deployments.index', ['server' => $site->server, 'site' => $site]) }}" wire:navigate
                class="text-[11px] font-semibold text-brand-forest hover:text-brand-sage hover:underline">
                {{ __('View all →') }}
            </a>
        </x-slot:actions>
    </x-workspace-panel-head>

    <ul class="divide-y divide-brand-ink/10">
        @foreach ($deployments as $deployment)
            @php
                $status = (string) $deployment->status;
                $billingBlocked = $deployment->isBillingBlocked();
                $statusLabel = $billingBlocked ? __('blocked — billing') : $status;
                [$dotClasses, $glyph] = match (true) {
                    $billingBlocked => ['bg-amber-100 text-amber-700', '$'],
                    $status === 'success' => ['bg-emerald-100 text-emerald-700', '✓'],
                    $status === 'failed' => ['bg-rose-100 text-rose-700', '✕'],
                    $status === 'running' => ['bg-amber-100 text-amber-700', '○'],
                    $status === 'skipped' => ['bg-slate-100 text-slate-500', '·'],
                    default => ['bg-brand-sand/60 text-brand-moss', '•'],
                };
                $statusLabelClasses = match (true) {
                    $billingBlocked => 'text-amber-700',
                    $status === 'success' => 'text-emerald-700',
                    $status === 'failed' => 'text-rose-700',
                    $status === 'running' => 'text-amber-700',
                    default => 'text-brand-moss',
                };
                $durationMs = $deployment->phaseTotalDurationMs();
                $shortSha = $deployment->git_sha ? substr((string) $deployment->git_sha, 0, 7) : null;
            @endphp
            <li class="relative">
                {{-- The deployment-detail link is a sibling overlay, NOT nested in
                     the summary: a summary element is itself a button, so an anchor
                     or button inside it nests interactive controls (the "interactive
                     element within a summary" a11y warning) and is inconsistently
                     reachable by keyboard/AT. Positioned over the row's right edge. --}}
                <a href="{{ route('sites.deployments.show', ['server' => $site->server, 'site' => $site, 'deployment' => $deployment]) }}" wire:navigate
                    class="absolute right-10 top-2 z-10 hidden shrink-0 rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] text-brand-mist hover:bg-brand-sand/70 hover:text-brand-moss sm:right-12 sm:inline-block"
                    title="{{ __('Open deployment detail') }}">{{ \Illuminate\Support\Str::limit((string) $deployment->id, 12, '…') }}</a>
                <details class="group">
                    <summary class="flex cursor-pointer list-none items-center gap-2 {{ $rowPad }} transition-colors hover:bg-brand-sand/15 [&::-webkit-details-marker]:hidden">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $dotClasses }} {{ $status === 'running' ? 'animate-pulse' : '' }}" aria-hidden="true">{{ $glyph }}</span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs">
                                <span class="text-[10px] font-semibold uppercase tracking-[0.12em] {{ $statusLabelClasses }}">{{ $statusLabel }}</span>
                                <span class="text-brand-moss">{{ $deployment->started_at?->diffForHumans() ?? '—' }}</span>
                                @if ($deployment->trigger)
                                    <span class="text-brand-mist">·</span>
                                    <span class="text-brand-moss">{{ $deployment->trigger }}</span>
                                @endif
                                @if ($durationMs > 0)
                                    <span class="text-brand-mist">·</span>
                                    <span class="font-mono text-[11px] text-brand-moss">{{ number_format($durationMs / 1000, 1) }}s</span>
                                @endif
                                @if ($shortSha)
                                    <span class="text-brand-mist">·</span>
                                    <span class="inline-flex items-center gap-1 font-mono text-[11px] text-brand-moss">
                                        <x-heroicon-o-code-bracket class="h-3 w-3" aria-hidden="true" />{{ $shortSha }}
                                    </span>
                                @endif
                            </div>
                            <div class="mt-0.5 flex flex-wrap gap-1">
                                @foreach (['clone', 'build', 'swap', 'activate', 'release', 'restart', 'serverless'] as $phase)
                                    @if ($deployment->hasPhase($phase) && $deployment->phaseSteps($phase) !== [])
                                        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.1em] {{ $deployment->phaseOk($phase) ? 'bg-brand-sage/12 text-brand-forest' : 'bg-rose-50 text-rose-700' }}">
                                            {{ $phase }}
                                            <span class="font-mono opacity-70">{{ count($deployment->phaseSteps($phase)) }}</span>
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>

                        {{-- spacer so the overlaid id-chip link (above) doesn't collide with the chevron --}}
                        <span class="hidden shrink-0 sm:block sm:w-16" aria-hidden="true"></span>

                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition-transform group-open:rotate-90" aria-hidden="true" />
                    </summary>

                    <div class="space-y-2 border-t border-brand-ink/10 bg-brand-sand/10 {{ $detailPad }}">
                        @foreach (['clone', 'build', 'swap', 'activate', 'release', 'restart', 'serverless'] as $phase)
                            @if ($deployment->hasPhase($phase) && $deployment->phaseSteps($phase) !== [])
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $phase }}</p>
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($deployment->phaseSteps($phase) as $step)
                                            @include('livewire.sites.partials.recent-deployment-step', ['step' => $step])
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                        <x-cli-snippet class="text-[10px]" :command="'dply sites:deployment '.$deployment->id.' --output'" />
                    </div>
                </details>
            </li>
        @endforeach
    </ul>
</section>
