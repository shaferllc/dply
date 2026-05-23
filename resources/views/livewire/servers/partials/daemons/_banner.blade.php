        @if ($supervisor_installed === null)
            <p class="mb-4 flex items-center gap-2 text-sm text-brand-moss">
                <x-spinner variant="forest" />
                {{ __('Checking Supervisor installation…') }}
            </p>
        @elseif ($supervisor_installed === false)
            <div class="mb-6 rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-4 sm:flex sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-amber-950">{{ __('Supervisor is not installed') }}</h2>
                    <p class="mt-1 text-sm text-amber-900/90">{{ __('This server does not have the Supervisor package yet (skipped provision step, older server, or install failed). Install it here before syncing program configs.') }}</p>
                </div>
                <button
                    type="button"
                    wire:click="installSupervisorPackage"
                    wire:loading.attr="disabled"
                    class="mt-4 inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-950 disabled:opacity-50 sm:mt-0"
                >
                    <span wire:loading.remove wire:target="installSupervisorPackage">{{ __('Install Supervisor') }}</span>
                    <span wire:loading wire:target="installSupervisorPackage" class="inline-flex items-center gap-2">
                        <x-spinner variant="white" />
                        {{ __('Installing…') }}
                    </span>
                </button>
            </div>
        @endif
