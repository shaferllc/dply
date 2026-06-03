@php
    $latest = $latestDeployment ?? null;
    $isRunning = $latest && $latest->status === 'running';
    // A deploy is "in progress" whenever the latest run is running or the
    // deploy lock is held (e.g. a queued run on a worker). Drive the deploy
    // button off this so it reads "Deploying…" for the whole run, not just
    // the brief request that dispatches it.
    $deployInProgress = $isRunning || (bool) ($this->deployLockInfo ?? null);
    // "Deployed commit" must reflect the code actually live — the last SUCCESSFUL
    // deploy — not the latest attempt (which may be skipped/failed with no SHA,
    // the source of the confusing "No deploys yet" when deploys clearly exist).
    $deployedDeployment = ($latest && $latest->status === 'success')
        ? $latest
        : $site->deployments()->where('status', 'success')->latest()->first();
    $deployedSha = $deployedDeployment?->git_sha;
    $shortSha = $deployedSha ? \Illuminate\Support\Str::limit($deployedSha, 7, '') : null;
    $totalDurationMs = $latest ? $latest->phaseTotalDurationMs() : 0;
    // Phase timeline derived from the site's pipeline (Clone → Build →
    // Activate → Release) overlaid with this deployment's recorded steps.
    $timelinePhases = \App\Support\Sites\SiteDeployTimeline::forDeployment($site, $latest);

    // Env vars the last deploy was blocked on (recorded by the deploy job's
    // preflight). Non-empty → the deploy stopped early asking for these.
    $blockedEnv = $this->deployBlockedEnvKeys();
@endphp

