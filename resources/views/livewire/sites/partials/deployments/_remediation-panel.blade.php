@php
    /**
     * "dply recognized this failure" Fix panel for a failed deployment. Host
     * uses {@see \App\Livewire\Sites\Concerns\SurfacesDeploymentRemediation}.
     *
     * @var \App\Models\SiteDeployment $deployment
     */
    $remediation = $this->remediationForDeployment($deployment);
    $run = $this->deploymentRemediationRun;
    $succeeded = $run && $run->status === 'completed';
@endphp
@if ($remediation)
    <section @class([
        'mb-6 overflow-hidden rounded-2xl border',
        'border-emerald-200 bg-emerald-50/60' => $succeeded,
        'border-amber-200 bg-amber-50/60' => ! $succeeded,
    ])>
        <div class="flex items-start gap-3 px-6 py-5 sm:px-7">
            @if ($succeeded)
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 ring-1 ring-emerald-600/20">
                    <x-heroicon-o-check-circle class="h-5 w-5" aria-hidden="true" />
                </span>
            @else
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 ring-1 ring-amber-600/20">
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                </span>
            @endif
            <div class="min-w-0 flex-1">
                @if ($succeeded)
                    {{-- Success state: the fix applied — point the operator at re-deploy. --}}
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-700">{{ __('Fix applied') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $remediation['title'] }} — {{ __('resolved') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('The fix ran successfully on :server. Re-deploy to continue where the deployment left off.', ['server' => $server->name]) }}</p>
                    <div class="mt-4">
                        @if (method_exists($this, 'deployNow'))
                            {{-- On the deploy hub: trigger the same deploy the primary button does. --}}
                            <button type="button" wire:click="deployNow" wire:loading.attr="disabled" wire:target="deployNow"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60">
                                <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                                {{ __('Re-deploy') }}
                            </button>
                        @else
                            {{-- On the read-only permalink: jump to the deploy hub to re-run. --}}
                            <a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site]) }}" wire:navigate
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                                <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                                {{ __('Re-deploy') }}
                            </a>
                        @endif
                    </div>
                @else
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('dply recognized this failure') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $remediation['title'] }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $remediation['explanation'] }}</p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($remediation['actions'] as $action)
                            <button
                                type="button"
                                wire:click="applyDeploymentRemediation('{{ $deployment->id }}', '{{ $action['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="applyDeploymentRemediation('{{ $deployment->id }}', '{{ $action['key'] }}')"
                                @class([
                                    'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold shadow-sm transition disabled:opacity-60',
                                    'bg-brand-ink text-brand-cream hover:bg-brand-forest' => ! empty($action['recommended']),
                                    'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => empty($action['recommended']),
                                ])
                            >
                                <x-heroicon-o-wrench class="h-4 w-4" aria-hidden="true" />
                                {{ $action['label'] }}
                                @if (! empty($action['recommended']))
                                    <span class="rounded-full bg-brand-cream/20 px-1.5 py-0.5 text-[10px] uppercase tracking-wide">{{ __('Recommended') }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[11px] text-brand-mist">{{ __('Runs over SSH on :server. After it succeeds, re-deploy to continue.', ['server' => $server->name]) }}</p>
                @endif
            </div>
        </div>

        {{-- The run's live/finished log (collapsed transcript via the banner). --}}
        @if ($run)
            <div @class([
                'border-t px-6 py-4 sm:px-7',
                'border-emerald-200/70' => $succeeded,
                'border-amber-200/70' => ! $succeeded,
            ])>
                @include('livewire.partials.console-action-banner-static', [
                    'run' => $run,
                    'kindLabels' => (array) config('console_actions.kinds', []),
                ])
            </div>
        @endif
    </section>
@endif
