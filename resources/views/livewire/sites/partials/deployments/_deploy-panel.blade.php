@php
    $latest = $latestDeployment ?? null;
    $isRunning = $latest && $latest->status === 'running';
    // A deploy is "in progress" while the latest run is running, or the deploy
    // lock is held AND no terminal run has landed since it was taken. Driving
    // the button off raw lock presence kept it spinning "Deploying…" for the
    // full 600s lock TTL after a deploy finished — a self-deploy can kill the
    // worker that runs the lock-cleanup `finally` before it clears the marker.
    // The trait helper stops as soon as this run lands terminal. (Guarded so a
    // partial rendered by a component without the trait still falls back.)
    $deployInProgress = method_exists($this, 'deployIsInProgress')
        ? $this->deployIsInProgress($latest)
        : ($isRunning || (bool) ($this->deployLockInfo ?? null));
    // "Deployed commit" must reflect the code actually live — the last SUCCESSFUL
    // deploy — not the latest attempt (which may be skipped/failed with no SHA,
    // the source of the confusing "No deploys yet" when deploys clearly exist).
    $deployedDeployment = ($latest && $latest->status === 'success')
        ? $latest
        : $site->deployments()->where('status', 'success')->latest()->first();
    $deployedSha = $deployedDeployment?->git_sha;
    $shortSha = $deployedSha ? \Illuminate\Support\Str::limit($deployedSha, 7, '') : null;
    // Detail-modal data for the deployed commit badge.
    $commitWebUrl = $deployedSha ? $site->commitWebUrl($deployedSha) : null;
    $deployedBranch = trim((string) ($site->git_branch ?? ''));
    $deployedDurationMs = $deployedDeployment ? $deployedDeployment->phaseTotalDurationMs() : 0;
    $totalDurationMs = $latest ? $latest->phaseTotalDurationMs() : 0;
    // Phase timeline derived from the site's pipeline (Clone → Build →
    // Release → Activate) overlaid with this deployment's recorded steps.
    $timelinePhases = \App\Support\Sites\SiteDeployTimeline::forDeployment($site, $latest);

    // Env vars the last deploy was blocked on (recorded by the deploy job's
    // preflight). Non-empty → the deploy stopped early asking for these.
    $blockedEnv = $this->deployBlockedEnvKeys();
@endphp