<div class="space-y-6" @if ($deployInProgress) wire:poll.5s @endif>
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
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add variables') }}
                </button>
                <button
                    type="button"
                    wire:click="setTab('environment')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm transition-colors hover:bg-rose-100"
                >
                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
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
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="recheckBlockedEnv" />
                        <span wire:loading wire:target="recheckBlockedEnv" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
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
                        <x-heroicon-o-eye class="h-3.5 w-3.5" wire:loading.remove wire:target="viewServerEnv" />
                        <span wire:loading wire:target="viewServerEnv" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                        {{ __('View server .env') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmDeployIgnoringEnvGate"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white/70 px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm transition-colors hover:bg-rose-100"
                        title="{{ __('Ignore the required-env check and deploy anyway.') }}"
                    >
                        <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" />
                        {{ __('Deploy anyway') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmIgnoreMissingEnv"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white/70 px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm transition-colors hover:bg-rose-100"
                        title="{{ __('Stop blocking deploys on missing required variables.') }}"
                    >
                        <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
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
                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
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

    {{-- Pipeline suggestions — proactively flag missing-but-needed deploy
         steps (e.g. installs JS deps but never builds them → the live site
         500s on a missing Vite manifest) with one-click "Add to pipeline". --}}
    @php $pipelineSuggestions = method_exists($this, 'optimizePipeline') ? \App\Support\Sites\SitePipelineAdvisor::suggestions($site) : []; @endphp
    @if ($pipelineSuggestions !== [])
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
                                {{ trans_choice('{1} :count suggested deploy step|[2,*] :count suggested deploy steps', count($pipelineSuggestions), ['count' => count($pipelineSuggestions)]) }}
                            </h3>
                        </div>
                        @if (method_exists($this, 'optimizePipeline'))
                            <button type="button" wire:click="optimizePipeline" wire:loading.attr="disabled" wire:target="optimizePipeline" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-indigo-300 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 disabled:opacity-60" title="{{ __('Read package.json / composer.json on the server and add every step the repo needs.') }}">
                                <x-heroicon-o-sparkles class="h-3.5 w-3.5" wire:loading.remove wire:target="optimizePipeline" />
                                <span wire:loading wire:target="optimizePipeline" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                                <span wire:loading.remove wire:target="optimizePipeline">{{ __('Optimize pipeline') }}</span>
                                <span wire:loading wire:target="optimizePipeline">{{ __('Scanning…') }}</span>
                            </button>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-indigo-900/80">{{ __('Add these individually, or Optimize pipeline to scan the repo and add everything at once — so a deploy doesn\'t succeed while the site breaks.') }}</p>
                    <ul class="mt-3 space-y-2">
                        @foreach ($pipelineSuggestions as $sug)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-indigo-200/70 bg-white/70 px-3 py-2">
                                <div class="min-w-0 flex-1">
                                    <p class="flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                                        {{ $sug['label'] }}
                                        @if ($sug['priority'] === 'high')
                                            <span class="rounded-full bg-rose-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.12em] text-rose-700">{{ __('recommended') }}</span>
                                        @endif
                                        <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-[10px] text-brand-moss">{{ $sug['phase'] }}</span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ $sug['reason'] }}@if ($sug['command']) <span class="font-mono text-brand-ink/70">· {{ $sug['command'] }}</span>@endif</p>
                                </div>
                                @if (method_exists($this, 'addDeployPipelineStepFromPalette'))
                                    <button
                                        type="button"
                                        wire:click="addDeployPipelineStepFromPalette(@js($sug['step_type']), null, @js($sug['phase']), @js($sug['command']))"
                                        wire:loading.attr="disabled"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:opacity-60"
                                    >
                                        <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                        {{ __('Add to pipeline') }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" wire:click="setTab('pipeline')" class="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-700 hover:underline">
                        <x-heroicon-o-pencil-square class="h-3 w-3" />
                        {{ __('Edit the full pipeline') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <section class="dply-card overflow-hidden">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:px-8">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
            </span>
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
                        <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" wire:loading.remove wire:target="deployNow" />
                        <span wire:loading wire:target="deployNow"><x-spinner variant="white" size="sm" /></span>
                        <span wire:loading.remove wire:target="deployNow">{{ __('Deploy') }}</span>
                        <span wire:loading wire:target="deployNow">{{ __('Deploying…') }}</span>
                    @endif
                </button>
                @if ($this->deploySyncPeerCount > 0)
                    <button type="button" wire:click="queueDeploy" wire:loading.attr="disabled" wire:target="queueDeploy" title="{{ __('Also deploys :count linked site(s) in this deploy sync group.', ['count' => $this->deploySyncPeerCount]) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:opacity-50">
                        <x-heroicon-o-queue-list class="h-3.5 w-3.5" />
                        {{ __('Deploy linked sites') }}
                    </button>
                @endif
                @if (method_exists($this, 'optimizePipeline'))
                    <button type="button" wire:click="optimizePipeline" wire:loading.attr="disabled" wire:target="optimizePipeline" class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-2 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-indigo-100 disabled:opacity-60" title="{{ __('Read package.json / composer.json on the server and add every deploy step the repo needs.') }}">
                        <x-heroicon-o-sparkles class="h-3.5 w-3.5" wire:loading.remove wire:target="optimizePipeline" />
                        <span wire:loading wire:target="optimizePipeline" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                        <span wire:loading.remove wire:target="optimizePipeline">{{ __('Optimize pipeline') }}</span>
                        <span wire:loading wire:target="optimizePipeline">{{ __('Scanning…') }}</span>
                    </button>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 border-b border-brand-ink/10 bg-white px-6 py-5 text-sm sm:grid-cols-4 sm:px-8">
            <div class="min-w-0">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Deployed commit') }}</dt>
                <dd class="mt-1 truncate">
                    @if ($shortSha)
                        <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-xs font-semibold text-brand-sage" title="{{ $deployedSha }}">{{ $shortSha }}</span>
                    @elseif ($latest)
                        <span class="text-brand-mist">{{ __('No successful deploy yet') }}</span>
                    @else
                        <span class="text-brand-mist">{{ __('No deploys yet') }}</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Status') }}</dt>
                <dd class="mt-1">
                    @if ($latest)
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $latest->status === 'success',
                            'bg-rose-50 text-rose-800 ring-rose-200' => $latest->status === 'failed',
                            'bg-amber-50 text-amber-900 ring-amber-200' => $latest->status === 'running',
                            'bg-brand-sand/60 text-brand-ink ring-brand-ink/10' => ! in_array($latest->status, ['success', 'failed', 'running']),
                        ])>{{ $latest->status }}</span>
                    @else
                        <span class="text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Duration') }}</dt>
                <dd class="mt-1 font-mono text-xs text-brand-ink">
                    @if ($totalDurationMs > 0)
                        {{ number_format($totalDurationMs / 1000, 1) }}s
                    @elseif ($latest?->started_at && $latest?->finished_at)
                        {{ $latest->started_at->diffInSeconds($latest->finished_at) }}s
                    @else
                        <span class="font-sans text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Trigger') }}</dt>
                <dd class="mt-1 text-brand-ink">{{ $latest?->trigger ?: '—' }}</dd>
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
                        <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>

                <ol class="mt-3 space-y-2">
                    @foreach ($timelinePhases as $phase)
                        @php
                            $st = $phase['status'];
                            $stepCount = count($phase['steps']);
                            $durTxt = $phase['duration_ms'] > 0 ? number_format($phase['duration_ms'] / 1000, 1).'s' : null;
                        @endphp
                        <li @class([
                            'rounded-2xl border px-4 py-3 transition-colors',
                            'border-emerald-200 bg-emerald-50/50' => $st === 'success',
                            'border-rose-200 bg-rose-50/50' => $st === 'failed',
                            'border-amber-200 bg-amber-50/50' => $st === 'running',
                            'border-brand-ink/10 bg-brand-sand/10' => in_array($st, ['skipped', 'pending'], true),
                        ])>
                            <div class="flex items-center gap-3">
                                <span @class([
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-1 ring-inset font-semibold text-xs',
                                    'bg-emerald-100 text-emerald-800 ring-emerald-200' => $st === 'success',
                                    'bg-rose-100 text-rose-800 ring-rose-200' => $st === 'failed',
                                    'bg-amber-100 text-amber-800 ring-amber-200' => $st === 'running',
                                    'bg-white text-brand-mist ring-brand-ink/10' => in_array($st, ['skipped', 'pending'], true),
                                ])>
                                    @switch ($st)
                                        @case('success')
                                            <x-heroicon-m-check class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @case('failed')
                                            <x-heroicon-m-x-mark class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @case('running')
                                            <x-heroicon-m-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                                            @break
                                        @case('skipped')
                                            <x-heroicon-m-minus class="h-4 w-4" aria-hidden="true" />
                                            @break
                                        @default
                                            {{ $loop->iteration }}
                                    @endswitch
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                                        <span class="font-semibold text-brand-ink">{{ $phase['label'] }}</span>
                                        <span class="text-[11px] text-brand-moss">
                                            @switch ($st)
                                                @case('success')
                                                    {{ trans_choice('{1} :count step|[2,*] :count steps', $stepCount, ['count' => $stepCount]) }}@if ($durTxt) · <span class="font-mono">{{ $durTxt }}</span>@endif
                                                    @break
                                                @case('failed')
                                                    <span class="font-semibold text-rose-700">{{ __('Failed') }}</span>@if ($durTxt) · <span class="font-mono">{{ $durTxt }}</span>@endif
                                                    @break
                                                @case('running')
                                                    {{ __('Running…') }}
                                                    @break
                                                @case('skipped')
                                                    {{ __('No steps') }}
                                                    @break
                                                @default
                                                    {{ __('Not started') }}
                                            @endswitch
                                        </span>
                                    </p>
                                </div>
                            </div>

                            @if ($phase['steps'] !== [])
                                <ul class="mt-2 space-y-1 pl-11">
                                    @foreach ($phase['steps'] as $step)
                                        @php($stepFailed = ! $step['ok'] && ! $step['skipped'] && ! ($step['pending'] ?? false))
                                        <li>
                                            <div class="flex items-center gap-2 text-xs">
                                                <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold {{ $step['glyph_classes'] }}">{{ $step['glyph'] }}</span>
                                                <span class="min-w-0 truncate {{ $stepFailed ? 'font-medium text-rose-800' : (($step['pending'] ?? false) ? 'text-brand-mist' : 'text-brand-ink') }}">{{ $step['label'] }}</span>
                                                @if ($step['pending'] ?? false)
                                                    <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] text-brand-moss">{{ __('queued') }}</span>
                                                @elseif ($step['skipped'])
                                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] text-amber-900">{{ __('skipped') }}</span>
                                                @elseif ($step['duration_ms'] > 0)
                                                    <span class="font-mono text-brand-mist">{{ $step['duration_ms'] >= 1000 ? number_format($step['duration_ms'] / 1000, 1).'s' : $step['duration_ms'].'ms' }}</span>
                                                @endif
                                            </div>
                                            @if (($step['output'] ?? '') !== '')
                                                {{-- Any step with output is expandable (failed steps open by default),
                                                     mirroring the slide-over deploy console. --}}
                                                <div x-data="{ open: @js($stepFailed) }" class="mt-1">
                                                    <button type="button" x-on:click="open = ! open"
                                                        class="inline-flex items-center gap-1 text-[10px] font-semibold {{ $stepFailed ? 'text-rose-700' : 'text-brand-moss' }} hover:underline">
                                                        <span class="font-mono" x-text="open ? '▾' : '▸'"></span>
                                                        <span x-text="open ? @js(__('Hide output')) : @js(__('Show output'))"></span>
                                                    </button>
                                                    <pre x-show="open" x-cloak class="mt-1.5 max-h-96 overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-[11px] leading-relaxed {{ $stepFailed ? 'text-rose-100/95' : 'text-brand-cream/90' }}">{{ $step['output'] }}</pre>
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ol>

                @if ($latest->exit_code !== null && $latest->exit_code !== 0)
                    <div class="mt-4 space-y-2">
                        <p class="font-mono text-xs text-rose-700">{{ __('exit :code', ['code' => $latest->exit_code]) }}</p>
                        {{-- A deploy can fail BETWEEN recorded phases (e.g. a thrown
                             exception that never becomes a pipeline step), leaving the
                             timeline with nothing to expand. Surface the captured
                             failure reason from the log so the operator isn't left with
                             a bare exit code. --}}
                        @php($failLog = trim((string) $latest->log_output))
                        @if ($failLog !== '')
                            @php($failTail = mb_strlen($failLog) > 4000 ? '…'.mb_substr($failLog, -4000) : $failLog)
                            <pre class="max-h-60 overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-[11px] leading-relaxed text-rose-100/95">{{ $failTail }}</pre>
                        @endif
                    </div>
                @endif
            @endif
            </div>
        </div>
    </section>

    @if (method_exists($this, 'applyPipelineOptimization'))
        @include('livewire.sites.partials.pipeline._optimize-preview-modal')
    @endif
</div>
