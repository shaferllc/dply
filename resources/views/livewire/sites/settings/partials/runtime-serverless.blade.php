@php
    $cfg = $site->serverlessConfig();
    $savedLimits = $site->serverlessLimits();
    $deployedLimits = is_array($cfg['deployed_limits'] ?? null) ? $cfg['deployed_limits'] : null;

    $runtimeKind = trim((string) ($cfg['runtime'] ?? ''));
    $entrypoint = trim((string) ($cfg['entrypoint'] ?? ''));
    $package = trim((string) ($cfg['package'] ?? 'default')) ?: 'default';
    $actionName = trim((string) ($cfg['action_name'] ?? ''));
    $revision = trim((string) ($cfg['last_revision_id'] ?? ''));
    $invocationUrl = trim((string) ($cfg['action_url'] ?? ''));
    $lastDeployedAt = $cfg['last_deployed_at'] ?? null;
    $keepWarm = (bool) ($cfg['keep_warm'] ?? false);
    $neverDeployed = $revision === '';

    // Saved limits live in meta.serverless.limits; deployed_limits is what the
    // deployer last pushed to OpenWhisk. When they diverge the operator has
    // saved changes that won't take effect until the next deploy.
    $pendingRedeploy = $deployedLimits !== null && (
        (int) ($deployedLimits['memory'] ?? 0) !== $savedLimits['memory']
        || (int) ($deployedLimits['timeout'] ?? 0) !== $savedLimits['timeout']
        || (int) ($deployedLimits['concurrency'] ?? 0) !== $savedLimits['concurrency']
    );
@endphp