<div class="space-y-6" @if ($deployInProgress) wire:poll.5s @endif>
    {{-- While a queued console action is being watched (e.g. "Optimize pipeline"
         scanning the repo), poll so the deploy hub re-renders on completion: the
         success toast fires and the proposed-changes preview modal below auto-opens
         off the freshly-written meta. Without this the scan finishes silently and
         the Optimize button looks like it did nothing. --}}
    @if ($watchedConsoleRunId)
        <div wire:poll.3s="resolveWatchedConsoleAction" class="hidden" aria-hidden="true"></div>
    @endif
    @if ($latest && $latest->status === 'failed')
        @include('livewire.sites.partials.deployments._remediation-panel', ['deployment' => $latest])
    @endif

    {{-- Resume-from-phase: the deploy failed AFTER staging a release but BEFORE
         cutover (a build step or a migration broke), so the prior release is
         still live and the staged release is intact on disk. Offer to re-run
         from the failed phase — reusing the clone (and, past build, the built
         vendor/) — instead of a full deploy from scratch. Atomic only. --}}
    @if ($latest && $latest->status === 'failed' && $site->isAtomicDeploys() && $latest->isResumable() && method_exists($this, 'confirmResumeDeployment'))
        @php $resumePhase = $latest->resumeStartPhase(); @endphp
        <div class="mb-6 overflow-hidden rounded-2xl border border-sky-200 bg-sky-50/60">
            <div class="flex items-start gap-3 px-6 py-5 sm:px-7">
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 ring-1 ring-sky-600/20">
                    <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-sky-700">{{ __('Resume available') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Retry from the :phase phase', ['phase' => $resumePhase]) }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        @if ($resumePhase === 'restart')
                            {{ __('The new release is already live — only a post-cutover step (the post-deploy command or a worker restart) failed. Resume re-runs just that tail: no re-clone, re-build, re-migrate, or symlink flip.') }}
                        @elseif ($resumePhase === 'release')
                            {{ __('The build succeeded but a release step failed before cutover. Resume re-uses that build and re-runs the release phase onward — the previous release stays live until it passes. Note: this re-runs migrations.') }}
                        @else
                            {{ __('A build step failed before cutover. Resume re-uses the existing checkout and re-runs from the build phase — the previous release stays live until the new build passes.') }}
                        @endif
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="confirmResumeDeployment('{{ $latest->id }}')"
                            wire:loading.attr="disabled"
                            wire:target="confirmResumeDeployment('{{ $latest->id }}')"
                            @disabled($deployInProgress)
                            class="inline-flex items-center gap-2 rounded-lg bg-sky-700 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-800 disabled:opacity-60"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                            {{ __('Resume from :phase', ['phase' => $resumePhase]) }}
                        </button>
                        <span class="text-[11px] text-brand-mist">{{ __('Or use Deploy above for a clean full run.') }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($this->deployLockInfo ?? null)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="flex flex-wrap items-center gap-2">
                <x-heroicon-m-bolt class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                <strong class="font-semibold">{{ __('Deployment in progress') }}</strong>
                @if (! empty($this->deployLockInfo['deployment_id']))
                    <span class="font-mono text-xs text-amber-800">#{{ $this->deployLockInfo['deployment_id'] }}</span>
                @endif
            </div>
            <p class="mt-1 text-amber-800">{{ __('Queued deploys may appear as skipped until this run finishes.') }}</p>
            <button
                type="button"
                wire:click="openConfirmActionModal('releaseDeployLock', [], @js(__('Clear deploy lock')), @js(__('Force-clear the deploy lock? Only if no worker is actually deploying.')), @js(__('Clear lock')), true)"
                class="mt-2 text-xs font-semibold text-amber-900 underline hover:text-amber-700"
            >{{ __('Clear lock') }}</button>
        </div>
    @endif

    {{-- Deploy blocked on missing env. The deploy job's preflight reads the
         live .env and stops early when a required (no-default) variable is
         absent, recording the offenders here. Prompt the operator to fill them
         inline rather than letting the build succeed and the app 500. --}}
    @if ($blockedEnv !== [])
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
            <div class="flex items-start gap-3">
                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-rose-700" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-rose-900">
                        {{ trans_choice('{1} Deploy needs :count environment variable|[2,*] Deploy needs :count environment variables', count($blockedEnv), ['count' => count($blockedEnv)]) }}
                    </p>
                    <p class="mt-1 text-sm leading-relaxed text-rose-800">{{ __('The last deploy stopped because the app requires these and they aren\'t set. Add them, then deploy again.') }}</p>
                    <div class="mt-2.5 flex flex-wrap gap-1.5">
                        @foreach (array_slice($blockedEnv, 0, 24) as $entry)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 font-mono text-[11px] font-semibold text-rose-800 ring-1 ring-inset ring-rose-200">
                                {{ $entry['key'] }}
                                <button type="button" wire:click="confirmIgnoreEnvKey('{{ $entry['key'] }}')" class="-mr-0.5 text-rose-400 hover:text-rose-700" title="{{ __('Ignore :key', ['key' => $entry['key']]) }}" aria-label="{{ __('Ignore :key', ['key' => $entry['key']]) }}">
                                    <x-heroicon-o-x-mark class="h-3 w-3" />
                                </button>
                            </span>
                        @endforeach
                        @if (count($blockedEnv) > 24)
                            <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800">{{ __('+:count more', ['count' => count($blockedEnv) - 24]) }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Single action bar: fix-it actions on the left, sync / bypass on
                 the right. Wraps cleanly instead of a floating centered column. --}}
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-rose-200/70 pt-3">
                <button
                    type="button"
                    wire:click="openBlockedEnvModal"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-rose-800"
                >
                    <x-heroicon-o-plus class="h-4 w-4" />
                    {{ __('Add variables') }}
                </button>
                <button
                    type="button"
                    wire:click="setTab('environment')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm transition-colors hover:bg-rose-100"
                >
                    <x-heroicon-o-pencil-square class="h-4 w-4" />
                    {{ __('Edit all variables') }}
                </button>

                <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                    <button
                        type="button"
                        wire:click="recheckBlockedEnv"
                        wire:loading.attr="disabled"
                        wire:target="recheckBlockedEnv"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white/70 px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm transition-colors hover:bg-rose-100 disabled:opacity-60"
                        title="{{ __('Re-read the server .env and clear this if the variables are actually set — no deploy needed.') }}"
                    >
                        <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="recheckBlockedEnv" />
                        <span wire:loading wire:target="recheckBlockedEnv" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                        {{ __('Re-check') }}
                    </button>
                    <button
                        type="button"
                        wire:click="viewServerEnv"
                        wire:loading.attr="disabled"
                        wire:target="viewServerEnv"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white/70 px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm transition-colors hover:bg-rose-100 disabled:opacity-60"
                        title="{{ __('See which variables are set on the server right now — read-only, nothing is overwritten.') }}"
                    >
                        <x-heroicon-o-eye class="h-4 w-4" wire:loading.remove wire:target="viewServerEnv" />
                        <span wire:loading wire:target="viewServerEnv" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                        {{ __('View server .env') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmDeployIgnoringEnvGate"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white/70 px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm transition-colors hover:bg-rose-100"
                        title="{{ __('Ignore the required-env check and deploy anyway.') }}"
                    >
                        <x-heroicon-o-rocket-launch class="h-4 w-4" />
                        {{ __('Deploy anyway') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmIgnoreMissingEnv"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white/70 px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm transition-colors hover:bg-rose-100"
                        title="{{ __('Stop blocking deploys on missing required variables.') }}"
                    >
                        <x-heroicon-o-no-symbol class="h-4 w-4" />
                        {{ __('Ignore all') }}
                    </button>
                </div>
            </div>
        </div>

        <x-modal name="deploy-missing-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
            <div class="relative border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-700">{{ __('Required variables') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add the missing variables') }}</h2>
                <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                    {{ __('The deploy needs these to run. Fill in the ones you have — blanks are skipped. They\'re saved to the Environment section and pushed to the server.') }}
                </p>
                <button
                    type="button"
                    x-on:click="$dispatch('close')"
                    class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                    aria-label="{{ __('Close') }}"
                >
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto px-6 py-6">
                <form wire:submit="addBlockedEnvVars" id="deploy-missing-env-form" class="space-y-3">
                    @foreach ($blockedEnv as $entry)
                        <div wire:key="blocked-env-{{ md5($entry['key']) }}">
                            <label class="block font-mono text-xs font-semibold text-brand-ink" for="blocked_env_{{ md5($entry['key']) }}">{{ $entry['key'] }}</label>
                            <input
                                id="blocked_env_{{ md5($entry['key']) }}"
                                wire:model="blocked_env_values.{{ $entry['key'] }}"
                                autocomplete="off"
                                spellcheck="false"
                                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                                placeholder="{{ $entry['example'] !== null && $entry['example'] !== '' ? $entry['example'] : __('value') }}"
                            />
                            <div class="mt-1 flex items-center gap-3">
                                @if ($entry['key'] === 'APP_KEY')
                                    <button type="button" wire:click="generateBlockedAppKey" class="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-700 hover:underline">
                                        <x-heroicon-o-sparkles class="h-3 w-3" />
                                        {{ __('Generate a key') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="confirmIgnoreEnvKey('{{ $entry['key'] }}')" class="text-[11px] font-semibold text-brand-mist hover:text-rose-700 hover:underline" title="{{ __('Mark this variable as intentionally unset.') }}">{{ __('Ignore this') }}</button>
                            </div>
                        </div>
                    @endforeach
                </form>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                <button type="button" wire:click="setTab('environment')" class="mr-auto inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                    <x-heroicon-o-pencil-square class="h-4 w-4" />
                    {{ __('Edit all variables') }}
                </button>
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit" form="deploy-missing-env-form" wire:loading.attr="disabled" wire:target="addBlockedEnvVars">
                    <span wire:loading.remove wire:target="addBlockedEnvVars">{{ __('Add variables') }}</span>
                    <span wire:loading wire:target="addBlockedEnvVars">{{ __('Adding…') }}</span>
                </x-primary-button>
            </div>
        </x-modal>
    @endif

    {{-- Verify Octane is actually installed AND serving this site before the
         advisor is allowed to suggest `octane:reload`. Deferred so it never
         SSHes from the render path; renders unconditionally (when supported) so
         the probe still runs when the suppressed Octane step is the only one. --}}
    @if (method_exists($this, 'ensureOctaneVerificationProbe'))
        <div wire:init="ensureOctaneVerificationProbe" class="hidden" aria-hidden="true"></div>
    @endif

    {{-- Pipeline suggestions — proactively flag missing-but-needed deploy
         steps (e.g. installs JS deps but never builds them → the live site
         500s on a missing Vite manifest) with one-click "Add to pipeline". --}}
    @php
        $pipelineSuggestions = method_exists($this, 'optimizePipeline') ? \App\Support\Sites\SitePipelineAdvisor::suggestions($site) : [];
        $pipelineDismissedCount = method_exists($this, 'optimizePipeline') ? \App\Support\Sites\SitePipelineAdvisor::dismissedCount($site) : 0;
        $canAutofixPipeline = method_exists($this, 'addSuggestedPipelineStep');
    @endphp
    @if ($pipelineSuggestions !== [] || $pipelineDismissedCount > 0)
        {{-- While the optimizePipeline Livewire request is in flight, swap the
             card for a starting placeholder so old suggestions don't flash. --}}
        <div wire:loading.flex wire:target="optimizePipeline" class="hidden items-center justify-center gap-3 rounded-2xl border border-dashed border-indigo-200 bg-indigo-50/40 px-4 py-8 text-sm text-indigo-700">
            <x-spinner size="sm" />
            <span>{{ __('Starting pipeline scan…') }}</span>
        </div>

        <div wire:loading.remove wire:target="optimizePipeline">
        @if ($watchedConsoleRunId)
            {{-- Scan job is running on the worker; the hidden poll div above
                 calls resolveWatchedConsoleAction every 3 s and re-renders. --}}
            <div class="flex items-center justify-center gap-3 rounded-2xl border border-dashed border-indigo-200 bg-indigo-50/40 px-4 py-8 text-sm text-indigo-700">
                <x-spinner size="sm" />
                <span>{{ __('Scanning the repo for pipeline steps…') }}</span>
            </div>
        @else
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-4">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 ring-1 ring-inset ring-indigo-200">
                    <x-heroicon-o-sparkles class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ __('Pipeline check') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-indigo-950">
                                @if ($pipelineSuggestions !== [])
                                    {{ trans_choice('{1} :count suggested deploy step|[2,*] :count suggested deploy steps', count($pipelineSuggestions), ['count' => count($pipelineSuggestions)]) }}
                                @else
                                    {{ __('No open suggestions') }}
                                @endif
                            </h3>
                        </div>
                        @if ($pipelineSuggestions !== [] && method_exists($this, 'optimizePipeline'))
                            <button type="button" wire:click="optimizePipeline" wire:loading.attr="disabled" wire:target="optimizePipeline" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-indigo-300 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 disabled:opacity-60" title="{{ __('Read package.json / composer.json on the server and add every step the repo needs.') }}">
                                <x-heroicon-o-sparkles class="h-4 w-4" wire:loading.remove wire:target="optimizePipeline" />
                                <span wire:loading wire:target="optimizePipeline" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                                <span wire:loading.remove wire:target="optimizePipeline">{{ __('Optimize pipeline') }}</span>
                                <span wire:loading wire:target="optimizePipeline">{{ __('Scanning…') }}</span>
                            </button>
                        @endif
                    </div>

                    @if ($pipelineSuggestions !== [])
                        <p class="mt-1 text-sm text-indigo-900/80">{{ __('Add a fix to drop the step into your pipeline, or Optimize to scan the repo and add everything at once — so a deploy doesn\'t succeed while the site breaks. Dismiss anything you don\'t want.') }}</p>
                        <ul class="mt-3 space-y-2">
                            @foreach ($pipelineSuggestions as $sug)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-indigo-200/70 bg-white/70 px-3 py-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-brand-ink">
                                            {{ $sug['label'] }}
                                            @if ($sug['priority'] === 'high')
                                                <span class="rounded-full bg-rose-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.12em] text-rose-700">{{ __('recommended') }}</span>
                                            @endif
                                            <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-[10px] text-brand-moss">{{ $sug['phase'] }}</span>
                                        </p>
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ $sug['reason'] }}@if ($sug['command']) <span class="font-mono text-brand-ink/70">· {{ $sug['command'] }}</span>@endif</p>
                                    </div>
                                    @if ($canAutofixPipeline)
                                        <div class="flex shrink-0 items-center gap-1.5">
                                            <button
                                                type="button"
                                                wire:click="addSuggestedPipelineStep(@js($sug['key']))"
                                                wire:loading.attr="disabled"
                                                wire:target="addSuggestedPipelineStep, dismissPipelineSuggestion"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-60"
                                                title="{{ __('Add this step to the deploy pipeline.') }}"
                                            >
                                                <x-heroicon-o-wrench-screwdriver class="h-4 w-4" />
                                                {{ __('Add fix') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="dismissPipelineSuggestion(@js($sug['key']))"
                                                wire:loading.attr="disabled"
                                                wire:target="addSuggestedPipelineStep, dismissPipelineSuggestion"
                                                class="inline-flex items-center justify-center rounded-lg border border-transparent p-1.5 text-brand-mist transition-colors hover:border-indigo-200 hover:bg-white hover:text-brand-moss disabled:opacity-60"
                                                title="{{ __('Dismiss this suggestion') }}"
                                                aria-label="{{ __('Dismiss :label', ['label' => $sug['label']]) }}"
                                            >
                                                <x-heroicon-o-x-mark class="h-4 w-4" />
                                            </button>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-1 text-sm text-indigo-900/80">{{ __('Every detected suggestion has been dismissed. Your pipeline still deploys — restore them if you want another look.') }}</p>
                    @endif

                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                        @if ($pipelineSuggestions !== [])
                            <button type="button" wire:click="setTab('pipeline')" class="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-700 hover:underline">
                                <x-heroicon-o-pencil-square class="h-3 w-3" />
                                {{ __('Edit the full pipeline') }}
                            </button>
                        @endif
                        @if ($pipelineDismissedCount > 0 && method_exists($this, 'restorePipelineSuggestions'))
                            <button type="button" wire:click="restorePipelineSuggestions" class="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-moss hover:text-brand-ink hover:underline">
                                <x-heroicon-o-arrow-uturn-left class="h-3 w-3" />
                                {{ trans_choice('{1} Restore 1 dismissed|[2,*] Restore :count dismissed', $pipelineDismissedCount, ['count' => $pipelineDismissedCount]) }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endif
        </div>
    @endif

    <section class="dply-card overflow-hidden">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:px-8">
            <x-icon-badge>
                <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Ship the current branch') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    @if ($latest)
                        @if ($isRunning)
                            {{ __('A deploy is currently running. Watch the phase timeline below.') }}
                        @elseif ($latest->status === 'success')
                            {{ __('Last deploy succeeded :time.', ['time' => ($latest->finished_at ?? $latest->created_at)?->diffForHumans()]) }}
                        @elseif ($latest->status === 'failed')
                            {{ __('Last deploy failed :time. Check the phase timeline below.', ['time' => ($latest->finished_at ?? $latest->created_at)?->diffForHumans()]) }}
                        @else
                            {{ __('Latest deploy: :status', ['status' => $latest->status]) }}
                        @endif
                    @else
                        {{ __('No deploys yet. Trigger one to deploy the current branch.') }}
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2 sm:ml-auto">
                <button type="button" wire:click="deployNow" wire:loading.attr="disabled" wire:target="deployNow" @disabled($deployInProgress) class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60">
                    @if ($deployInProgress)
                        <x-spinner variant="white" size="sm" />
                        <span>{{ __('Deploying…') }}</span>
                    @else
                        <x-heroicon-o-rocket-launch class="h-4 w-4" wire:loading.remove wire:target="deployNow" />
                        <span wire:loading wire:target="deployNow"><x-spinner variant="white" size="sm" /></span>
                        <span wire:loading.remove wire:target="deployNow">{{ __('Deploy') }}</span>
                        <span wire:loading wire:target="deployNow">{{ __('Deploying…') }}</span>
                    @endif
                </button>
                @if ($this->deploySyncPeerCount > 0)
                    <button type="button" wire:click="queueDeploy" wire:loading.attr="disabled" wire:target="queueDeploy" title="{{ __('Also deploys :count linked site(s) in this deploy sync group.', ['count' => $this->deploySyncPeerCount]) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:opacity-50">
                        <x-heroicon-o-queue-list class="h-4 w-4" />
                        {{ __('Deploy linked sites') }}
                    </button>
                @endif

                {{-- Schedule a one-off deploy for later (delay presets + custom time). --}}
                <div x-data="{ open: false, custom: '' }" class="relative">
                    <button type="button" x-on:click="open = ! open" title="{{ __('Schedule this deploy for later') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        <x-heroicon-o-clock class="h-4 w-4" />
                        {{ __('Schedule') }}
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 opacity-60" />
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition class="absolute right-0 z-20 mt-1 w-60 rounded-xl border border-brand-ink/10 bg-white p-1.5 shadow-xl">
                        <p class="px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Deploy in') }}</p>
                        @foreach (['15' => __('15 minutes'), '60' => __('1 hour'), '180' => __('3 hours'), '720' => __('12 hours')] as $mins => $label)
                            <button type="button" wire:click="scheduleDeploy('{{ $mins }}')" x-on:click="open = false" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-clock class="h-3.5 w-3.5 text-brand-moss" />
                                {{ $label }}
                            </button>
                        @endforeach
                        <div class="my-1 border-t border-brand-ink/10"></div>
                        <div class="px-2 py-1.5">
                            <label class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Custom time') }}</label>
                            <input type="datetime-local" x-model="custom" class="dply-input mt-1 w-full text-xs" />
                            <button type="button" x-on:click="if (custom) { $wire.scheduleDeploy(custom); open = false; custom = '' }" x-bind:disabled="! custom" class="mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-50">
                                <x-heroicon-o-clock class="h-3.5 w-3.5" />
                                {{ __('Schedule deploy') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Pending delayed deploy banner. --}}
        @if ($this->pendingScheduledDeploy)
            @php $pendingSchedule = $this->pendingScheduledDeploy; @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-6 py-3 sm:px-8">
                <p class="flex items-center gap-2 text-sm text-amber-900">
                    <x-heroicon-o-clock class="h-4 w-4 shrink-0 text-amber-700" />
                    <span>
                        {{ __('Deploy scheduled :rel', ['rel' => $pendingSchedule->run_at->diffForHumans()]) }}
                        <span class="text-amber-700/80" title="{{ $pendingSchedule->run_at->toDayDateTimeString() }}">· {{ $pendingSchedule->run_at->isoFormat('MMM D, h:mm A') }}</span>
                    </span>
                </p>
                <button type="button" wire:click="cancelScheduledDeploy" wire:loading.attr="disabled" wire:target="cancelScheduledDeploy" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-800 hover:underline">
                    <x-heroicon-o-x-mark class="h-4 w-4" />
                    {{ __('Cancel') }}
                </button>
            </div>
        @endif

        {{-- Summary stats — hairline-divided cells (gap-px over a tinted track)
             so the four read as one continuous strip rather than four boxes,
             echoing the connected feel of the phase rail below. --}}
        <dl class="grid grid-cols-2 gap-px border-b border-brand-ink/10 bg-brand-ink/[0.06] text-sm sm:grid-cols-4">
            <div class="min-w-0 bg-white px-6 py-4 sm:px-8">
                <dt class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <x-heroicon-m-code-bracket class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Deployed commit') }}
                </dt>
                <dd class="mt-1.5 truncate">
                    @if ($shortSha)
                        <button
                            type="button"
                            x-on:click="$dispatch('open-modal', 'deployed-commit')"
                            title="{{ __('View commit details') }}"
                            class="inline-flex items-center gap-1 rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-xs font-semibold text-brand-sage transition-colors hover:bg-brand-sand hover:text-brand-forest focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
                        >
                            {{ $shortSha }}
                            <x-heroicon-m-arrow-top-right-on-square class="h-3 w-3 opacity-60" aria-hidden="true" />
                        </button>
                    @elseif ($latest)
                        <span class="text-brand-mist">{{ __('No successful deploy yet') }}</span>
                    @else
                        <span class="text-brand-mist">{{ __('No deploys yet') }}</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0 bg-white px-6 py-4 sm:px-8">
                <dt class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <x-heroicon-m-signal class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Status') }}
                </dt>
                <dd class="mt-1.5">
                    @if ($latest)
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $latest->status === 'success',
                            'bg-rose-50 text-rose-800 ring-rose-200' => $latest->status === 'failed',
                            'bg-amber-50 text-amber-900 ring-amber-200' => $latest->status === 'running',
                            'bg-brand-sand/60 text-brand-ink ring-brand-ink/10' => ! in_array($latest->status, ['success', 'failed', 'running']),
                        ])>
                            <span @class([
                                'h-1.5 w-1.5 rounded-full',
                                'bg-emerald-500' => $latest->status === 'success',
                                'bg-rose-500' => $latest->status === 'failed',
                                'bg-amber-500 animate-pulse' => $latest->status === 'running',
                                'bg-brand-mist' => ! in_array($latest->status, ['success', 'failed', 'running']),
                            ])></span>
                            {{ $latest->status }}
                        </span>
                    @else
                        <span class="text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0 bg-white px-6 py-4 sm:px-8">
                <dt class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <x-heroicon-m-clock class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Duration') }}
                </dt>
                <dd class="mt-1.5 font-mono text-xs tabular-nums text-brand-ink">
                    @if ($totalDurationMs > 0)
                        {{ number_format($totalDurationMs / 1000, 1) }}s
                    @elseif ($latest?->started_at && $latest?->finished_at)
                        {{ $latest->started_at->diffInSeconds($latest->finished_at) }}s
                    @else
                        <span class="font-sans text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0 bg-white px-6 py-4 sm:px-8">
                <dt class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <x-heroicon-m-bolt class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Trigger') }}
                </dt>
                <dd class="mt-1.5 truncate text-brand-ink">{{ $latest?->trigger ?: '—' }}</dd>
            </div>
        </dl>

        @include('livewire.sites.partials.deployments._schedule-panel')

        <div class="px-6 py-6 sm:px-8">
            {{-- While a deploy request is in flight, clear the previous run's
                 timeline right away and show a starting placeholder. The deploy
                 runs synchronously, so this state holds for the whole request
                 instead of flashing for a frame. --}}
            <div wire:loading.flex wire:target="deployNow,queueDeploy" class="hidden items-center justify-center gap-3 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-8 text-sm text-brand-moss">
                <x-spinner size="sm" />
                <span>{{ __('Starting deploy — clearing the previous run…') }}</span>
            </div>

            <div wire:loading.remove wire:target="deployNow,queueDeploy">
            @if ($deployInProgress && ! $isRunning)
                {{-- Queued on a worker but the run hasn't recorded its first
                     phase yet — show a starting placeholder instead of the
                     previous run's timeline. Flips to the live timeline below
                     once the worker creates the running deployment record. --}}
                <div class="flex items-center justify-center gap-3 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-8 text-sm text-brand-moss">
                    <x-spinner size="sm" />
                    <span>{{ __('Deploy queued — starting on a worker…') }}</span>
                </div>
            @elseif ($latest === null)
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-8 text-center text-sm text-brand-moss">
                    {{ __('No phase timeline yet — your first deploy will appear here.') }}
                </div>
            @else
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Phase timeline') }}</p>
                    <a
                        href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $latest]) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:text-brand-sage hover:underline"
                    >
                        {{ __('View full log') }}
                        <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
                    </a>
                </div>

                @php
                    // Inline database-connection fix under the failed step (Q8/Q10).
                    // On the deploy hub $latest IS the site's latest deployment, so
                    // we only gate on "failed + matched the guided DB remediation".
                    $dbFix = null;
                    if ($latest && $latest->status === 'failed' && method_exists($this, 'remediationForDeployment')) {
                        $rem = $this->remediationForDeployment($latest);
                        if (is_array($rem) && ($rem['code'] ?? null) === 'database_connection_failed') {
                            $dbFix = ['server' => $server, 'site' => $site];
                        }
                    }
                @endphp
                @include('livewire.sites.partials.deployments._phase-timeline', ['timelinePhases' => $timelinePhases, 'deployment' => $latest, 'dbFix' => $dbFix])
            @endif
            </div>
        </div>
    </section>

    {{-- Deployed-commit detail modal (opened from the summary badge). --}}
    @if ($deployedDeployment)
        <x-modal name="deployed-commit" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-code-bracket class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Deployed commit') }}</p>
                    <h2 class="mt-1 font-mono text-lg font-semibold text-brand-ink">{{ $shortSha }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('The commit currently live for this site (last successful deploy).') }}</p>
                </div>
            </div>

            <div class="space-y-4 px-6 py-6">
                {{-- Full SHA + copy. --}}
                <div x-data="{ copied: false }">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Full SHA') }}</p>
                    <div class="mt-1 flex items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded-lg bg-brand-sand/40 px-3 py-2 font-mono text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $deployedSha }}</code>
                        <button
                            type="button"
                            x-on:click="navigator.clipboard.writeText(@js($deployedSha)); copied = true; setTimeout(() => copied = false, 1500)"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                        >
                            <template x-if="! copied"><span class="inline-flex items-center gap-1.5"><x-heroicon-o-clipboard-document class="h-4 w-4" /> {{ __('Copy') }}</span></template>
                            <template x-if="copied"><span class="inline-flex items-center gap-1.5 text-emerald-700"><x-heroicon-o-check class="h-4 w-4" /> {{ __('Copied') }}</span></template>
                        </button>
                    </div>
                </div>

                {{-- Facts grid. Built as a list so an odd cell count makes the
                     last cell span both columns — no empty grey track cell. --}}
                @php
                    $deployedAt = $deployedDeployment->finished_at ?? $deployedDeployment->created_at;
                    $facts = [];
                    if ($deployedBranch !== '') {
                        $facts[] = ['label' => __('Branch'), 'value' => $deployedBranch, 'class' => 'truncate font-mono text-xs text-brand-ink'];
                    }
                    $facts[] = ['label' => __('Status'), 'value' => ucfirst($deployedDeployment->status), 'class' => 'text-xs font-semibold text-emerald-700'];
                    $facts[] = ['label' => __('Deployed'), 'value' => $deployedAt?->diffForHumans(), 'class' => 'text-xs text-brand-ink', 'title' => $deployedAt?->toDayDateTimeString()];
                    $facts[] = ['label' => __('Trigger'), 'value' => ucfirst((string) ($deployedDeployment->trigger ?? '—')), 'class' => 'text-xs text-brand-ink'];
                    if ($deployedDurationMs > 0) {
                        $facts[] = ['label' => __('Duration'), 'value' => number_format($deployedDurationMs / 1000, 1).'s', 'class' => 'font-mono text-xs text-brand-ink'];
                    }
                    $oddCount = count($facts) % 2 === 1;
                @endphp
                <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-brand-ink/[0.06] ring-1 ring-inset ring-brand-ink/10">
                    @foreach ($facts as $fact)
                        <div @class(['bg-white px-4 py-3', 'col-span-2' => $oddCount && $loop->last])>
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $fact['label'] }}</dt>
                            <dd class="mt-1 {{ $fact['class'] }}" @isset($fact['title']) title="{{ $fact['title'] }}" @endisset>{{ $fact['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <div class="flex flex-wrap items-center gap-3">
                    @if ($commitWebUrl)
                        <a href="{{ $commitWebUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-forest hover:underline">
                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" /> {{ __('View on provider') }}
                        </a>
                    @endif
                    <a href="{{ route('sites.deployments.show', ['server' => $server ?? $site->server, 'site' => $site, 'deployment' => $deployedDeployment]) }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-forest hover:underline">
                        <x-heroicon-o-document-text class="h-4 w-4" /> {{ __('Open deployment') }}
                    </a>
                </div>
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'deployed-commit')">{{ __('Close') }}</x-secondary-button>
            </div>
        </x-modal>
    @endif

    @if (method_exists($this, 'applyPipelineOptimization'))
        @include('livewire.sites.partials.pipeline._optimize-preview-modal')
    @endif

    @if (method_exists($this, 'deployWithIdentity'))
        <x-modal name="supply-deploy-identity" maxWidth="lg" overlayClass="bg-brand-ink/40" focusable>
            <div class="p-6">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-key class="h-6 w-6 shrink-0 text-brand-forest" />
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Supply your organization key') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('This site has secrets under a customer-held key, so dply cannot decrypt them on its own. Paste your age identity to deploy. It is used for this deploy only and is never stored.') }}
                        </p>
                    </div>
                </div>

                <div class="mt-4">
                    <x-input-label for="deploy_identity" :value="__('age identity (AGE-SECRET-KEY-…)')" />
                    <textarea
                        id="deploy_identity"
                        wire:model="deploy_identity"
                        rows="4"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-[12px] shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        placeholder="AGE-SECRET-KEY-1…"
                    ></textarea>
                    <x-input-error :messages="$errors->get('deploy_identity')" class="mt-1" />
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'supply-deploy-identity')">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="button" wire:click="deployWithIdentity" wire:loading.attr="disabled" wire:target="deployWithIdentity">
                        {{ __('Deploy') }}
                    </x-primary-button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
