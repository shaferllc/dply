@php $isEmbedded = $embedded ?? false; @endphp
{{-- Single unconditional root: the chrome is chosen INSIDE so Livewire keeps a
     stable wire:id boundary when embedded (see repository.blade.php for why). --}}
<div>
@if (! $isEmbedded)
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @include('livewire.sites.partials.workspace-breadcrumb-bar', [
            'server' => $server,
            'site' => $site,
            'currentLabel' => __('Set up site'),
            'currentIcon' => 'wrench-screwdriver',
        ])

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
@else
            <div class="space-y-6">
@endif
                <div class="flex items-start justify-between gap-4">
                    <x-page-header
                        :title="__('Set up your site')"
                        :description="__('Configure what :name needs, then deploy. Your site stays live on its preview URL the whole time.', ['name' => $site->name])"
                        :show-documentation="false"
                        flush
                        compact
                    />
                    <button type="button" wire:click="configureLater"
                        class="shrink-0 rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-moss transition-colors hover:bg-brand-sand/40 hover:text-brand-ink">
                        {{ __("I'll configure later") }}
                    </button>
                </div>

                @if ($site->isPreflightScanning())
                    {{-- Analyzing: pre-flight clone + scan in flight. Live step
                         timeline driven by meta.setup.scan_step (written by
                         PreflightSiteSetupJob::markScanStep). --}}
                    @php
                        $scanSteps = [
                            'resolving' => ['label' => __('Resolving repository access'), 'desc' => __('Authenticating with your git provider.')],
                            'cloning' => ['label' => __('Cloning the repository'), 'desc' => __('Pulling a shallow copy of your branch.')],
                            'scanning' => ['label' => __('Scanning for configuration'), 'desc' => __('Reading .env.example and code for the variables it needs.')],
                            'detecting' => ['label' => __('Detecting resources'), 'desc' => __('Working out the databases, cache and queues to offer.')],
                        ];
                        $stepKeys = array_keys($scanSteps);
                        $currentStep = (string) data_get($site->meta, 'setup.scan_step', '');
                        $currentIdx = in_array($currentStep, $stepKeys, true) ? (int) array_search($currentStep, $stepKeys, true) : 0;
                        $scanPct = (int) round((($currentIdx + 1) / count($stepKeys)) * 100);
                    @endphp
                    <div wire:poll.2s.visible="pollPreflight" class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm sm:p-8">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-sage/12 text-brand-forest">
                                <x-heroicon-o-magnifying-glass class="h-5 w-5 animate-pulse" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Analyzing your repository…') }}</h2>
                                <p class="text-sm text-brand-moss">{{ __('Reading the code to detect the environment variables and resources it needs.') }}</p>
                            </div>
                        </div>

                        <div class="mt-5 flex items-center gap-3">
                            <div class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-brand-sand/70">
                                <div class="h-full rounded-full bg-brand-forest transition-[width] duration-500" style="width: {{ $scanPct }}%"></div>
                            </div>
                            <span class="shrink-0 text-xs font-semibold tabular-nums text-brand-forest">{{ $scanPct }}%</span>
                        </div>

                        <ol class="mt-5 space-y-2.5">
                            @foreach ($stepKeys as $i => $key)
                                @php $isDone = $i < $currentIdx; $isActive = $i === $currentIdx; @endphp
                                <li class="flex items-start gap-3">
                                    <span @class([
                                        'mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                                        'bg-brand-forest text-brand-cream' => $isDone,
                                        'bg-brand-sage/15 text-brand-forest ring-2 ring-brand-sage/30' => $isActive,
                                        'bg-brand-ink/[0.06] text-brand-mist' => ! $isDone && ! $isActive,
                                    ])>
                                        @if ($isDone)
                                            <x-heroicon-s-check class="h-4 w-4" />
                                        @elseif ($isActive)
                                            <span class="h-2 w-2 animate-pulse rounded-full bg-brand-forest"></span>
                                        @else
                                            {{ $i + 1 }}
                                        @endif
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium {{ $isDone || $isActive ? 'text-brand-ink' : 'text-brand-mist' }}">{{ $scanSteps[$key]['label'] }}</p>
                                        @if ($isActive)
                                            <p class="text-xs text-brand-moss">{{ $scanSteps[$key]['desc'] }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        @if ($site->isPreflightStalled())
                            {{-- The scan heartbeat has gone cold — the job likely
                                 died mid-run. Offer a manual re-scan so the operator
                                 can unstick it and proceed to deploy. --}}
                            <div class="mt-6 flex flex-col gap-2 border-t border-brand-ink/10 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs text-brand-moss">{{ __('This is taking longer than expected. You can re-run the scan to try again.') }}</p>
                                <button type="button" wire:click="rescan" wire:loading.attr="disabled" wire:target="rescan"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60">
                                    <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="rescan" />
                                    <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" wire:loading wire:target="rescan" />
                                    {{ __('Re-scan') }}
                                </button>
                            </div>
                        @endif

                        @php $scanConsole = (array) data_get($site->meta, 'setup_console', []); @endphp
                        @if ($scanConsole !== [])
                            {{-- Live job console: the pre-flight job streams its
                                 progress + any error here (polled with the timeline),
                                 so you can watch what it's doing and see why it stalls. --}}
                            <div class="mt-6 border-t border-brand-ink/10 pt-4">
                                <div class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-moss">
                                    <x-heroicon-o-command-line class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Job console') }}
                                </div>
                                <div
                                    class="max-h-48 overflow-y-auto rounded-xl border border-brand-ink/10 bg-brand-ink/[0.035] p-3 font-mono text-[11px] leading-relaxed text-brand-ink"
                                    x-data
                                    x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true, subtree: true })"
                                >
                                    @foreach ($scanConsole as $entry)
                                        <div class="flex gap-2">
                                            <span class="shrink-0 text-brand-mist">{{ \Illuminate\Support\Carbon::parse($entry['at'] ?? now())->format('H:i:s') }}</span>
                                            <span class="min-w-0 break-words">{{ $entry['line'] ?? '' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    @php
                        // Boot-critical keys still unset — the Review soft gate. The
                        // env editing itself is the real Environment tab (the partial).
                        $missing = $this->missingRequired();
                        $envComplete = empty($missing);
                        $steps = [
                            ['id' => 'environment', 'n' => 1, 'label' => __('Environment & resources'), 'done' => $envComplete],
                            ['id' => 'review', 'n' => 2, 'label' => __('Review & deploy'), 'done' => false],
                        ];
                    @endphp

                    @if ($site->setupScanFailed())
                        <div class="rounded-2xl border border-brand-gold/40 bg-brand-gold/10 px-5 py-4">
                            <div class="flex items-start gap-3">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-brand-rust" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __("Couldn't read your repository") }}</p>
                                    <p class="mt-1 text-sm text-brand-moss">
                                        @switch($site->setupScanFailureReason())
                                            @case('auth')
                                                {{ __('Access was denied — this looks like a private repository. Connect a source-control account or check the deploy credentials, then re-scan.') }}
                                                @break
                                            @case('not_found')
                                                {{ __('The repository could not be found. Double-check the URL and branch, then re-scan.') }}
                                                @break
                                            @case('network')
                                                {{ __('We could not reach the git host (network/timeout). Re-scan to try again.') }}
                                                @break
                                            @case('branch')
                                                {{ __('The branch could not be found in the repository. Check the branch name, then re-scan.') }}
                                                @break
                                            @default
                                                {{ __('Something went wrong reading the repository. You can still enter variables manually, or re-scan.') }}
                                        @endswitch
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" wire:click="rescan"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-medium text-brand-cream hover:bg-brand-forest">
                                            <x-heroicon-o-arrow-path class="h-4 w-4" /> {{ __('Re-scan') }}
                                        </button>
                                        <a href="{{ route('sites.repository', [$server, $site]) }}" wire:navigate
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                                            {{ __('Repository settings') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @php $scanConsole = (array) data_get($site->meta, 'setup_console', []); @endphp
                        @if ($scanConsole !== [])
                            {{-- The pre-flight job's console — the last line is usually the
                                 exact reason it failed. --}}
                            <div class="mt-4">
                                <div class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-moss">
                                    <x-heroicon-o-command-line class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Job console') }}
                                </div>
                                <div class="max-h-48 overflow-y-auto rounded-xl border border-brand-ink/10 bg-brand-ink/[0.035] p-3 font-mono text-[11px] leading-relaxed text-brand-ink"
                                    x-data x-init="$el.scrollTop = $el.scrollHeight">
                                    @foreach ($scanConsole as $entry)
                                        <div class="flex gap-2">
                                            <span class="shrink-0 text-brand-mist">{{ \Illuminate\Support\Carbon::parse($entry['at'] ?? now())->format('H:i:s') }}</span>
                                            <span class="min-w-0 break-words">{{ $entry['line'] ?? '' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif

                    {{-- Stepper --}}
                    <nav class="flex items-center gap-2">
                        @foreach ($steps as $s)
                            <button type="button" wire:click="goToStep('{{ $s['id'] }}')" @class([
                                'flex flex-1 items-center gap-3 rounded-xl border px-4 py-3 text-left transition-colors',
                                'border-brand-forest bg-white shadow-sm ring-1 ring-brand-sage/30' => $step === $s['id'],
                                'border-brand-ink/10 bg-white/60 hover:border-brand-ink/20' => $step !== $s['id'],
                            ])>
                                <span @class([
                                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                    'bg-brand-forest text-brand-cream' => $step === $s['id'],
                                    'bg-brand-sage/15 text-brand-forest' => $step !== $s['id'] && $s['done'],
                                    'bg-brand-ink/[0.06] text-brand-mist' => $step !== $s['id'] && ! $s['done'],
                                ])>
                                    @if ($s['done'] && $step !== $s['id'])
                                        <x-heroicon-s-check class="h-4 w-4" />
                                    @else
                                        {{ $s['n'] }}
                                    @endif
                                </span>
                                <span class="text-sm font-medium {{ $step === $s['id'] ? 'text-brand-ink' : 'text-brand-moss' }}">{{ $s['label'] }}</span>
                            </button>
                        @endforeach
                    </nav>

                    {{-- Step body --}}
                    @if ($step === 'environment')
                        {{-- The Environment step IS the real Environment tab: variables
                             (masked Show/Edit rows, filter chips, import) AND
                             "Connect resource" (attach/provision databases, cache,
                             queue, storage). One editor, identical to Deployments →
                             Environment. Pushes are HELD until deploy (see
                             SiteSetup::autoPushAfterCacheMutation). --}}
                        @include('livewire.sites.settings.partials.environment')

                        <div class="flex items-center justify-end">
                            <button type="button" wire:click="goToStep('review')"
                                class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-medium text-brand-cream hover:bg-brand-forest">
                                {{ __('Continue to review') }} <x-heroicon-o-arrow-right class="h-4 w-4" />
                            </button>
                        </div>
                    @else
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm">
                            {{-- Review & deploy --}}
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Review & deploy') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Confirm and run the first deploy. Your environment is written to the server as the deploy runs.') }}</p>

                            <dl class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-brand-ink/10 p-4">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Repository') }}</dt>
                                    <dd class="mt-1 truncate font-mono text-sm text-brand-ink">{{ $site->git_repository_url }}</dd>
                                    <dd class="text-xs text-brand-moss">{{ __('Branch') }}: {{ $site->git_branch }}</dd>
                                </div>
                                <div class="rounded-xl border border-brand-ink/10 p-4">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Document root') }}</dt>
                                    <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $site->document_root ?: '/' }}</dd>
                                    <dd class="text-xs text-brand-moss">{{ __('Runtime') }}: {{ $site->runtime }}{{ $site->runtime_version ? ' '.$site->runtime_version : '' }}</dd>
                                </div>
                            </dl>

                            @error('deploy')<p class="mt-4 rounded-lg bg-brand-rust/10 px-3 py-2 text-sm text-brand-rust">{{ $message }}</p>@enderror

                            @if (! empty($missing))
                                {{-- Warn, don't block: required vars are flagged but the operator
                                     can deploy anyway and let it fail — their call. --}}
                                <div class="mt-4 rounded-xl border border-brand-gold/40 bg-brand-gold/10 p-4">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __(':count required variable(s) still unset', ['count' => count($missing)]) }}</p>
                                    <p class="mt-1 font-mono text-xs text-brand-moss">{{ implode(', ', $missing) }}</p>
                                    <p class="mt-2 text-xs text-brand-moss">{{ __('You can deploy without them — the deploy will surface the failure if the app needs them.') }}</p>
                                    <button type="button" wire:click="goToStep('environment')" class="mt-2 text-xs font-medium text-brand-rust hover:underline">{{ __('← Set them first') }}</button>
                                </div>
                            @endif

                            <div class="mt-6 flex items-center justify-between">
                                <button type="button" wire:click="goToStep('environment')" class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('← Back') }}</button>
                                <button type="button" wire:click="finishAndDeploy" wire:loading.attr="disabled"
                                    @if (! empty($missing)) wire:confirm="{{ __('Deploy with :count required variable(s) unset? The app may fail to boot until you set them.', ['count' => count($missing)]) }}" @endif
                                    @class([
                                        'inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold transition-colors',
                                        'bg-brand-forest text-brand-cream hover:bg-brand-forest/90' => empty($missing),
                                        'bg-brand-gold text-brand-ink hover:bg-brand-gold/90' => ! empty($missing),
                                    ])>
                                    <x-heroicon-o-rocket-launch class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="finishAndDeploy">{{ empty($missing) ? __('Deploy now') : __('Deploy anyway') }}</span>
                                    <span wire:loading wire:target="finishAndDeploy">{{ __('Starting deploy…') }}</span>
                                </button>
                            </div>
                        </div>
                    @endif
                @endif
@if (! $isEmbedded)
            </main>
        </div>
    </div>
@else
            </div>
@endif
</div>
