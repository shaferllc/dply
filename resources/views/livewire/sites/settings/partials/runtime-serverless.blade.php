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
    $friendlyUrl = $site->serverlessFriendlyUrl();
    $lastDeployedAt = $cfg['last_deployed_at'] ?? null;
    $keepWarm = $site->serverlessKeepWarmEnabled();
    $backgroundEnabled = $site->serverlessBackgroundProcessingEnabled();
    $neverDeployed = $revision === '';

    // Saved limits live in meta.serverless.limits; deployed_limits is what the
    // deployer last pushed to OpenWhisk. When they diverge the operator has
    // saved changes that won't take effect until the next deploy.
    $pendingRedeploy = $deployedLimits !== null && (
        (int) ($deployedLimits['memory'] ?? 0) !== $savedLimits['memory']
        || (int) ($deployedLimits['timeout'] ?? 0) !== $savedLimits['timeout']
        || (int) ($deployedLimits['concurrency'] ?? 0) !== $savedLimits['concurrency']
        || (isset($deployedLimits['logs']) && (int) $deployedLimits['logs'] !== $savedLimits['logs'])
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

            @include('livewire.serverless.partials.function-url-rows', [
                'invocationUrl' => $invocationUrl,
                'friendlyUrl' => $friendlyUrl,
                'wrapperClass' => 'mt-2 overflow-hidden rounded-lg border border-brand-ink/10 bg-brand-sand/20',
                'pad' => 'px-3 py-2',
                'urlClass' => 'mt-1 block truncate font-mono text-xs text-brand-ink underline-offset-2 hover:text-brand-sage hover:underline',
            ])
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
                    <p>{{ __('Saved limits differ from what is live (:mem MB · :to · concurrency :cc · :logs KB logs). Redeploy to apply them.', [
                        'mem' => $deployedLimits['memory'] ?? '—',
                        'to' => isset($deployedLimits['timeout']) ? number_format(((int) $deployedLimits['timeout']) / 1000, 1).'s' : '—',
                        'cc' => $deployedLimits['concurrency'] ?? '—',
                        'logs' => $deployedLimits['logs'] ?? '—',
                    ]) }}</p>
                    <button type="button" wire:click="redeployServerlessFunction" wire:loading.attr="disabled" wire:target="redeployServerlessFunction"
                        class="shrink-0 rounded-lg bg-brand-ink px-2.5 py-1 text-2xs font-semibold text-white hover:bg-brand-ink/90 disabled:opacity-50">
                        <span wire:loading.remove wire:target="redeployServerlessFunction">{{ __('Redeploy now') }}</span>
                        <span wire:loading wire:target="redeployServerlessFunction">{{ __('Starting…') }}</span>
                    </button>
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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

                <div>
                    <x-input-label for="serverless_logs_kb" :value="__('Log capture (KB)')" />
                    <x-text-input id="serverless_logs_kb" type="number" wire:model="serverless_logs_kb" class="mt-1 block w-full font-mono text-sm"
                        min="{{ \App\Models\Site::SERVERLESS_MIN_LOGS_KB }}" max="{{ \App\Models\Site::SERVERLESS_MAX_LOGS_KB }}" step="1" />
                    <p class="mt-1 text-2xs text-brand-moss">{{ __('Console output kept per request. The host caps this at :max KB.', ['max' => \App\Models\Site::SERVERLESS_MAX_LOGS_KB]) }}</p>
                    <x-input-error :messages="$errors->get('serverless_logs_kb')" class="mt-1" />
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

    @if ($site->isLaravelFrameworkDetected())
        <form wire:submit="saveServerlessMaintenance" class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-pause-circle"
                :title="__('Maintenance')"
                :note="__('Takes the app offline with a 503. This survives a cold start — it is not a temporary file on the function.')"
            />
            <div class="space-y-3 px-3 py-3 sm:px-4">
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="serverless_maintenance" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                    <span class="min-w-0">
                        <span class="block text-xs font-medium text-brand-ink">{{ __('Show the maintenance page') }}</span>
                        <span class="mt-0.5 block text-2xs text-brand-moss">{{ __('Visitors get a 503 until you turn this off. Scheduler and queue ticks still run.') }}</span>
                    </span>
                </label>
                <div class="flex justify-end">
                    <x-primary-button type="submit">
                        <span wire:loading.remove wire:target="saveServerlessMaintenance">{{ __('Save maintenance') }}</span>
                        <span wire:loading wire:target="saveServerlessMaintenance">{{ __('Saving…') }}</span>
                    </x-primary-button>
                </div>
            </div>
        </form>
    @endif

    {{-- 3. HTTP access — how the function is reachable, who may call it, and
         what is bound to it. Action metadata, so saving applies live. --}}
    @php
        $features = app(\App\Modules\Serverless\Services\ServerlessFeatureMatrix::class);
        $feature = \App\Modules\Serverless\Contracts\ServerlessFeature::class;
        $supportsRaw = $features->siteSupports($site, $feature::RawHttp);
        $supportsCors = $features->siteSupports($site, $feature::CustomCors);
        $supportsSecured = $features->siteSupports($site, $feature::SecuredWeb);
        $supportsApiKey = $features->siteSupports($site, $feature::ApiKeyPassthrough);
        $supportsParams = $features->siteSupports($site, $feature::DefaultParameters);
        $supportsFinal = $features->siteSupports($site, $feature::FinalParameters);

        $endpointSecret = trim((string) (($cfg['web']['auth_secret'] ?? '') ?: ''));
    @endphp

    <form wire:submit="saveServerlessHttpConfig" class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-globe-alt"
            :title="__('HTTP access')"
            :note="__('Whether the function answers HTTP, who may call it, and what parameters are bound to it. Applied to the live function on save. Web responses larger than 1 MB are rejected by the host.')"
        />

        <div class="space-y-3 px-3 py-3 sm:px-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="serverless_web_mode" :value="__('Exposure')" />
                    <select id="serverless_web_mode" wire:model.live="serverless_web_mode" class="mt-1 block w-full rounded-lg border-brand-ink/15 py-1.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                        <option value="web">{{ __('Web function — the platform parses the request') }}</option>
                        @if ($supportsRaw)
                            <option value="raw">{{ __('Raw HTTP — the handler receives the unparsed body') }}</option>
                        @endif
                        <option value="off">{{ __('Off — invocable only through the authenticated API') }}</option>
                    </select>
                    <p class="mt-1 text-2xs text-brand-moss">{{ __('Turning HTTP off makes the invocation URL 404.') }}</p>
                    <x-input-error :messages="$errors->get('serverless_web_mode')" class="mt-1" />
                </div>

                <div class="space-y-2">
                    @if ($supportsSecured)
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model.live="serverless_secured" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                            <span class="min-w-0">
                                <span class="block text-xs font-medium text-brand-ink">{{ __('Require a shared secret') }}</span>
                                <span class="block text-2xs text-brand-moss">{{ __('Callers must send it in the X-Require-Whisk-Auth header.') }}</span>
                            </span>
                        </label>
                    @endif

                    @if ($supportsApiKey)
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="serverless_provide_api_key" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                            <span class="min-w-0">
                                <span class="block text-xs font-medium text-brand-ink">{{ __('Pass the platform API key to the handler') }}</span>
                                <span class="block text-2xs text-brand-moss">{{ __('The function can then call the platform API as itself.') }}</span>
                            </span>
                        </label>
                    @endif
                </div>
            </div>

            @if ($serverless_secured && $endpointSecret !== '')
                <div x-data="{ shown: false, copied: false }" class="flex flex-wrap items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                    <span class="shrink-0 text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Endpoint secret') }}</span>
                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink">
                        <span x-show="!shown">{{ str_repeat('•', 24) }}</span>
                        <span x-show="shown" x-cloak>{{ $endpointSecret }}</span>
                    </span>
                    <span x-show="copied" x-cloak class="shrink-0 text-2xs font-medium text-brand-forest">{{ __('Copied') }}</span>
                    <button type="button" class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink" title="{{ __('Show / hide') }}" @click="shown = !shown">
                        <x-heroicon-o-eye class="h-3.5 w-3.5" />
                    </button>
                    <button type="button" class="shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink" title="{{ __('Copy') }}"
                        @click="navigator.clipboard.writeText(@js($endpointSecret)); copied = true; setTimeout(() => copied = false, 2000)">
                        <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                    </button>
                    <button type="button" wire:click="rotateServerlessEndpointSecret"
                        wire:confirm="{{ __('Rotate the endpoint secret? Every caller using the current value will start getting 401s.') }}"
                        class="dply-btn dply-btn-xs dply-btn-outline shrink-0">{{ __('Rotate') }}</button>
                </div>
            @endif

            @if ($supportsCors && $serverless_web_mode !== 'off')
                <div class="rounded-lg border border-brand-ink/10">
                    <label class="flex items-start gap-2 border-b border-brand-ink/10 px-3 py-2">
                        <input type="checkbox" wire:model.live="serverless_cors_enabled" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                        <span class="min-w-0">
                            <span class="block text-xs font-medium text-brand-ink">{{ __('Custom CORS headers') }}</span>
                            <span class="block text-2xs text-brand-moss">{{ __('Take over CORS from the platform defaults. The function then answers its own preflight.') }}</span>
                        </span>
                    </label>

                    @if ($serverless_cors_enabled)
                        <div class="grid gap-3 px-3 py-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="serverless_cors_origins" :value="__('Allowed origins')" />
                                <x-text-input id="serverless_cors_origins" type="text" wire:model="serverless_cors_origins" class="mt-1 block w-full font-mono text-sm" placeholder="https://app.example.com, https://admin.example.com" />
                                <p class="mt-1 text-2xs text-brand-moss">{{ __('Comma separated. Use * for any origin.') }}</p>
                                <x-input-error :messages="$errors->get('serverless_cors_origins')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="serverless_cors_headers" :value="__('Allowed request headers')" />
                                <x-text-input id="serverless_cors_headers" type="text" wire:model="serverless_cors_headers" class="mt-1 block w-full font-mono text-sm" placeholder="Content-Type, Authorization" />
                                <p class="mt-1 text-2xs text-brand-moss">{{ __('Headers the browser may send on a cross-origin request.') }}</p>
                                <x-input-error :messages="$errors->get('serverless_cors_headers')" class="mt-1" />
                            </div>

                            <div class="sm:col-span-2">
                                <x-input-label :value="__('Allowed methods')" />
                                <div class="mt-1 flex flex-wrap gap-2">
                                    @foreach (\App\Modules\Serverless\Support\FunctionCorsPolicy::METHODS as $method)
                                        <label class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 px-2 py-1 text-2xs font-medium text-brand-ink">
                                            <input type="checkbox" value="{{ $method }}" wire:model="serverless_cors_methods" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                                            <span class="font-mono">{{ $method }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <x-input-error :messages="$errors->get('serverless_cors_methods')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="serverless_cors_max_age" :value="__('Preflight cache (seconds)')" />
                                <x-text-input id="serverless_cors_max_age" type="number" wire:model="serverless_cors_max_age" class="mt-1 block w-full font-mono text-sm" min="0" max="86400" placeholder="600" />
                                <p class="mt-1 text-2xs text-brand-moss">{{ __('How long a browser may reuse a preflight result. Blank omits the header.') }}</p>
                                <x-input-error :messages="$errors->get('serverless_cors_max_age')" class="mt-1" />
                            </div>

                            <div class="flex items-end">
                                <label class="flex items-start gap-2">
                                    <input type="checkbox" wire:model="serverless_cors_credentials" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                                    <span class="min-w-0">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Allow credentials') }}</span>
                                        <span class="block text-2xs text-brand-moss">{{ __('Cookies and auth headers on cross-origin calls. Requires explicit origins, not *.') }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            @if ($supportsParams)
                <div class="rounded-lg border border-brand-ink/10">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-3 py-2">
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-brand-ink">{{ __('Default parameters') }}</p>
                            <p class="text-2xs text-brand-moss">{{ __('Bound to the function at deploy time and merged into every event.') }}</p>
                        </div>
                        <button type="button" wire:click="addServerlessParameter" class="dply-btn dply-btn-xs dply-btn-outline shrink-0">
                            {{ __('Add parameter') }}
                        </button>
                    </div>

                    <div class="space-y-2 px-3 py-3">
                        @forelse ($serverless_parameters as $index => $row)
                            <div class="flex items-start gap-2" wire:key="serverless-param-{{ $index }}">
                                <div class="min-w-0 flex-1">
                                    <x-text-input type="text" wire:model="serverless_parameters.{{ $index }}.key" class="block w-full font-mono text-sm" placeholder="STRIPE_KEY" aria-label="{{ __('Parameter name') }}" />
                                    <x-input-error :messages="$errors->get('serverless_parameters.'.$index.'.key')" class="mt-1" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <x-text-input type="text" wire:model="serverless_parameters.{{ $index }}.value" class="block w-full font-mono text-sm" placeholder="{{ __('value') }}" aria-label="{{ __('Parameter value') }}" />
                                    <x-input-error :messages="$errors->get('serverless_parameters.'.$index.'.value')" class="mt-1" />
                                </div>
                                <button type="button" wire:click="removeServerlessParameter({{ $index }})" title="{{ __('Remove') }}"
                                    class="mt-1.5 shrink-0 rounded-md p-1 text-brand-mist hover:bg-brand-sand/60 hover:text-brand-ink">
                                    <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        @empty
                            <p class="text-2xs text-brand-moss">{{ __('No bound parameters. The function reads its configuration from the environment instead.') }}</p>
                        @endforelse

                        @if ($supportsFinal && count($serverless_parameters) > 0)
                            <label class="flex items-start gap-2 border-t border-brand-ink/10 pt-2">
                                <input type="checkbox" wire:model="serverless_parameters_final" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                                <span class="min-w-0">
                                    <span class="block text-xs font-medium text-brand-ink">{{ __('Seal these parameters') }}</span>
                                    <span class="block text-2xs text-brand-moss">{{ __('A caller cannot override them per request — the safety catch when a parameter holds a secret.') }}</span>
                                </span>
                            </label>
                        @endif
                    </div>
                </div>
            @endif

            @if ($features->siteSupports($site, $feature::LogForwarding))
                <div class="rounded-lg border border-brand-ink/10">
                    <div class="border-b border-brand-ink/10 px-3 py-2">
                        <p class="text-xs font-medium text-brand-ink">{{ __('Forward logs') }}</p>
                        <p class="text-2xs text-brand-moss">{{ __("Send the function's console and error output to a third-party logging service.") }}</p>
                    </div>

                    <div class="grid gap-3 px-3 py-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="serverless_log_provider" :value="__('Destination')" />
                            <select id="serverless_log_provider" wire:model.live="serverless_log_provider" class="mt-1 block w-full rounded-lg border-brand-ink/15 py-1.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                <option value="">{{ __('Off — logs stay on the platform') }}</option>
                                <option value="papertrail">{{ __('Papertrail') }}</option>
                                <option value="datadog">{{ __('Datadog') }}</option>
                                <option value="logtail">{{ __('Better Stack') }}</option>
                            </select>
                            <x-input-error :messages="$errors->get('serverless_log_provider')" class="mt-1" />
                        </div>

                        @if ($serverless_log_provider !== '')
                            <div>
                                <x-input-label for="serverless_log_token" :value="$serverless_log_provider === 'datadog' ? __('API key') : __('Source token')" />
                                <x-text-input id="serverless_log_token" type="password" wire:model="serverless_log_token" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                                <p class="mt-1 text-2xs text-brand-moss">{{ __('Stored with the function and deployed as its LOG_DESTINATIONS variable.') }}</p>
                                <x-input-error :messages="$errors->get('serverless_log_token')" class="mt-1" />
                            </div>
                        @endif

                        @if ($serverless_log_provider === 'datadog')
                            <div class="sm:col-span-2">
                                <x-input-label for="serverless_log_endpoint" :value="__('Intake endpoint')" />
                                <x-text-input id="serverless_log_endpoint" type="text" wire:model="serverless_log_endpoint" class="mt-1 block w-full font-mono text-sm"
                                    placeholder="{{ \App\Modules\Serverless\Support\FunctionLogForwarding::DATADOG_DEFAULT_ENDPOINT }}" />
                                <p class="mt-1 text-2xs text-brand-moss">{{ __('Leave blank for the US intake. EU and other regions have their own host.') }}</p>
                                <x-input-error :messages="$errors->get('serverless_log_endpoint')" class="mt-1" />
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-3">
                <p class="text-2xs text-brand-moss">
                    @if ($neverDeployed)
                        {{ __('These settings apply on the first deploy.') }}
                    @else
                        {{ __('Saving pushes these to the live function — no redeploy needed.') }}
                    @endif
                </p>
                <x-primary-button type="submit">
                    <span wire:loading.remove wire:target="saveServerlessHttpConfig">{{ __('Save HTTP settings') }}</span>
                    <span wire:loading wire:target="saveServerlessHttpConfig">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </form>

    {{-- 4. Warm start — a control-plane minute ping. Takes effect on the
         next tick; no redeploy. Background processing already warms, so
         that path skips the extra GET. --}}
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-bolt"
            :title="__('Warm start')"
            :count="$keepWarm ? __('on') : __('off')"
            :note="$backgroundEnabled
                ? __('Background processing already pings every minute, which holds the function warm. dply will not send a second warm-start request.')
                : ($keepWarm
                    ? __('A minute ping holds the function warm so visitors do not pay a cold start.')
                    : __('After idle, the first visitor waits for the function to boot. Turn this on for public sites.'))"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="toggleServerlessKeepWarm"
                    wire:loading.attr="disabled"
                    wire:target="toggleServerlessKeepWarm"
                    class="dply-btn dply-btn-xs dply-btn-outline"
                >
                    <span wire:loading.remove wire:target="toggleServerlessKeepWarm">{{ $keepWarm ? __('Disable') : __('Enable') }}</span>
                    <span wire:loading wire:target="toggleServerlessKeepWarm">{{ __('Saving…') }}</span>
                </button>
            </x-slot:actions>
        </x-workspace-panel-head>
    </section>

    {{-- 5. CLI parity --}}
    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
        <x-cli-snippet :commands="[
            ['label' => __('Deploy / redeploy the app'), 'command' => 'dply sites:deploy '.$site->slug],
        ]" />
    </div>
</div>
