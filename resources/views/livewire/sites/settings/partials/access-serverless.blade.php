{{-- SERVERLESS > Access — how the function is reachable over HTTP and who
     may call it. Split out of Runtime, which had grown into five unrelated
     control surfaces on one page. Log forwarding moved to Logs; both still
     save through `saveServerlessHttpConfig`, which writes the block whole.

     Merged chrome, like Workers and Schedule: one card, sand identity header,
     hairline strips, sand CLI footer. --}}
@php
    $cfg = $site->serverlessConfig();
    $neverDeployed = trim((string) ($cfg['last_revision_id'] ?? '')) === '';

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

<div class="min-w-0">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-globe-alt"
        :title="__('Access')"
        :note="__('Whether this function answers HTTP, who may call it, and what parameters are bound to it. Applied to the live function on save.')"
    >
        <x-slot:actions>
            @include('livewire.sites.partials.header-role-badge')
        </x-slot:actions>
    </x-workspace-panel-head>

    <form wire:submit="saveServerlessHttpConfig">
        {{-- Exposure + who may call it. --}}
        <div class="border-b border-brand-ink/10">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                    <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('HTTP access') }}
                </h3>
                <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Web responses larger than 1 MB are rejected by the host.') }}">
                    {{ __('Web responses larger than 1 MB are rejected by the host.') }}
                </p>
            </div>

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
            </div>
        </div>

        @if ($supportsCors && $serverless_web_mode !== 'off')
            <div class="border-b border-brand-ink/10">
                <label class="flex items-start gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <input type="checkbox" wire:model.live="serverless_cors_enabled" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                    <span class="min-w-0">
                        <span class="block text-xs font-medium text-brand-ink">{{ __('Custom CORS headers') }}</span>
                        <span class="block text-2xs text-brand-moss">{{ __('Take over CORS from the platform defaults. The function then answers its own preflight.') }}</span>
                    </span>
                </label>

                @if ($serverless_cors_enabled)
                    <div class="grid gap-3 px-3 py-3 sm:grid-cols-2 sm:px-4">
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
            <div class="border-b border-brand-ink/10">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-brand-ink">{{ __('Default parameters') }}</p>
                        <p class="text-2xs text-brand-moss">{{ __('Bound to the function at deploy time and merged into every event.') }}</p>
                    </div>
                    <button type="button" wire:click="addServerlessParameter" class="dply-btn dply-btn-xs dply-btn-outline shrink-0">
                        {{ __('Add parameter') }}
                    </button>
                </div>

                <div class="space-y-2 px-3 py-3 sm:px-4">
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

        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 sm:px-4">
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
    </form>

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
        <x-cli-snippet :commands="[
            ['label' => __('Current HTTP settings'), 'command' => 'dply serverless runtime '.$site->slug],
            ['label' => __('Exposure + shared secret'), 'command' => 'dply serverless runtime '.$site->slug.' --web-mode web --secure'],
            ['label' => __('CORS'), 'command' => 'dply serverless runtime '.$site->slug.' --cors on --cors-origins https://app.example.com'],
            ['label' => __('Bound parameters'), 'command' => 'dply serverless runtime '.$site->slug.' --param STRIPE_MODE=live'],
            ['label' => __('Rotate the endpoint secret'), 'command' => 'dply serverless runtime '.$site->slug.' --rotate-secret'],
        ]" />
    </div>
</div>
