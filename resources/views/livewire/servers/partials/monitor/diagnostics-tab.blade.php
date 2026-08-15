{{-- Nested inside the merged Metrics card — no second outer card/header. --}}
<div>
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-wrench-screwdriver"
        :title="__('Diagnostics & repair')"
        :note="__('Inspect the agent and re-deploy callback wiring when samples stop arriving.')"
    />

    <div class="px-3 py-2.5 sm:px-4">
        @if ($isProductionMirror)
            {{-- Repair/inspect open an SSH session in-request. Unlike probe,
                 install and thresholds there is no remote verb to proxy them
                 to, so they are pointed at production rather than offered here
                 as buttons that could only fail. --}}
            <div class="rounded-lg border border-amber-200/80 bg-amber-50/80 p-3 text-xs text-amber-950">
                <p class="font-semibold">{{ __('Repair tools run where the SSH key lives.') }}</p>
                <p class="mt-1">{{ __('This is a mirror of a production host — inspecting the agent and re-deploying its wiring have to run from the control plane that owns it.') }}</p>
                <a
                    href="{{ rtrim($productionMirrorBaseUrl ?? '', '/') }}/servers/{{ $server->id }}/monitor"
                    target="_blank"
                    rel="noopener"
                    class="mt-2 inline-flex h-6 items-center gap-1 rounded-md border border-amber-300/80 bg-white px-2 font-semibold text-amber-900 shadow-sm hover:bg-amber-100"
                >
                    {{ __('Open Diagnostics on production') }}
                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                </a>
            </div>
        @elseif ($isDeployer)
            <div class="rounded-lg border border-amber-200/80 bg-amber-50/80 p-3 text-xs text-amber-950">
                {{ __('Your role cannot run repairs or diagnostics. Ask an admin to open this Metrics page if the monitor needs attention.') }}
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Repair monitor wiring') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                        {{ __('Re-deploys the agent script, callback env, and cron over SSH. Use when samples have stopped arriving but SSH still works.') }}
                    </p>
                    <x-primary-button size="sm" type="button" wire:click="repairMonitorNow" wire:loading.attr="disabled" wire:target="repairMonitorNow" class="mt-3">
                        <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="repairMonitorNow" aria-hidden="true" />
                        <span wire:loading.remove wire:target="repairMonitorNow">{{ __('Repair monitor now') }}</span>
                        <span wire:loading wire:target="repairMonitorNow">{{ __('Repairing…') }}</span>
                    </x-primary-button>
                </div>

                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Run callback diagnostics') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                        {{ __('Runs the snapshot script locally and probes the callback URL from the host. Useful when repair finishes but samples still don\'t arrive.') }}
                    </p>
                    <x-secondary-button size="sm" type="button" wire:click="runMonitorCallbackDiagnostics" wire:loading.attr="disabled" wire:target="runMonitorCallbackDiagnostics" class="mt-3">
                        <x-heroicon-o-bug-ant class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="runMonitorCallbackDiagnostics" aria-hidden="true" />
                        <span wire:loading.remove wire:target="runMonitorCallbackDiagnostics">{{ __('Run callback diagnostics') }}</span>
                        <span wire:loading wire:target="runMonitorCallbackDiagnostics">{{ __('Running…') }}</span>
                    </x-secondary-button>
                </div>

                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Inspect callback env') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                        {{ __('Prints the agent\'s metrics-callback.env file with the token redacted. Verifies the URL the agent is POSTing to.') }}
                    </p>
                    <x-secondary-button size="sm" type="button" wire:click="inspectMetricsCallbackEnv" wire:loading.attr="disabled" wire:target="inspectMetricsCallbackEnv" class="mt-3">
                        <x-heroicon-o-document-magnifying-glass class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="inspectMetricsCallbackEnv" aria-hidden="true" />
                        <span wire:loading.remove wire:target="inspectMetricsCallbackEnv">{{ __('Inspect callback env') }}</span>
                        <span wire:loading wire:target="inspectMetricsCallbackEnv">{{ __('Inspecting…') }}</span>
                    </x-secondary-button>
                </div>

                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Re-verify guest push') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                        {{ __('Re-reads the script SHA, env, and cron from the host and queues repair jobs for anything missing.') }}
                    </p>
                    <x-secondary-button size="sm" type="button" wire:click="verifyGuestPush" wire:loading.attr="disabled" wire:target="verifyGuestPush" class="mt-3">
                        <x-heroicon-o-shield-check class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="verifyGuestPush" aria-hidden="true" />
                        <span wire:loading.remove wire:target="verifyGuestPush">{{ __('Re-verify guest push') }}</span>
                        <span wire:loading wire:target="verifyGuestPush">{{ __('Verifying…') }}</span>
                    </x-secondary-button>
                </div>
            </div>

            @if ($probeAt || $guestPushCronExpression)
                <div class="mt-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
                    @if ($probeAt)
                        <p>{{ __('Last SSH/Python probe') }}: <span class="font-mono text-brand-ink">{{ $probeAt->format('Y-m-d H:i:s T') }}</span></p>
                    @endif
                    @if ($guestPushCronExpression)
                        <p class="mt-1">{{ __('Push cron') }}: <span class="font-mono text-brand-ink">{{ $guestPushCronExpression }}</span></p>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
