<div class="{{ $card }} p-6 sm:p-8">
    <div class="flex min-w-0 items-start gap-3">
        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
            <x-heroicon-o-wrench-screwdriver class="h-5 w-5" />
        </span>
        <div class="min-w-0 flex-1">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Diagnostics & repair') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                {{ __('Inspect what the agent is doing on the host and re-deploy its callback wiring when samples stop arriving. Output streams under the page header.') }}
            </p>
        </div>
    </div>

    @if ($isDeployer)
        <div class="mt-6 rounded-xl border border-amber-200/80 bg-amber-50/80 p-4 text-sm text-amber-950">
            {{ __('Your role cannot run repairs or diagnostics. Ask an admin to open this Metrics page if the monitor needs attention.') }}
        </div>
    @else
        <div class="mt-6 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Repair monitor wiring') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                    {{ __('Re-deploys the agent script, callback env, and cron over SSH. Use when samples have stopped arriving but SSH still works.') }}
                </p>
                <button type="button" wire:click="repairMonitorNow" wire:loading.attr="disabled" wire:target="repairMonitorNow" class="{{ $btnPrimary }} mt-4">
                    <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="repairMonitorNow" aria-hidden="true" />
                    <span wire:loading.remove wire:target="repairMonitorNow">{{ __('Repair monitor now') }}</span>
                    <span wire:loading wire:target="repairMonitorNow">{{ __('Repairing…') }}</span>
                </button>
            </div>

            <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Run callback diagnostics') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                    {{ __('Runs the snapshot script locally and probes the callback URL from the host. Useful when repair finishes but samples still don\'t arrive.') }}
                </p>
                <button type="button" wire:click="runMonitorCallbackDiagnostics" wire:loading.attr="disabled" wire:target="runMonitorCallbackDiagnostics" class="{{ $btnSecondary }} mt-4">
                    <x-heroicon-o-bug-ant class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="runMonitorCallbackDiagnostics" aria-hidden="true" />
                    <span wire:loading.remove wire:target="runMonitorCallbackDiagnostics">{{ __('Run callback diagnostics') }}</span>
                    <span wire:loading wire:target="runMonitorCallbackDiagnostics">{{ __('Running…') }}</span>
                </button>
            </div>

            <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Inspect callback env') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                    {{ __('Prints the agent\'s metrics-callback.env file with the token redacted. Verifies the URL the agent is POSTing to.') }}
                </p>
                <button type="button" wire:click="inspectMetricsCallbackEnv" wire:loading.attr="disabled" wire:target="inspectMetricsCallbackEnv" class="{{ $btnSecondary }} mt-4">
                    <x-heroicon-o-document-magnifying-glass class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="inspectMetricsCallbackEnv" aria-hidden="true" />
                    <span wire:loading.remove wire:target="inspectMetricsCallbackEnv">{{ __('Inspect callback env') }}</span>
                    <span wire:loading wire:target="inspectMetricsCallbackEnv">{{ __('Inspecting…') }}</span>
                </button>
            </div>

            <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Re-verify guest push') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                    {{ __('Re-reads the script SHA, env, and cron from the host and queues repair jobs for anything missing.') }}
                </p>
                <button type="button" wire:click="verifyGuestPush" wire:loading.attr="disabled" wire:target="verifyGuestPush" class="{{ $btnSecondary }} mt-4">
                    <x-heroicon-o-shield-check class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="verifyGuestPush" aria-hidden="true" />
                    <span wire:loading.remove wire:target="verifyGuestPush">{{ __('Re-verify guest push') }}</span>
                    <span wire:loading wire:target="verifyGuestPush">{{ __('Verifying…') }}</span>
                </button>
            </div>
        </div>

        @if ($probeAt || $guestPushCronExpression)
            <div class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
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
