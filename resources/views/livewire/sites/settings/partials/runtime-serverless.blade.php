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

    // Read-only facts about the deployed action. Flat rows in one bordered grid
    // rather than six free-floating cards — six values is a spec sheet, not six
    // things worth a card each.
    $facts = [
        ['label' => __('Runtime'), 'value' => $runtimeKind !== '' ? $runtimeKind : __('Auto-detected on deploy'), 'mono' => $runtimeKind !== ''],
        ['label' => __('Entrypoint'), 'value' => $entrypoint !== '' ? $entrypoint : '—', 'mono' => true],
        ['label' => __('Package'), 'value' => $package, 'mono' => true],
        ['label' => __('Action name'), 'value' => $actionName !== '' ? $actionName : '—', 'mono' => true],
        ['label' => __('Revision'), 'value' => $revision !== '' ? $revision : __('Not deployed'), 'mono' => $revision !== ''],
        [
            'label' => __('Last deployed'),
            'value' => $lastDeployedAt ? \Illuminate\Support\Carbon::parse($lastDeployedAt)->diffForHumans() : '—',
            'mono' => false,
            'title' => $lastDeployedAt,
        ],
    ];
@endphp

<div class="min-w-0">
    {{-- 1. Execution profile — what the function is and how it's invoked. --}}
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-bolt"
            :title="__('Execution profile')"
            :note="__('Detected when the artifact is built. Runtime, entrypoint, and build command are edited on the Repository tab.')"
        >
            <x-slot:actions>
                <a href="{{ route('sites.repository', ['server' => $server, 'site' => $site]) }}" wire:navigate class="dply-btn dply-btn-xs dply-btn-outline">
                    {{ __('Repository') }}
                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="px-3 py-3 sm:px-4">
            <dl class="grid grid-cols-1 overflow-hidden rounded-lg border border-brand-ink/10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($facts as $fact)
                    <div class="min-w-0 border-b border-r border-brand-ink/10 bg-brand-sand/20 px-3 py-2 last:border-b-0">
                        <dt class="text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $fact['label'] }}</dt>
                        <dd @class(['mt-0.5 truncate text-xs text-brand-ink', 'font-mono' => $fact['mono']])
                            @isset($fact['title']) title="{{ $fact['title'] }}" @endisset
                        >{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($invocationUrl !== '')
                <div x-data="{ copied: false }" class="mt-2 flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                    <span class="shrink-0 text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Invocation URL') }}</span>
                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink" title="{{ $invocationUrl }}">{{ $invocationUrl }}</span>
                    <span x-show="copied" x-cloak class="shrink-0 text-2xs font-medium text-brand-forest">{{ __('Copied') }}</span>
                    <button type="button"
                        class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink"
                        title="{{ __('Copy URL') }}"
                        @click="navigator.clipboard.writeText(@js($invocationUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                    >
                        <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                    </button>
                    <a href="{{ $invocationUrl }}" target="_blank" rel="noreferrer" title="{{ __('Open') }}" class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink">
                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" />
                    </a>
                </div>
            @endif
        </div>
    </section>

    {{-- 2. Resource limits — the editable control surface. --}}
    <form wire:submit="saveServerlessRuntime" class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-adjustments-horizontal"
            :title="__('Resource limits')"
            :note="__('How much the app gets per request. These are pushed to the action on the next deploy.')"
        />

        <div class="space-y-3 px-3 py-3 sm:px-4">
            @if ($pendingRedeploy)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    <p>{{ __('Saved limits differ from what is live (:mem MB · :to · concurrency :cc). Redeploy to apply them.', [
                        'mem' => $deployedLimits['memory'] ?? '—',
                        'to' => isset($deployedLimits['timeout']) ? number_format(((int) $deployedLimits['timeout']) / 1000, 1).'s' : '—',
                        'cc' => $deployedLimits['concurrency'] ?? '—',
                    ]) }}</p>
                    <button type="button" wire:click="redeployServerlessFunction" wire:loading.attr="disabled" wire:target="redeployServerlessFunction"
                        class="shrink-0 rounded-lg bg-brand-ink px-2.5 py-1 text-2xs font-semibold text-white hover:bg-brand-ink/90 disabled:opacity-50">
                        <span wire:loading.remove wire:target="redeployServerlessFunction">{{ __('Redeploy now') }}</span>
                        <span wire:loading wire:target="redeployServerlessFunction">{{ __('Starting…') }}</span>
                    </button>
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <x-input-label for="serverless_memory" :value="__('Memory')" />
                    <select id="serverless_memory" wire:model="serverless_memory" class="mt-1 block w-full rounded-lg border-brand-ink/15 py-1.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                        @foreach (\App\Models\Site::SERVERLESS_MEMORY_OPTIONS_MB as $mb)
                            <option value="{{ $mb }}">{{ $mb }} MB</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-2xs text-brand-moss">{{ __('RAM ceiling per invocation. CPU scales with memory.') }}</p>
                    <x-input-error :messages="$errors->get('serverless_memory')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="serverless_timeout_ms" :value="__('Timeout (ms)')" />
                    <x-text-input id="serverless_timeout_ms" type="number" wire:model="serverless_timeout_ms" class="mt-1 block w-full font-mono text-sm"
                        min="{{ \App\Models\Site::SERVERLESS_MIN_TIMEOUT_MS }}" max="{{ \App\Models\Site::SERVERLESS_MAX_TIMEOUT_MS }}" step="1000" />
                    <p class="mt-1 text-2xs text-brand-moss">{{ __('Hard cap before the invocation is killed. Max :max ms (15 min).', ['max' => number_format(\App\Models\Site::SERVERLESS_MAX_TIMEOUT_MS)]) }}</p>
                    <x-input-error :messages="$errors->get('serverless_timeout_ms')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="serverless_concurrency" :value="__('Concurrency')" />
                    <x-text-input id="serverless_concurrency" type="number" wire:model="serverless_concurrency" class="mt-1 block w-full font-mono text-sm"
                        min="1" max="{{ \App\Models\Site::SERVERLESS_MAX_CONCURRENCY }}" step="1" />
                    <p class="mt-1 text-2xs text-brand-moss">{{ __('Requests one container handles at once before another is spun up.') }}</p>
                    <x-input-error :messages="$errors->get('serverless_concurrency')" class="mt-1" />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-3">
                <p class="text-2xs text-brand-moss">
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

    {{-- 3. Cold starts — keep-warm is owned by the Workers tab; surface its state
         here. Header-only section: the state IS the note, so there's no body. --}}
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-clock"
            :title="__('Cold starts')"
            :count="$keepWarm ? __('keep-warm on') : __('keep-warm off')"
            :note="$keepWarm
                ? __('A scheduled ping holds a container warm to cut cold-start latency.')
                : __('The first request after idle pays the framework cold-start cost.')"
        >
            <x-slot:actions>
                <a href="{{ route('sites.workers', ['server' => $server, 'site' => $site]) }}" wire:navigate class="dply-btn dply-btn-xs dply-btn-outline">
                    {{ __('Workers') }}
                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
            </x-slot:actions>
        </x-workspace-panel-head>
    </section>

    {{-- 4. CLI parity --}}
    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
        <x-cli-snippet :commands="[
            ['label' => __('Deploy / redeploy the app'), 'command' => 'dply sites:deploy '.$site->slug],
        ]" />
    </div>
</div>
