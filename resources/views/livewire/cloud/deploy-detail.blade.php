<div class="mx-auto max-w-5xl px-6 py-10">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Cloud apps'), 'href' => route('cloud.index'), 'icon' => 'cloud'],
        ['label' => $site->name, 'href' => route('sites.show', ['server' => $server, 'site' => $site])],
        ['label' => __('Deploy :id', ['id' => substr($deployId, 0, 8)])],
    ]" />

    <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-1.5">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                <x-heroicon-o-rocket-launch class="h-3 w-3" aria-hidden="true" />
                {{ __('Deploy') }}
            </span>
            <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ $site->name }}</h1>
            <p class="font-mono text-xs text-brand-mist">{{ $deployId }}</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="refreshDeployment" wire:loading.attr="disabled" wire:target="refreshDeployment" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink transition hover:bg-brand-cream/40">
                <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                <span wire:loading.remove wire:target="refreshDeployment">{{ __('Refresh') }}</span>
                <span wire:loading wire:target="refreshDeployment">{{ __('Refreshing…') }}</span>
            </button>
            @php
                $inProgress = is_array($deployment) && in_array((string) ($deployment['phase'] ?? ''), ['PENDING_BUILD', 'BUILDING', 'PENDING_DEPLOY', 'DEPLOYING'], true);
            @endphp
            @if ($inProgress)
                <button type="button" wire:click="cancelDeploy" wire:loading.attr="disabled" wire:target="cancelDeploy" wire:confirm="{{ __('Cancel this in-progress deploy?') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800 transition hover:bg-rose-100">
                    <x-heroicon-o-x-circle class="h-4 w-4" aria-hidden="true" />
                    <span wire:loading.remove wire:target="cancelDeploy">{{ __('Cancel deploy') }}</span>
                    <span wire:loading wire:target="cancelDeploy">{{ __('Canceling…') }}</span>
                </button>
            @endif
        </div>
    </header>

    @if ($loadError)
        <div class="dply-card flex items-start gap-3 p-5">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-brand-rust" aria-hidden="true" />
            <p class="text-sm text-brand-moss">{{ $loadError }}</p>
        </div>
    @elseif (is_array($deployment))
        @php
            $phase = (string) ($deployment['phase'] ?? 'UNKNOWN');
            $phaseClass = match ($phase) {
                'ACTIVE' => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700', 'pulse' => false],
                'BUILDING', 'DEPLOYING', 'PENDING_BUILD', 'PENDING_DEPLOY' => ['dot' => 'bg-sky-500', 'text' => 'text-sky-700', 'pulse' => true],
                'ERROR', 'FAILED', 'CANCELED' => ['dot' => 'bg-rose-500', 'text' => 'text-rose-700', 'pulse' => false],
                'SUPERSEDED' => ['dot' => 'bg-brand-mist', 'text' => 'text-brand-moss', 'pulse' => false],
                default => ['dot' => 'bg-brand-mist', 'text' => 'text-brand-moss', 'pulse' => false],
            };
            $cause = $deployment['cause_details']['type'] ?? ($deployment['cause'] ?? null);
            $progress = is_array($deployment['progress'] ?? null) ? $deployment['progress'] : [];
            $steps = is_array($progress['steps'] ?? null) ? $progress['steps'] : [];
            $services = is_array($deployment['services'] ?? null) ? $deployment['services'] : [];
            $workers = is_array($deployment['workers'] ?? null) ? $deployment['workers'] : [];
            $jobs = is_array($deployment['jobs'] ?? null) ? $deployment['jobs'] : [];
        @endphp

        {{-- Summary --}}
        <div class="dply-card mb-5 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex items-center gap-2">
                    <span class="relative inline-flex h-2.5 w-2.5">
                        @if ($phaseClass['pulse'])
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full {{ $phaseClass['dot'] }} opacity-60"></span>
                        @endif
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full {{ $phaseClass['dot'] }}"></span>
                    </span>
                    <span class="text-sm font-semibold {{ $phaseClass['text'] }}">{{ str_replace('_', ' ', $phase) }}</span>
                </div>
                @if ($cause)
                    <span class="text-xs text-brand-moss">{{ __('Trigger:') }} <span class="font-mono">{{ str_replace('_', ' ', strtolower((string) $cause)) }}</span></span>
                @endif
            </div>
            <dl class="mt-4 grid gap-4 text-xs sm:grid-cols-3">
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Created') }}</dt>
                    <dd class="mt-0.5 font-mono text-brand-ink">{{ $deployment['created_at'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Started') }}</dt>
                    <dd class="mt-0.5 font-mono text-brand-ink">{{ $deployment['started_at'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Finished') }}</dt>
                    <dd class="mt-0.5 font-mono text-brand-ink">{{ $deployment['phase_last_updated_at'] ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- Phases timeline --}}
        @if ($steps !== [])
            <div class="dply-card mb-5 overflow-hidden">
                <header class="flex items-center gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Pipeline') }}</span>
                </header>
                <ol class="divide-y divide-brand-ink/10">
                    @foreach ($steps as $step)
                        @php
                            $stepStatus = (string) ($step['status'] ?? 'UNKNOWN');
                            $stepIcon = match ($stepStatus) {
                                'SUCCESS' => ['icon' => 'check-circle', 'class' => 'text-emerald-600'],
                                'RUNNING' => ['icon' => 'arrow-path', 'class' => 'text-sky-600 animate-spin'],
                                'ERROR', 'FAILED' => ['icon' => 'x-circle', 'class' => 'text-rose-600'],
                                'PENDING' => ['icon' => 'clock', 'class' => 'text-brand-mist'],
                                default => ['icon' => 'minus-circle', 'class' => 'text-brand-mist'],
                            };
                        @endphp
                        <li class="flex items-center justify-between gap-3 px-6 py-3 text-sm">
                            <div class="flex items-center gap-3">
                                @switch($stepIcon['icon'])
                                    @case('check-circle') <x-heroicon-o-check-circle class="h-4 w-4 {{ $stepIcon['class'] }}" /> @break
                                    @case('arrow-path') <x-heroicon-o-arrow-path class="h-4 w-4 {{ $stepIcon['class'] }}" /> @break
                                    @case('x-circle') <x-heroicon-o-x-circle class="h-4 w-4 {{ $stepIcon['class'] }}" /> @break
                                    @case('clock') <x-heroicon-o-clock class="h-4 w-4 {{ $stepIcon['class'] }}" /> @break
                                    @default <x-heroicon-o-minus-circle class="h-4 w-4 {{ $stepIcon['class'] }}" />
                                @endswitch
                                <span class="font-medium text-brand-ink">{{ str_replace('_', ' ', strtolower((string) ($step['name'] ?? 'step'))) }}</span>
                            </div>
                            <span class="text-xs font-mono text-brand-mist">{{ $stepStatus }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        {{-- Per-component status --}}
        @if ($services !== [] || $workers !== [] || $jobs !== [])
            <div class="dply-card mb-5 overflow-hidden">
                <header class="flex items-center gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                    <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Components') }}</span>
                </header>
                <ul class="divide-y divide-brand-ink/10 text-sm">
                    @foreach (['services' => __('Service'), 'workers' => __('Worker'), 'jobs' => __('Job')] as $key => $label)
                        @foreach (${$key} as $component)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="rounded-full bg-brand-cream/70 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ $label }}</span>
                                    <span class="truncate font-medium text-brand-ink">{{ $component['name'] ?? '—' }}</span>
                                </div>
                                <span class="font-mono text-xs text-brand-mist">{{ $component['source_commit_hash'] ?? $component['source_image_digest'] ?? '—' }}</span>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Raw payload (collapsed by default) --}}
        <details class="dply-card overflow-hidden">
            <summary class="cursor-pointer border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                {{ __('Raw DO response') }}
            </summary>
            <pre class="max-h-96 overflow-auto bg-slate-900 p-4 font-mono text-[10px] leading-5 text-slate-100">{{ json_encode($deployment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @else
        <p class="text-sm text-brand-moss">{{ __('Loading deployment…') }}</p>
    @endif
</div>
