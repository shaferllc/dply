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

            <main class="min-w-0 lg:col-span-9">
                <section class="dply-card min-w-0 overflow-hidden p-0">
                    <x-workspace-panel-head
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-wrench-screwdriver"
                        :title="__('Set up your site')"
                        :note="__('Configure what :name needs, then deploy. Your site stays live on its preview URL the whole time.', ['name' => $site->name])"
                    >
                        <x-slot:actions>
                            <button type="button" wire:click="configureLater" class="dply-btn dply-btn-xs dply-btn-outline">
                                {{ __("I'll configure later") }}
                            </button>
                        </x-slot:actions>
                    </x-workspace-panel-head>
@else
                {{-- Embedded as the Repository "Set up" tab — host card already
                     provides the page header, so keep a compact strip only. --}}
                <div>
                    <x-workspace-panel-head
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-wrench-screwdriver"
                        :title="__('Set up your site')"
                        :note="__('Configure what :name needs, then deploy. Your site stays live on its preview URL the whole time.', ['name' => $site->name])"
                    >
                        <x-slot:actions>
                            <button type="button" wire:click="configureLater" class="dply-btn dply-btn-xs dply-btn-outline">
                                {{ __("I'll configure later") }}
                            </button>
                        </x-slot:actions>
                    </x-workspace-panel-head>