<div class="space-y-6">
    {{-- 1. Execution profile — what the function is and how it's invoked. --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Function') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Execution profile') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Detected when the artifact is built. Runtime, entrypoint, and build command are edited on the Repository tab.') }}</p>
            </div>
            <a href="{{ route('sites.repository', ['server' => $server, 'site' => $site]) }}" wire:navigate class="inline-flex shrink-0 items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">
                {{ __('Repository') }} →
            </a>
        </div>

        <div class="px-6 py-6 sm:px-7 space-y-5">
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Runtime') }}</dt>
                <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $runtimeKind !== '' ? $runtimeKind : __('Auto-detected on deploy') }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Entrypoint') }}</dt>
                <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $entrypoint !== '' ? $entrypoint : '—' }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Package') }}</dt>
                <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $package }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Action name') }}</dt>
                <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $actionName !== '' ? $actionName : '—' }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Current revision') }}</dt>
                <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $revision !== '' ? $revision : __('Not deployed') }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Last deployed') }}</dt>
                <dd class="mt-2 text-sm text-brand-ink">
                    @if ($lastDeployedAt)
                        <span title="{{ $lastDeployedAt }}">{{ \Illuminate\Support\Carbon::parse($lastDeployedAt)->diffForHumans() }}</span>
                    @else
                        —
                    @endif
                </dd>
            </div>
        </dl>

        @if ($invocationUrl !== '')
            <div x-data="{ copied: false }" class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Invocation URL') }}</dt>
                <div class="mt-2 flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink" title="{{ $invocationUrl }}">{{ $invocationUrl }}</span>
                    <button type="button"
                        class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                        title="{{ __('Copy URL') }}"
                        @click="navigator.clipboard.writeText(@js($invocationUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                    >
                        <x-heroicon-o-clipboard class="h-4 w-4" />
                    </button>
                    <a href="{{ $invocationUrl }}" target="_blank" rel="noreferrer" title="{{ __('Open') }}" class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink">
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                    </a>
                    <span x-show="copied" x-cloak class="shrink-0 text-[10px] font-medium text-brand-forest">{{ __('Copied') }}</span>
                </div>
            </div>
        @endif
        </div>
    </section>

    {{-- 2. Resource limits — the editable control surface. --}}
    <form wire:submit="saveServerlessRuntime" class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Limits') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Resource limits') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('How much the function gets per invocation. These are pushed to the action on the next deploy.') }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7 space-y-6">
        @if ($pendingRedeploy)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p>{{ __('Saved limits differ from what is live (:mem MB · :to · concurrency :cc). Redeploy to apply them.', [
                    'mem' => $deployedLimits['memory'] ?? '—',
                    'to' => isset($deployedLimits['timeout']) ? number_format(((int) $deployedLimits['timeout']) / 1000, 1).'s' : '—',
                    'cc' => $deployedLimits['concurrency'] ?? '—',
                ]) }}</p>
                <button type="button" wire:click="redeployServerlessFunction" wire:loading.attr="disabled" wire:target="redeployServerlessFunction"
                    class="shrink-0 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-ink/90 disabled:opacity-50">
                    <span wire:loading.remove wire:target="redeployServerlessFunction">{{ __('Redeploy now') }}</span>
                    <span wire:loading wire:target="redeployServerlessFunction">{{ __('Starting…') }}</span>
                </button>
            </div>
        @endif

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <x-input-label for="serverless_memory" :value="__('Memory')" />
                <select id="serverless_memory" wire:model="serverless_memory" class="mt-1 block w-full rounded-xl border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                    @foreach (\App\Models\Site::SERVERLESS_MEMORY_OPTIONS_MB as $mb)
                        <option value="{{ $mb }}">{{ $mb }} MB</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-brand-moss">{{ __('RAM ceiling per invocation. CPU scales with memory.') }}</p>
                <x-input-error :messages="$errors->get('serverless_memory')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="serverless_timeout_ms" :value="__('Timeout (ms)')" />
                <x-text-input id="serverless_timeout_ms" type="number" wire:model="serverless_timeout_ms" class="mt-1 block w-full font-mono text-sm"
                    min="{{ \App\Models\Site::SERVERLESS_MIN_TIMEOUT_MS }}" max="{{ \App\Models\Site::SERVERLESS_MAX_TIMEOUT_MS }}" step="1000" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Hard cap before the invocation is killed. Max :max ms (15 min).', ['max' => number_format(\App\Models\Site::SERVERLESS_MAX_TIMEOUT_MS)]) }}</p>
                <x-input-error :messages="$errors->get('serverless_timeout_ms')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="serverless_concurrency" :value="__('Concurrency')" />
                <x-text-input id="serverless_concurrency" type="number" wire:model="serverless_concurrency" class="mt-1 block w-full font-mono text-sm"
                    min="1" max="{{ \App\Models\Site::SERVERLESS_MAX_CONCURRENCY }}" step="1" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Requests one container handles at once before another is spun up.') }}</p>
                <x-input-error :messages="$errors->get('serverless_concurrency')" class="mt-1" />
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-4">
            <p class="text-xs text-brand-moss">
                @if ($neverDeployed)
                    {{ __('Saved limits apply on the first deploy.') }}
                @else
                    {{ __('Saving stores the limits — they take effect on the next deploy.') }}
                @endif
            </p>
            <x-primary-button type="submit">
                <span wire:loading.remove wire:target="saveServerlessRuntime">{{ __('Save limits') }}</span>
                <span wire:loading wire:target="saveServerlessRuntime">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
        </div>
    </form>

    {{-- 3. Cold starts — keep-warm is owned by the Workers tab; surface its state here. --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Latency') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cold starts') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Keep-warm is currently') }}
                    <span class="font-semibold {{ $keepWarm ? 'text-brand-forest' : 'text-brand-ink' }}">{{ $keepWarm ? __('on') : __('off') }}</span>.
                    {{ $keepWarm
                        ? __('A scheduled ping holds a container warm to cut cold-start latency.')
                        : __('The first request after idle pays the framework cold-start cost.') }}
                </p>
            </div>
            <a href="{{ route('sites.workers', ['server' => $server, 'site' => $site]) }}" wire:navigate class="inline-flex shrink-0 items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">
                {{ __('Workers') }} →
            </a>
        </div>
    </section>

    {{-- 4. CLI parity --}}
    <x-cli-snippet :commands="[
        ['label' => __('Deploy / redeploy the function'), 'command' => 'dply sites:deploy '.$site->slug],
    ]" />
</div>
