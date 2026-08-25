{{--
    Shared URL-first runtime detection panel.

    Keyed entirely off the $detectedPlan array populated by the
    DetectsRepositoryRuntime concern. Rendered identically by the VM site,
    and Cloud container create flows.

    Optional include data:
      - $detectionInstallable (bool) — render the VM-only "install runtime on
        this server" affordance. Only the VM site flow (which has a $server)
        passes this; defaults to false everywhere else.
--}}
@php($detectionInstallable = $detectionInstallable ?? false)

@if (! empty($detectedPlan['error']))
    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
        <p class="font-medium">{{ __('Could not clone the repository.') }}</p>
        <p class="mt-1 font-mono text-xs">{{ $detectedPlan['error'] }}</p>
    </div>
@elseif (! empty($detectedPlan['no_match']))
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-medium">{{ __('No runtime detected.') }}</p>
        <p class="mt-1">{{ __('No dply.yaml manifest and no recognized runtime signals (composer.json, package.json, requirements.txt, Gemfile, go.mod, index.html, etc.) at the repo root.') }}</p>
    </div>
@elseif (! empty($detectedPlan['runtime']))
    <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 text-sm text-emerald-950 space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/70 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-emerald-900">
                {{ $detectedPlan['runtime'] ?: ($detectedPlan['framework'] ?? '') }}
                @if (! empty($detectedPlan['framework']) && $detectedPlan['framework'] !== ($detectedPlan['runtime'] ?? null))
                    · {{ $detectedPlan['framework'] }}
                @endif
            </span>
            @if (! empty($detectedPlan['version']))
                <span class="inline-flex items-center gap-1 rounded-full bg-white/70 px-3 py-1 font-mono text-xs text-emerald-900">{{ $detectedPlan['version'] }}</span>
            @endif
            @if (! empty($detectedPlan['confidence']))
                <span class="inline-flex items-center gap-1 rounded-full bg-white/40 px-3 py-1 text-xs uppercase tracking-[0.16em] text-emerald-900/80">{{ $detectedPlan['confidence'] }} confidence</span>
            @endif
            @if (! empty($detectedPlan['has_manifest']))
                <span class="inline-flex items-center gap-1 rounded-full bg-white/40 px-3 py-1 text-xs uppercase tracking-[0.16em] text-emerald-900/80">{{ __('dply.yaml present') }}</span>
            @endif
        </div>

        <dl class="grid gap-3 sm:grid-cols-2">
            @if (! empty($detectedPlan['build_command']))
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Build command') }}</dt>
                    <dd class="mt-1 font-mono text-xs text-emerald-950 break-all">{{ $detectedPlan['build_command'] }}</dd>
                </div>
            @endif
            @if (! empty($detectedPlan['output_dir']))
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Output directory') }}</dt>
                    <dd class="mt-1 font-mono text-xs text-emerald-950 break-all">{{ $detectedPlan['output_dir'] }}</dd>
                </div>
            @endif
            @if (! empty($detectedPlan['start_command']))
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Start command') }}</dt>
                    <dd class="mt-1 font-mono text-xs text-emerald-950 break-all">{{ $detectedPlan['start_command'] }}</dd>
                </div>
            @endif
        </dl>

        @if (! empty($detectedPlan['processes']))
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800">{{ __('Suggested processes') }}</p>
                <ul class="mt-1 space-y-1 text-xs">
                    @foreach ($detectedPlan['processes'] as $process)
                        <li class="flex items-start gap-2">
                            <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full bg-white/70 px-2 py-0.5 font-semibold uppercase tracking-[0.12em] text-2xs text-emerald-900">{{ $process['type'] }}</span>
                            <span><span class="font-semibold">{{ $process['name'] }}</span> — <span class="font-mono">{{ $process['command'] }}</span></span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (! empty($detectedPlan['reasons']))
            <details class="text-xs text-emerald-900/80">
                <summary class="cursor-pointer font-semibold uppercase tracking-[0.16em]">{{ __('Detection details') }}</summary>
                <ul class="mt-2 space-y-1 pl-3 list-disc">
                    @foreach ($detectedPlan['reasons'] as $reason)
                        <li>{!! \Illuminate\Support\Str::of($reason)->replaceMatches('/`([^`]+)`/', '<code class="font-mono">$1</code>') !!}</li>
                    @endforeach
                </ul>
            </details>
        @endif

        @if (! empty($detectedPlan['warnings']))
            <ul class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 space-y-1">
                @foreach ($detectedPlan['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif

        @if ($detectionInstallable && $this->detectedRuntimeNeedsInstall)
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
                <p class="font-medium">{{ __('Heads up: this server hasn\'t pinned :runtime yet.', ['runtime' => ucfirst((string) $detectedPlan['runtime'])]) }}</p>
                <p class="mt-1 text-xs">{{ __('mise will install it on demand at deploy time, but you can preinstall now to keep the first deploy fast.') }}</p>
                <button
                    type="button"
                    wire:click="installDetectedRuntimeOnServer"
                    wire:loading.attr="disabled"
                    wire:target="installDetectedRuntimeOnServer"
                    class="mt-3 inline-flex items-center justify-center rounded-lg bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-sky-800 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="installDetectedRuntimeOnServer">{{ __('Install :runtime :version on this server', ['runtime' => ucfirst((string) $detectedPlan['runtime']), 'version' => $detectedPlan['version'] ?? '']) }}</span>
                    <span wire:loading wire:target="installDetectedRuntimeOnServer">{{ __('Installing…') }}</span>
                </button>
            </div>
        @endif

        @if ($detectionInstallable && ! empty($runtimeInstallResult))
            <div class="rounded-xl border {{ ($runtimeInstallResult['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900' }} p-3 text-xs">
                {{ $runtimeInstallResult['message'] ?? '' }}
            </div>
        @endif
    </div>
@else
    {{--
        Nothing detected yet. Several call sites wrap this include in a card, so a
        silent render leaves an empty box on the page — give it an empty state
        instead. Borderless so it reads as the *contents* of that wrapper card
        rather than a second nested box.
    --}}
    <x-empty-state
        borderless
        compact
        tone="sage"
        icon="heroicon-o-sparkles"
        :title="__('No runtime detected yet')"
        :description="__('Pick a repository and branch, then run detection — dply inspects the repo and previews the runtime, build command, and output directory before you deploy.')"
    />
@endif
