<section class="space-y-6" aria-labelledby="manage-danger-title">
    @if (count($dangerousActions) > 0)
        <div class="{{ $card }} p-6 sm:p-8 border-red-200/50">
            <h2 id="manage-danger-title" class="text-lg font-semibold text-red-900">{{ __('Danger zone') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('These actions can disrupt production traffic or drop your SSH session.') }}
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                @foreach ($dangerousActions as $actionKey => $action)
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label'] ?? $actionKey), @js($action['confirm'] ?? __('Are you sure?')), @js($action['label'] ?? __('Run action')), true)"
                        @disabled(! $opsReady || $isDeployer)
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-red-900 hover:bg-red-100 transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" />
                        {{ $action['label'] ?? $actionKey }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($manageRemoteTaskId)
        <div class="{{ $card }} p-6 sm:p-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Stuck queued task') }}</h3>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('A task is currently queued for this server. If a worker died mid-flight or you no longer need the result, clear the queued reference here. The action does not interrupt anything running on the server itself — it just stops the UI from waiting.') }}
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                <button
                    type="button"
                    wire:click="cancelQueuedManageTasks"
                    @disabled($isDeployer)
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                    {{ __('Cancel queued task') }}
                </button>
            </div>
        </div>
    @endif

    <div class="{{ $card }} p-6 sm:p-8">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Detach or destroy this server') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Removal flows live on the Settings → Danger page. They confirm what will be deleted and cleanly tear down related resources.') }}
        </p>
        <p class="mt-3">
            <a
                href="{{ route('servers.settings', ['server' => $server, 'section' => 'danger']) }}"
                wire:navigate
                class="text-sm font-medium text-brand-ink underline decoration-brand-sage/40 underline-offset-2 hover:text-brand-sage"
            >{{ __('Open Settings → Danger') }}</a>
        </p>
    </div>
</section>
