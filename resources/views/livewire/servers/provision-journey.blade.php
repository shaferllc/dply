@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $progressPercent = $totalCount > 0 ? (int) round(($completedCount / $totalCount) * 100) : 0;
@endphp

<div
    @if ($shouldPoll)
        wire:poll.5s
    @endif
    x-data
    x-init="
        (() => {
            // #region agent log
            fetch('http://127.0.0.1:7652/ingest/ff63025e-790d-4d37-ad99-1fc12ab824d9',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'182f08'},body:JSON.stringify({sessionId:'182f08',runId:'pre-fix',hypothesisId:'H2',location:'resources/views/livewire/servers/provision-journey.blade.php:6',message:'Journey page initialized',data:{serverId:@js((string) $server->id),shouldPoll:@js($shouldPoll),serverStatus:@js($server->status),setupStatus:@js($server->setup_status),href:window.location.href},timestamp:Date.now()})}).catch(()=>{});
            // #endregion

            if (!window.__dplyJourneyFetchDebug) {
                window.__dplyJourneyFetchDebug = true;
                const originalFetch = window.fetch.bind(window);
                window.fetch = (input, init) => {
                    const url = typeof input === 'string' ? input : input?.url;
                    const isLivewireUpdate = typeof url === 'string' && url.includes('/livewire') && url.includes('/update');

                    if (!isLivewireUpdate) {
                        return originalFetch(input, init);
                    }

                    // #region agent log
                    originalFetch('http://127.0.0.1:7652/ingest/ff63025e-790d-4d37-ad99-1fc12ab824d9',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'182f08'},body:JSON.stringify({sessionId:'182f08',runId:'pre-fix',hypothesisId:'N1',location:'resources/views/livewire/servers/provision-journey.blade.php:18',message:'Livewire update request started',data:{url,online:navigator.onLine,visibility:document.visibilityState,pathname:window.location.pathname},timestamp:Date.now()})}).catch(()=>{});
                    // #endregion

                    return originalFetch(input, init)
                        .then((response) => {
                            const responseClone = response.clone();
                            // #region agent log
                            originalFetch('http://127.0.0.1:7652/ingest/ff63025e-790d-4d37-ad99-1fc12ab824d9',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'182f08'},body:JSON.stringify({sessionId:'182f08',runId:'pre-fix',hypothesisId:'N2',location:'resources/views/livewire/servers/provision-journey.blade.php:24',message:'Livewire update request resolved',data:{url,status:response.status,ok:response.ok,redirected:response.redirected,type:response.type},timestamp:Date.now()})}).catch(()=>{});
                            // #endregion

                            responseClone.text().then((bodyText) => {
                                // #region agent log
                                originalFetch('http://127.0.0.1:7652/ingest/ff63025e-790d-4d37-ad99-1fc12ab824d9',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'182f08'},body:JSON.stringify({sessionId:'182f08',runId:'pre-fix',hypothesisId:'P1',location:'resources/views/livewire/servers/provision-journey.blade.php:28',message:'Livewire update response body captured',data:{url,bodyPreview:bodyText.slice(0,600)},timestamp:Date.now()})}).catch(()=>{});
                                // #endregion
                            }).catch((error) => {
                                // #region agent log
                                originalFetch('http://127.0.0.1:7652/ingest/ff63025e-790d-4d37-ad99-1fc12ab824d9',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'182f08'},body:JSON.stringify({sessionId:'182f08',runId:'pre-fix',hypothesisId:'P1',location:'resources/views/livewire/servers/provision-journey.blade.php:32',message:'Livewire update response body read failed',data:{url,name:error?.name ?? null,message:error?.message ?? null},timestamp:Date.now()})}).catch(()=>{});
                                // #endregion
                            });

                            return response;
                        })
                        .catch((error) => {
                            // #region agent log
                            originalFetch('http://127.0.0.1:7652/ingest/ff63025e-790d-4d37-ad99-1fc12ab824d9',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'182f08'},body:JSON.stringify({sessionId:'182f08',runId:'pre-fix',hypothesisId:'N3',location:'resources/views/livewire/servers/provision-journey.blade.php:31',message:'Livewire update request rejected',data:{url,name:error?.name ?? null,message:error?.message ?? null,online:navigator.onLine,visibility:document.visibilityState},timestamp:Date.now()})}).catch(()=>{});
                            // #endregion

                            throw error;
                        });
                };
            }

            if (!window.__dplyJourneyPageLifecycleDebug) {
                window.__dplyJourneyPageLifecycleDebug = true;
                ['offline', 'online', 'beforeunload', 'pagehide'].forEach((eventName) => {
                    window.addEventListener(eventName, () => {
                        // #region agent log
                        fetch('http://127.0.0.1:7652/ingest/ff63025e-790d-4d37-ad99-1fc12ab824d9',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'182f08'},body:JSON.stringify({sessionId:'182f08',runId:'pre-fix',hypothesisId:'N4',location:'resources/views/livewire/servers/provision-journey.blade.php:43',message:'Window lifecycle event fired',data:{eventName,online:navigator.onLine,visibility:document.visibilityState,pathname:window.location.pathname},timestamp:Date.now()})}).catch(()=>{});
                        // #endregion
                    });
                });
            }

            if (!window.__dplyJourneyUnhandledRejectionDebug) {
                window.__dplyJourneyUnhandledRejectionDebug = true;
                window.addEventListener('unhandledrejection', (event) => {
                    // #region agent log
                    fetch('http://127.0.0.1:7652/ingest/ff63025e-790d-4d37-ad99-1fc12ab824d9',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'182f08'},body:JSON.stringify({sessionId:'182f08',runId:'pre-fix',hypothesisId:'H4',location:'resources/views/livewire/servers/provision-journey.blade.php:14',message:'Window unhandledrejection on journey page',data:{pathname:window.location.pathname,reasonStatus:event.reason?.status ?? null,reasonBody:event.reason?.body ?? null,reasonErrors:event.reason?.errors ?? null,reasonKeys:event.reason ? Object.keys(event.reason) : [],reasonString:event.reason ? String(event.reason) : null},timestamp:Date.now()})}).catch(()=>{});
                    // #endregion
                });
            }
        })()
    "