@endif

                @if ($site->isPreflightScanning())
                    {{-- Analyzing: pre-flight clone + scan in flight. Live step
                         timeline driven by meta.setup.scan_step (written by
                         PreflightSiteSetupJob::markScanStep). --}}
                    @php
                        $scanSteps = [
                            'resolving' => ['label' => __('Resolving repository access'), 'desc' => __('Verifying credentials and obtaining an authenticated clone URL.')],
                            'cloning' => ['label' => __('Cloning the repository'), 'desc' => __('Fetching a shallow copy of your branch (depth 1).')],
                            'scanning' => ['label' => __('Scanning for configuration'), 'desc' => __('Reading .env.example, app code, and config for every env() reference.')],
                            'detecting' => ['label' => __('Detecting resources'), 'desc' => __('Checking which boot-critical variables need values before the first deploy.')],
                        ];
                        $stepKeys = array_keys($scanSteps);
                        $stepCount = count($stepKeys);
                        $currentStep = (string) data_get($site->meta, 'setup.scan_step', '');
                        $currentIdx = in_array($currentStep, $stepKeys, true) ? (int) array_search($currentStep, $stepKeys, true) : 0;
                        $scanPct = (int) round((($currentIdx + 1) / $stepCount) * 100);
                        $scanConsole = (array) data_get($site->meta, 'setup_console', []);

                        // Elapsed time needs a start instant. `rescan` writes
                        // setup.started_at, but the *first* run is kicked off by
                        // PreflightSiteSetupJob, which doesn't — so fall back to the
                        // first console line, stamped as that run begins.
                        $startedAtRaw = data_get($site->meta, 'setup.started_at') ?? data_get($scanConsole, '0.at');
                        try {
                            $startedMs = $startedAtRaw ? \Illuminate\Support\Carbon::parse($startedAtRaw)->getTimestampMs() : null;
                        } catch (\Throwable) {
                            $startedMs = null;
                        }
                    @endphp
                    <div
                        wire:poll.2s.visible="pollPreflight"
                        role="status"
                        aria-live="polite"
                        class="relative border-b border-brand-ink/10"
                    >
                        {{-- Progress rides the strip's top edge as a hairline. --}}
                        <div
                            class="absolute inset-x-0 top-0 h-0.5 bg-brand-sand/70"
                            role="progressbar"
                            aria-valuenow="{{ $scanPct }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="{{ __('Repository analysis progress') }}"
                        >
                            <div class="h-full bg-brand-forest transition-[width] duration-700 ease-out" style="width: {{ $scanPct }}%"></div>
                        </div>

                        <x-workspace-panel-head
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-magnifying-glass"
                            :title="__('Analyzing your repository')"
                            :note="__('Reading the code to detect the environment variables and resources it needs.')"
                        >
                            <x-slot:actions>
                                <div class="flex shrink-0 items-center gap-1.5 text-[11px] font-medium text-brand-moss">
                                    <span class="rounded-full bg-white px-2 py-0.5 tabular-nums ring-1 ring-brand-ink/10">
                                        {{ __('Step :n of :total', ['n' => $currentIdx + 1, 'total' => $stepCount]) }}
                                    </span>
                                    @if ($startedMs !== null)
                                        <span
                                            class="flex items-center gap-1 rounded-full bg-white px-2 py-0.5 tabular-nums ring-1 ring-brand-ink/10"
                                            x-data="{
                                                started: {{ $startedMs }},
                                                now: Date.now(),
                                                timer: null,
                                                init() { this.timer = setInterval(() => this.now = Date.now(), 1000) },
                                                destroy() { clearInterval(this.timer) },
                                            }"
                                        >
                                            <x-heroicon-o-clock class="h-3 w-3" />
                                            <span x-text="new Date(Math.max(0, now - started)).toISOString().slice(14, 19)">0:00</span>
                                        </span>
                                    @endif
                                </div>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        <div class="px-5 py-4 sm:px-6">
                            <ol>
                                @foreach ($stepKeys as $i => $key)
                                    @php
                                        $isDone = $i < $currentIdx;
                                        $isActive = $i === $currentIdx;
                                        $isLast = $i === $stepCount - 1;
                                    @endphp
                                    <li class="relative flex gap-3 {{ $isLast ? '' : 'pb-3.5' }}">
                                        @unless ($isLast)
                                            <span
                                                class="absolute bottom-0 left-2.5 top-6 w-px -translate-x-1/2 {{ $isDone ? 'bg-brand-forest/35' : 'bg-brand-ink/10' }}"
                                                aria-hidden="true"
                                            ></span>
                                        @endunless

                                        <span class="relative z-10 mt-0.5 shrink-0">
                                            @if ($isDone)
                                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-brand-forest text-brand-cream">
                                                    <x-heroicon-s-check class="h-3 w-3" />
                                                </span>
                                            @elseif ($isActive)
                                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-brand-sage/15 ring-2 ring-brand-sage/40">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-brand-forest motion-safe:animate-pulse"></span>
                                                </span>
                                            @else
                                                <span class="block h-5 w-5 rounded-full border border-dashed border-brand-ink/20"></span>
                                            @endif
                                        </span>

                                        <div class="min-w-0">
                                            <p @class([
                                                'text-sm font-medium',
                                                'text-brand-ink' => $isDone || $isActive,
                                                'text-brand-mist' => ! $isDone && ! $isActive,
                                            ])>{{ $scanSteps[$key]['label'] }}</p>
                                            <p @class([
                                                'mt-0.5 text-xs',
                                                'text-brand-moss' => $isDone || $isActive,
                                                'text-brand-mist/70' => ! $isDone && ! $isActive,
                                            ])>{{ $scanSteps[$key]['desc'] }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>

                            @if ($site->isPreflightStalled())
                                <div class="mt-4 flex flex-col gap-2.5 rounded-lg border border-amber-200 bg-amber-50/80 p-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex min-w-0 items-start gap-2">
                                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" />
                                        <p class="text-xs text-amber-900">{{ __('This is taking longer than expected. You can re-run the scan to try again.') }}</p>
                                    </div>
                                    <button type="button" wire:click="rescan" wire:loading.attr="disabled" wire:target="rescan"
                                        class="dply-btn dply-btn-xs dply-btn-outline shrink-0">
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="rescan" />
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="rescan" />
                                        {{ __('Re-scan') }}
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- Live job console — always mounted so the card doesn't
                             reflow when the first line arrives. --}}
                        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-3 sm:px-6">
                            <div class="mb-1.5 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-moss">
                                    <x-heroicon-o-command-line class="h-3.5 w-3.5" />
                                    {{ __('Job console') }}
                                </div>
                                <span class="flex items-center gap-1.5 text-[11px] font-medium text-brand-moss">
                                    <span class="h-1.5 w-1.5 rounded-full bg-brand-forest motion-safe:animate-pulse" aria-hidden="true"></span>
                                    {{ __('Live') }}
                                </span>
                            </div>
                            <div
                                class="max-h-52 min-h-[3.5rem] overflow-y-auto rounded-lg border border-brand-ink/10 bg-white/70 p-2.5 font-mono text-[11px] leading-relaxed text-brand-ink"
                                x-data
                                x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true, subtree: true })"
                            >
                                @forelse ($scanConsole as $entry)
                                    @php $line = $entry['line'] ?? ''; $isIndent = str_starts_with($line, '  →'); @endphp
                                    <div class="flex gap-2">
                                        <span class="shrink-0 text-brand-mist">{{ \Illuminate\Support\Carbon::parse($entry['at'] ?? now())->format('H:i:s') }}</span>
                                        <span class="{{ $isIndent ? 'text-brand-rust' : '' }} min-w-0 break-words">{{ $line }}</span>
                                    </div>
                                @empty
                                    <p class="text-brand-mist">{{ __('Waiting for the first line…') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @else
                    @php
                        // Boot-critical keys still unset — the Review soft gate. The
                        // env editing itself is the real Environment tab (the partial).
                        $missing = $this->missingRequired();
                        $envComplete = empty($missing);

                        // The Resources step is conditional: only present when the
                        // scan suggested resources to connect. When it's absent the
                        // Environment step still carries inline "Connect resource".
                        $hasResources = $this->hasResourceStep();
                        $unsatisfiedResources = $hasResources ? $this->unsatisfiedResources() : [];

                        $steps = [];
                        $n = 1;
                        if ($hasResources) {
                            $steps[] = ['id' => 'resources', 'n' => $n++, 'label' => __('Resources'), 'done' => empty($unsatisfiedResources)];
                        }
                        $steps[] = ['id' => 'environment', 'n' => $n++, 'label' => $hasResources ? __('Environment') : __('Environment & resources'), 'done' => $envComplete];
                        $steps[] = ['id' => 'review', 'n' => $n++, 'label' => __('Review & deploy'), 'done' => false];
                    @endphp

                    @if ($site->setupScanFailed())
                        <div class="border-b border-brand-ink/10 bg-amber-50/60 px-5 py-3.5 sm:px-6">
                            <div class="flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-900 ring-1 ring-amber-200">
                                    <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __("Couldn't read your repository") }}</p>
                                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
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
                                    <div class="mt-2.5 flex flex-wrap gap-1.5">
                                        <button type="button" wire:click="rescan" class="dply-btn dply-btn-xs dply-btn-primary">
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" /> {{ __('Re-scan') }}
                                        </button>
                                        <a href="{{ route('sites.repository', [$server, $site]) }}" wire:navigate
                                            class="dply-btn dply-btn-xs dply-btn-outline">
                                            {{ __('Repository settings') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @php $scanConsole = (array) data_get($site->meta, 'setup_console', []); @endphp
                        @if ($scanConsole !== [])
                            <div class="border-b border-brand-ink/10 bg-brand-sand/25 px-5 py-3 sm:px-6">
                                <div class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-moss">
                                    <x-heroicon-o-command-line class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Job console') }}
                                </div>
                                <div class="max-h-52 overflow-y-auto rounded-lg border border-brand-ink/10 bg-white/70 p-2.5 font-mono text-[11px] leading-relaxed text-brand-ink"
                                    x-data x-init="$el.scrollTop = $el.scrollHeight">
                                    @foreach ($scanConsole as $entry)
                                        @php $line = $entry['line'] ?? ''; $isIndent = str_starts_with($line, '  →'); @endphp
                                        <div class="flex gap-2">
                                            <span class="shrink-0 text-brand-mist">{{ \Illuminate\Support\Carbon::parse($entry['at'] ?? now())->format('H:i:s') }}</span>
                                            <span class="{{ $isIndent ? 'text-brand-rust' : '' }} min-w-0 break-words">{{ $line }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif

                    {{-- Stepper as a compact segmented control in a flush strip. --}}
                    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                        <nav class="inline-flex max-w-full items-center gap-0.5 overflow-x-auto rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-0.5">
                            @foreach ($steps as $s)
                                <button type="button" wire:click="goToStep('{{ $s['id'] }}')" @class([
                                    'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium whitespace-nowrap transition-colors',
                                    'bg-white text-brand-ink shadow-sm' => $step === $s['id'],
                                    'text-brand-moss hover:text-brand-ink' => $step !== $s['id'],
                                ])>
                                    <span @class([
                                        'flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold',
                                        'bg-brand-forest text-brand-cream' => $step === $s['id'],
                                        'bg-brand-sage/25 text-brand-forest' => $step !== $s['id'] && $s['done'],
                                        'bg-brand-ink/[0.08] text-brand-mist' => $step !== $s['id'] && ! $s['done'],
                                    ])>
                                        @if ($s['done'] && $step !== $s['id'])
                                            <x-heroicon-s-check class="h-2.5 w-2.5" />
                                        @else
                                            {{ $s['n'] }}
                                        @endif
                                    </span>
                                    {{ $s['label'] }}
                                </button>
                            @endforeach
                        </nav>
                    </div>

                    {{-- Step body --}}
                    @if ($step === 'resources')
                        @include('livewire.sites.settings.partials.environment.resources-step')
                    @elseif ($step === 'environment')
                        {{-- Same merged-chrome Environment editor as Deployments →
                             Environment. Pushes are HELD until deploy (see
                             SiteSetup::autoPushAfterCacheMutation). --}}
                        @include('livewire.sites.settings.partials.environment', ['envMergedChrome' => true])

                        <div class="flex items-center justify-end border-t border-brand-ink/10 px-5 py-3 sm:px-6">
                            <button type="button" wire:click="goToStep('review')" class="dply-btn dply-btn-sm dply-btn-primary">
                                {{ __('Continue to review') }} <x-heroicon-o-arrow-right class="h-4 w-4" />
                            </button>
                        </div>
                    @else
                        <div class="border-b border-brand-ink/10">
                            <x-workspace-panel-head
                                class="border-b border-brand-ink/10"
                                icon="heroicon-o-rocket-launch"
                                :title="__('Review & deploy')"
                                :note="__('Confirm and run the first deploy. Your environment is written to the server as the deploy runs.')"
                            />

                            <div class="px-5 py-4 sm:px-6">
                                <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <div class="rounded-lg border border-brand-ink/10 px-3 py-2.5">
                                        <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Repository') }}</dt>
                                        <dd class="mt-0.5 truncate font-mono text-sm text-brand-ink">{{ $site->git_repository_url }}</dd>
                                        <dd class="text-xs text-brand-moss">{{ __('Branch') }}: {{ $site->git_branch }}</dd>
                                    </div>
                                    <div class="rounded-lg border border-brand-ink/10 px-3 py-2.5">
                                        <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Document root') }}</dt>
                                        <dd class="mt-0.5 font-mono text-sm text-brand-ink">{{ $site->document_root ?: '/' }}</dd>
                                        <dd class="text-xs text-brand-moss">{{ __('Runtime') }}: {{ $site->runtime }}{{ $site->runtime_version ? ' '.$site->runtime_version : '' }}</dd>
                                    </div>
                                </dl>

                                @error('deploy')
                                    <p class="mt-3 rounded-lg bg-brand-rust/10 px-3 py-2 text-xs text-brand-rust">{{ $message }}</p>
                                @enderror

                                @if (empty($missing))
                                    <div class="mt-3 flex items-center gap-2 rounded-lg border border-brand-sage/30 bg-brand-sage/10 px-3 py-2">
                                        <x-heroicon-o-check-circle class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                                        <p class="text-xs text-brand-forest">{{ __('All required variables are set. Ready to deploy.') }}</p>
                                    </div>
                                @else
                                    {{-- Warn, don't block: required vars are flagged but the operator
                                         can deploy anyway and let it fail — their call. --}}
                                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50/80 p-3">
                                        <p class="text-xs font-semibold text-brand-ink">{{ __(':count required variable(s) still unset', ['count' => count($missing)]) }}</p>
                                        <p class="mt-1 font-mono text-[11px] text-brand-moss">{{ implode(', ', $missing) }}</p>
                                        <p class="mt-1.5 text-[11px] text-brand-moss">{{ __('You can deploy without them — the deploy will surface the failure if the app needs them.') }}</p>
                                        <button type="button" wire:click="goToStep('environment')" class="mt-1.5 text-[11px] font-semibold text-brand-forest hover:underline">{{ __('← Set them first') }}</button>
                                    </div>
                                @endif

                                @if (! empty($unsatisfiedResources))
                                    <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50/80 p-3">
                                        <p class="text-xs font-semibold text-brand-ink">{{ __(':count detected resource(s) not connected', ['count' => count($unsatisfiedResources)]) }}</p>
                                        <p class="mt-1 text-[11px] text-brand-moss">{{ implode(', ', array_map(fn ($r) => $r['label'], $unsatisfiedResources)) }}</p>
                                        <p class="mt-1.5 text-[11px] text-brand-moss">{{ __('You can deploy without them — set their variables by hand, or connect the resource now.') }}</p>
                                        <button type="button" wire:click="goToStep('resources')" class="mt-1.5 text-[11px] font-semibold text-brand-forest hover:underline">{{ __('← Connect resources') }}</button>
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-center justify-between border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-3 sm:px-6">
                                <button type="button" wire:click="goToStep('environment')" class="text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('← Back') }}</button>
                                <button type="button" wire:loading.attr="disabled" wire:target="finishAndDeploy"
                                    @if (! empty($missing))
                                        wire:click="openConfirmActionModal('finishAndDeploy', [], @js(__('Deploy with required variables unset?')), @js(__('Deploy with :count required variable(s) unset? The app may fail to boot until you set them.', ['count' => count($missing)])), @js(__('Deploy anyway')), false, @js([['label' => __('Unset variables'), 'value' => implode(', ', $missing), 'mono' => true]]))"
                                    @else
                                        wire:click="finishAndDeploy"
                                    @endif
                                    @class([
                                        'dply-btn dply-btn-sm group',
                                        'dply-btn-primary' => empty($missing),
                                        'bg-brand-gold text-brand-ink hover:bg-brand-gold/90' => ! empty($missing),
                                    ])>
                                    <x-heroicon-o-rocket-launch class="h-4 w-4 transition-transform duration-150 group-hover:-translate-y-0.5 group-hover:translate-x-0.5" wire:loading.remove wire:target="finishAndDeploy" />
                                    <svg wire:loading wire:target="finishAndDeploy" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                    <span wire:loading.remove wire:target="finishAndDeploy">{{ empty($missing) ? __('Deploy now') : __('Deploy anyway') }}</span>
                                    <span wire:loading wire:target="finishAndDeploy">{{ __('Starting deploy…') }}</span>
                                </button>
                            </div>
                        </div>
                    @endif
                @endif

@if (! $isEmbedded)
                </section>
            </main>
        </div>
    </div>
@else
                </div>
@endif

{{-- The env partial's Remove/Sync actions open the shared confirm modal; render
     it so the confirmation dialog actually appears (otherwise removal silently
     no-ops). --}}
@include('livewire.partials.confirm-action-modal')
</div>
