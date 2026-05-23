@php
    $busyEngineLabel = $engineLabels[$busyService->engine] ?? ucfirst($busyService->engine);
    $busyMessage = match ($busyService->status) {
        \App\Models\ServerCacheService::STATUS_PENDING => __('Queued — :engine install will start shortly…', ['engine' => $busyEngineLabel]),
        \App\Models\ServerCacheService::STATUS_INSTALLING => __('Installing :engine on the server…', ['engine' => $busyEngineLabel]),
        \App\Models\ServerCacheService::STATUS_UNINSTALLING => __('Uninstalling :engine from the server…', ['engine' => $busyEngineLabel]),
        default => __('Working on :engine…', ['engine' => $busyEngineLabel]),
    };
    $busyOutput = (string) ($busyService->install_output ?? '');
@endphp
{{-- Polling element only mounts while a job is in flight. The moment status leaves the
     in-flight set, this disappears and polling stops. --}}
<div wire:poll.4s class="hidden" aria-hidden="true"></div>
<div class="mb-4 overflow-hidden rounded-xl border border-sky-200 bg-sky-50/80 text-sm text-sky-900 shadow-sm" role="status" aria-live="polite" x-data="{ expanded: false }">
    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/70 ring-1 ring-sky-200">
                <x-spinner variant="forest" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold leading-tight">{{ $busyMessage }}</p>
                <p class="mt-0.5 truncate text-xs text-sky-700/80">{{ __('Refreshing every 4s · safe to leave this page — the job runs on the queue. Other engines stay paused while apt runs.') }}</p>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
            @if ($busyService->status === \App\Models\ServerCacheService::STATUS_PENDING)
                <button
                    type="button"
                    wire:click="cancelCacheServiceChange('{{ $busyService->engine }}')"
                    wire:loading.attr="disabled"
                    wire:target="cancelCacheServiceChange"
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-rose-300/70 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-700 shadow-sm hover:bg-rose-50 disabled:opacity-50"
                    title="{{ __('The job has not started apt yet — safe to cancel.') }}"
                >
                    <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                    <span wire:loading.remove wire:target="cancelCacheServiceChange">{{ __('Cancel') }}</span>
                    <span wire:loading wire:target="cancelCacheServiceChange">{{ __('Cancelling…') }}</span>
                </button>
            @elseif ($busyService->status === \App\Models\ServerCacheService::STATUS_INSTALLING && $busyService->cancel_requested_at === null)
                @php
                    $hasOtherInstancesOfBusyEngine = \App\Models\ServerCacheService::query()
                        ->where('server_id', $this->server->id)
                        ->where('engine', $busyService->engine)
                        ->where('id', '!=', $busyService->id)
                        ->exists();
                    $cancelMessage = $hasOtherInstancesOfBusyEngine
                        ? __('Stops the install at the next output chunk, removes this instance\'s systemd unit and config, and deletes the row. The :engine package stays installed because another instance is still using it — uninstall the other instance(s) first if you want it gone.', ['engine' => $busyService->engine])
                        : __('Stops the install at the next output chunk, runs dpkg --configure -a + apt purge to clean up whatever apt left behind, and removes the row. Apt-purge takes a minute or two.');
                @endphp
                <button
                    type="button"
                    wire:click="openConfirmActionModal('cancelCacheServiceChange', ['{{ $busyService->engine }}'], @js(__('Stop install and revert?')), @js($cancelMessage), @js(__('Stop and revert')), true)"
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-rose-300/70 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-700 shadow-sm hover:bg-rose-50"
                    title="{{ __('Stop the install and revert this instance.') }}"
                >
                    <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                    {{ __('Stop & revert') }}
                </button>
            @elseif (($busyService->status === \App\Models\ServerCacheService::STATUS_INSTALLING && $busyService->cancel_requested_at !== null) || $busyService->status === \App\Models\ServerCacheService::STATUS_UNINSTALLING)
                @php
                    $stalenessRef = $busyService->cancel_requested_at ?? $busyService->updated_at;
                    $cancelStale = $stalenessRef !== null
                        && $stalenessRef->diffInSeconds(now()) >= 60;
                    $forceConfirmCopy = __(
                        'This operation hasn\'t made progress for over a minute. Force remove deletes the row outright so you can start fresh — server-side state may be partial. Run apt purge / systemctl disable manually if anything was already installed. Continue?'
                    );
                    $busyPillCopy = $busyService->status === \App\Models\ServerCacheService::STATUS_UNINSTALLING
                        ? __('Uninstalling…')
                        : __('Cancelling — reverting…');
                @endphp
                <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-rose-300/70 bg-rose-50 px-2.5 py-1.5 text-xs font-medium text-rose-700">
                    <x-spinner variant="forest" />
                    {{ $busyPillCopy }}
                </span>
                @if ($cancelStale)
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('forceCancelCacheServiceChange', ['{{ $busyService->engine }}'], @js(__('Force remove row?')), @js($forceConfirmCopy), @js(__('Force remove')), true)"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-rose-400 bg-rose-100 px-2.5 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-200"
                        title="{{ __('No progress observed for over 60s — remove the row and clean up the server manually.') }}"
                    >
                        <x-heroicon-o-trash class="h-3.5 w-3.5" />
                        {{ __('Force remove') }}
                    </button>
                @endif
            @endif
            <button
                type="button"
                x-on:click="expanded = !expanded"
                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-sky-200 bg-white px-2.5 py-1.5 text-xs font-medium text-sky-900 shadow-sm hover:bg-sky-50"
                x-bind:aria-expanded="expanded.toString()"
            >
                <x-heroicon-o-chevron-down class="h-3.5 w-3.5 transition-transform" x-bind:class="expanded ? 'rotate-180' : ''" />
                <span x-text="expanded ? @js(__('Hide output')) : @js(__('View output'))"></span>
            </button>
        </div>
    </div>
    <div x-show="expanded" x-cloak class="border-t border-sky-200 bg-white/70 px-4 py-3">
        @if (trim($busyOutput) === '')
            <p class="text-xs text-sky-800/80">{{ __('No output yet — the worker may still be picking up the job. This refreshes every 4s.') }}</p>
        @else
            <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100" x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">{{ $busyOutput }}</pre>
        @endif
    </div>
</div>