>
    <x-server-workspace-layout
        :server="$server"
        active="overview"
        :title="__('Server creation')"
        :description="__('Track provisioning and setup until this server is ready.')"
        :show-navigation="false"
    >
        @include('livewire.servers.partials.workspace-flashes')

        <div class="grid gap-8 xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]">
            <section class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Provision journey') }}</p>
                        <h2 class="mt-2 text-2xl font-semibold text-brand-ink">{{ __('Installation tasks (:done/:total)', ['done' => $completedCount, 'total' => $totalCount]) }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">
                            {{ __('We will keep this page updated as Dply provisions your server and applies the selected stack.') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($canCancelProvision)
                            <button
                                type="button"
                                wire:click="openCancelProvisionModal"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 shadow-sm transition-colors hover:border-red-300 hover:bg-red-100"
                            >
                                <x-heroicon-o-x-circle class="h-4 w-4" />
                                {{ __('Cancel build') }}
                            </button>
                        @endif
                        @if (\App\Jobs\RunSetupScriptJob::shouldDispatch($server) && $server->setup_status !== \App\Models\Server::SETUP_STATUS_RUNNING)
                            <button
                                type="button"
                                wire:click="rerunSetup"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage"
                            >
                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                                {{ __('Resume install') }}
                            </button>
                        @endif
                        @if ($server->status === \App\Models\Server::STATUS_READY && ! in_array($server->setup_status, [\App\Models\Server::SETUP_STATUS_PENDING, \App\Models\Server::SETUP_STATUS_RUNNING], true))
                            <a
                                href="{{ route('servers.overview', $server) }}"
                                wire:navigate
                                class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                            >
                                {{ __('Open server workspace') }}
                            </a>
                        @endif
                    </div>
                </div>

                <div class="mt-6">
                    <div class="mb-3 flex items-center gap-3">
                        <span class="inline-flex min-w-14 items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">{{ $progressPercent }}%</span>
                        <span class="text-sm text-brand-moss">{{ __(':done of :total steps complete', ['done' => $completedCount, 'total' => $totalCount]) }}</span>
                    </div>
                    <div class="h-4 overflow-hidden rounded-full bg-brand-sand/60">
                        <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $progressPercent }}%"></div>
                    </div>
                </div>

                <div class="mt-8 space-y-5">
                    @if ($failedStep)
                        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-red-900">{{ $failedStep['label'] }}</p>
                                    @if ($failedStep['detail'])
                                        <p class="mt-1 text-sm leading-6 text-red-800">{{ $failedStep['detail'] }}</p>
                                    @endif
                                    @if ($failedStep['output'])
                                        <div class="mt-4 rounded-xl border border-red-200 bg-white/80 p-4">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-red-700">{{ __('Captured output') }}</p>
                                            <pre class="mt-2 whitespace-pre-wrap font-mono text-xs leading-6 text-red-900">{{ $failedStep['output'] }}</pre>
                                        </div>
                                    @endif
                                </div>
                                <span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-red-800">{{ __('Failed') }}</span>
                            </div>
                        </div>
                    @elseif ($activeStep)
                        <div class="rounded-2xl border border-blue-100 bg-blue-50/80 px-5 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full border-4 border-blue-200 border-t-blue-500"></span>
                                        <p class="text-lg font-semibold text-brand-ink">{{ $activeStep['label'] }}</p>
                                    </div>
                                    @if ($activeStep['detail'])
                                        <p class="mt-3 text-sm leading-6 text-brand-moss whitespace-pre-line">{{ $activeStep['detail'] }}</p>
                                    @endif
                                    @if ($activeStep['output'])
                                        <div class="mt-4 rounded-xl border border-blue-100 bg-white/80 p-4">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Live output') }}</p>
                                            <pre class="mt-2 whitespace-pre-wrap font-mono text-xs leading-6 text-brand-ink">{{ $activeStep['output'] }}</pre>
                                        </div>
                                    @endif
                                </div>
                                @if ($activeStep['duration'])
                                    <span class="shrink-0 text-sm font-medium text-brand-moss">{{ $activeStep['duration'] }}</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    <details class="rounded-2xl border border-brand-ink/10 bg-white" @if($pendingSteps->isNotEmpty()) open @endif>
                        <summary class="cursor-pointer list-none px-5 py-4 text-lg font-medium text-brand-ink">
                            <div class="flex items-center justify-between gap-4">
                                <span>{{ __('Pending tasks (:count)', ['count' => $pendingSteps->count()]) }}</span>
                                <x-heroicon-o-chevron-down class="h-5 w-5 text-brand-moss" />
                            </div>
                        </summary>
                        @if ($pendingSteps->isNotEmpty())
                            <div class="space-y-3 px-5 pb-5">
                                @foreach ($pendingSteps as $step)
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="flex items-center gap-3">
                                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border-2 border-brand-mist"></span>
                                                <div>
                                                    <p class="text-base font-medium text-brand-ink">{{ $step['label'] }}</p>
                                                    @if ($step['detail'])
                                                        <p class="mt-1 text-sm text-brand-moss">{{ $step['detail'] }}</p>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </details>

                    <details class="rounded-2xl border border-brand-ink/10 bg-white" @if($completedSteps->isNotEmpty()) open @endif>
                        <summary class="cursor-pointer list-none px-5 py-4 text-lg font-medium text-brand-ink">
                            <div class="flex items-center justify-between gap-4">
                                <span>{{ __('Completed tasks (:count)', ['count' => $completedSteps->count()]) }}</span>
                                <x-heroicon-o-chevron-up class="h-5 w-5 text-brand-moss" />
                            </div>
                        </summary>
                        @if ($completedSteps->isNotEmpty())
                            <div class="space-y-3 px-5 pb-5">
                                @foreach ($completedSteps as $step)
                                    <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 px-4 py-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="flex items-center gap-3">
                                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500 text-white">
                                                    <x-heroicon-o-check class="h-4 w-4" />
                                                </span>
                                                <div>
                                                    <p class="text-base font-medium text-brand-forest">{{ $step['label'] }}</p>
                                                    @if ($step['detail'])
                                                        <p class="mt-1 text-sm text-brand-moss whitespace-pre-line">{{ $step['detail'] }}</p>
                                                    @endif
                                                    @if ($step['output'])
                                                        <div class="mt-3 rounded-xl border border-emerald-100 bg-white/80 p-4">
                                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Captured output') }}</p>
                                                            <pre class="mt-2 whitespace-pre-wrap font-mono text-xs leading-6 text-brand-ink">{{ $step['output'] }}</pre>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                            @if ($step['duration'])
                                                <span class="shrink-0 text-sm font-medium text-brand-moss">{{ $step['duration'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </details>
                </div>
            </section>

            <aside class="space-y-6">
                <section class="{{ $card }} p-6">
                    <h3 class="text-lg font-semibold text-brand-ink">{{ __('Server details') }}</h3>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="text-brand-moss">{{ __('Status') }}</dt>
                            <dd class="mt-1 font-medium text-brand-ink">{{ ucfirst($server->status) }}</dd>
                        </div>
                        <div>
                            <dt class="text-brand-moss">{{ __('Provider') }}</dt>
                            <dd class="mt-1 font-medium text-brand-ink">{{ $server->provider->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-brand-moss">{{ __('Region') }}</dt>
                            <dd class="mt-1 font-medium text-brand-ink">{{ $server->region ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-brand-moss">{{ __('Size') }}</dt>
                            <dd class="mt-1 font-medium text-brand-ink">{{ $server->size ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-brand-moss">{{ __('IP address') }}</dt>
                            <dd class="mt-1 font-mono font-medium text-brand-ink">{{ $server->ip_address ?: '—' }}</dd>
                        </div>
                        @if ($server->setup_status)
                            <div>
                                <dt class="text-brand-moss">{{ __('Setup status') }}</dt>
                                <dd class="mt-1 font-medium text-brand-ink">{{ ucfirst($server->setup_status) }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>

                @if ($task)
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Setup task') }}</h3>
                        <div class="mt-4 space-y-3 text-sm">
                            <p class="text-brand-moss">{{ __('Status') }}: <span class="font-medium text-brand-ink">{{ ucfirst($task->status->value) }}</span></p>
                            @if ($task->started_at)
                                <p class="text-brand-moss">{{ __('Started') }}: <span class="font-medium text-brand-ink">{{ $task->started_at->diffForHumans() }}</span></p>
                            @endif
                            <div class="min-w-0 rounded-xl bg-brand-sand/20 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Recent output') }}</p>
                                <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all font-mono text-xs text-brand-ink">{{ $task->tailOutput(6) ?: __('No task output yet.') }}</pre>
                            </div>
                        </div>
                    </section>
                @endif

                @if ($run)
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Provision run') }}</h3>
                        <div class="mt-4 space-y-3 text-sm">
                            <p class="text-brand-moss">{{ __('Attempt') }}: <span class="font-medium text-brand-ink">#{{ $run->attempt }}</span></p>
                            <p class="text-brand-moss">{{ __('Run status') }}: <span class="font-medium text-brand-ink">{{ ucfirst($run->status) }}</span></p>
                            @if ($run->rollback_status)
                                <p class="text-brand-moss">{{ __('Rollback') }}: <span class="font-medium text-brand-ink">{{ str_replace('_', ' ', ucfirst($run->rollback_status)) }}</span></p>
                            @endif
                            @if ($run->summary)
                                <p class="rounded-xl bg-brand-sand/20 p-4 text-brand-ink">{{ $run->summary }}</p>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($artifacts->isNotEmpty())
                    <section class="{{ $card }} p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Provision artifacts') }}</h3>
                        <div class="mt-4 space-y-4">
                            @foreach ($artifacts as $artifact)
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $artifact->label }}</p>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ str_replace('_', ' ', $artifact->type) }}</span>
                                    </div>
                                    @if ($artifact->content)
                                        <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap break-all font-mono text-xs text-brand-ink">{{ $artifact->content }}</pre>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </aside>
        </div>

        <x-slot name="modals">
            @if ($showCancelProvisionModal)
                <div
                    class="fixed inset-0 z-50 overflow-y-auto"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="cancel-provision-title"
                >
                    <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeCancelProvisionModal"></div>
                    <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10">
                        <div class="w-full max-w-xl rounded-2xl border border-brand-ink/10 bg-white shadow-xl" @click.stop>
                            <div class="border-b border-zinc-100 px-6 py-6 sm:px-8 sm:py-7">
                                <h2 id="cancel-provision-title" class="text-lg font-semibold text-brand-ink">{{ __('Cancel server build') }}</h2>
                                <p class="mt-3 text-sm leading-relaxed text-brand-moss">
                                    {{ __('Stop the current server build if it is still running. You can keep the server for another attempt, or continue into the remove flow if you want to delete the server entirely.') }}
                                </p>
                            </div>
                            <div class="space-y-5 px-6 py-7 sm:px-8 sm:py-8">
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-5 text-sm text-brand-moss">
                                    <p class="font-medium text-brand-ink">{{ __('Current status') }}</p>
                                    <p class="mt-2">{{ __('Server') }}: <span class="font-medium text-brand-ink">{{ ucfirst($server->status) }}</span></p>
                                    <p class="mt-1">{{ __('Setup') }}: <span class="font-medium text-brand-ink">{{ ucfirst($server->setup_status) }}</span></p>
                                    @if ($task)
                                        <p class="mt-1">{{ __('Task') }}: <span class="font-medium text-brand-ink">{{ ucfirst($task->status->value) }}</span></p>
                                    @endif
                                </div>

                                @if ($task && $task->status->isActive())
                                    <button
                                        type="button"
                                        wire:click="cancelProvision"
                                        class="flex w-full items-start justify-between rounded-2xl border border-brand-ink/10 bg-white px-5 py-4 text-left shadow-sm transition-colors hover:border-brand-sage hover:bg-brand-sand/20"
                                    >
                                        <span>
                                            <span class="block text-sm font-semibold text-brand-ink">{{ __('Cancel build and keep server') }}</span>
                                            <span class="mt-1 block text-sm text-brand-moss">{{ __('Stop the active provisioning task and leave the server in place so you can inspect it or rerun setup later.') }}</span>
                                        </span>
                                        <x-heroicon-o-pause-circle class="mt-0.5 h-5 w-5 shrink-0 text-brand-ink" />
                                    </button>
                                @endif

                                @can('delete', $server)
                                    <button
                                        type="button"
                                        wire:click="cancelProvisionAndOpenDelete"
                                        class="flex w-full items-start justify-between rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-left shadow-sm transition-colors hover:border-red-300 hover:bg-red-100"
                                    >
                                        <span>
                                            <span class="block text-sm font-semibold text-red-900">{{ __('Cancel build and remove server') }}</span>
                                            <span class="mt-1 block text-sm text-red-800">{{ __('Stop the build first, then continue into the existing removal confirmation flow to delete the server and any linked provider resource.') }}</span>
                                        </span>
                                        <x-heroicon-o-trash class="mt-0.5 h-5 w-5 shrink-0 text-red-800" />
                                    </button>
                                @endcan
                            </div>
                            <div class="flex justify-end border-t border-zinc-100 bg-zinc-50/80 px-6 py-5 sm:px-8 sm:py-6">
                                <button type="button" wire:click="closeCancelProvisionModal" class="inline-flex justify-center rounded-xl border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-brand-ink hover:bg-zinc-50 sm:px-6">
                                    {{ __('Close') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @include('livewire.servers.partials.remove-server-modal', [
                'open' => $showRemoveServerModal,
                'serverName' => $server->name,
                'serverId' => $server->id,
                'deletionSummary' => $deletionSummary,
            ])
        </x-slot>
    </x-server-workspace-layout>
</div>
