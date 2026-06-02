@php
    $functionsHost = $functionsHost ?? $server->hostCapabilities()->supportsFunctionDeploy();
    $card = 'dply-card overflow-hidden';
    // The raw server-block snippet is Nginx-specific. Caddy (and other engines)
    // don't take an Nginx `location` block, so only surface it on Nginx hosts.
    $isNginx = $site->webserver() === 'nginx';
@endphp

@if (! $functionsHost)
    <div class="space-y-6">
        <div class="{{ $card }}">
            <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7 lg:border-b-0 lg:border-r">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Activate') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Zero downtime deployment') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('When enabled, each deploy goes to a new release directory, then traffic switches in one step so the app stays up during builds. Disable for simple in-place git deploys.') }}</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-3 px-6 py-6 sm:px-7 sm:py-8">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="zero_downtime_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                        <span class="text-sm font-semibold text-brand-ink">{{ __('Enable zero-downtime rollout') }}</span>
                    </label>
                    <x-input-error :messages="$errors->get('zero_downtime_enabled')" />
                </div>
            </div>

        </div>

        @if (ephemeral_deploy_credentials_active($site->organization))
            <div class="{{ $card }}">
                <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7 lg:border-b-0 lg:border-r">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Security') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Ephemeral deploy credentials') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Issue a one-time SSH key per deploy, sync it for the rollout, then revoke when the deploy finishes — success or failure.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3 px-6 py-6 sm:px-7 sm:py-8">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" wire:model="ephemeral_deploy_credentials_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                            <span class="text-sm font-semibold text-brand-ink">{{ __('Use ephemeral SSH credentials for deploys') }}</span>
                        </label>
                        <x-input-error :messages="$errors->get('ephemeral_deploy_credentials_enabled')" />
                        <p class="text-sm leading-relaxed text-brand-moss">{{ __('Each deployment gets its own ed25519 key with a fingerprint in the audit log. Your server’s operational SSH key still installs and removes deploy keys.') }}</p>
                    </div>
                </div>

            </div>
        @endif

        <div class="space-y-6">
            @if ($zero_downtime_enabled)
                <section class="{{ $card }}">
                    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                                <x-heroicon-o-heart class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Verify') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('After deploy verification') }}</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Optional HTTP(S) check from the server after the new release is active. Uses your primary hostname as the Host header. Requires a route that returns the expected status (for example Laravel /up).') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-5 px-6 py-6 sm:px-7">
                        <div class="space-y-3 rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4 sm:p-5">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model.live="deploy_health_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                <span class="text-sm font-semibold text-brand-ink">{{ __('Run health check after each atomic deploy') }}</span>
                            </label>
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="deploy_health_auto_rollback" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" @disabled(! $deploy_health_enabled)>
                                <span class="text-sm font-semibold text-brand-ink @if (! $deploy_health_enabled) opacity-50 @endif">{{ __('Automatically roll back to the previous release if the check fails') }}</span>
                            </label>
                        </div>

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" @class(['opacity-50 pointer-events-none' => ! $deploy_health_enabled])>
                            <div>
                                <x-input-label for="deploy_health_scheme" :value="__('URL scheme')" />
                                <select id="deploy_health_scheme" wire:model="deploy_health_scheme" class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" @disabled(! $deploy_health_enabled)>
                                    <option value="http">http</option>
                                    <option value="https">https</option>
                                </select>
                                <x-input-error :messages="$errors->get('deploy_health_scheme')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="deploy_health_host" :value="__('Target host')" />
                                <x-text-input id="deploy_health_host" wire:model="deploy_health_host" class="mt-2 block w-full font-mono text-sm" placeholder="127.0.0.1" :disabled="! $deploy_health_enabled" />
                                <x-input-error :messages="$errors->get('deploy_health_host')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="deploy_health_port" :value="__('Target port (optional)')" />
                                <x-text-input id="deploy_health_port" type="number" wire:model="deploy_health_port" class="mt-2 block w-full font-mono text-sm" placeholder="80 / 443" min="1" max="65535" :disabled="! $deploy_health_enabled" />
                                <p class="mt-2 text-sm text-brand-moss">{{ __('Leave empty for the default port (80 or 443).') }}</p>
                                <x-input-error :messages="$errors->get('deploy_health_port')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="deploy_health_path" :value="__('Health path')" />
                                <x-text-input id="deploy_health_path" wire:model="deploy_health_path" class="mt-2 block w-full font-mono text-sm" placeholder="/up" :disabled="! $deploy_health_enabled" />
                                <x-input-error :messages="$errors->get('deploy_health_path')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="deploy_health_expect_status" :value="__('Expected HTTP status')" />
                                <x-text-input id="deploy_health_expect_status" type="number" wire:model="deploy_health_expect_status" class="mt-2 w-28" min="100" max="599" :disabled="! $deploy_health_enabled" />
                                <x-input-error :messages="$errors->get('deploy_health_expect_status')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="deploy_health_attempts" :value="__('Attempts')" />
                                <x-text-input id="deploy_health_attempts" type="number" wire:model="deploy_health_attempts" class="mt-2 w-28" min="1" max="30" :disabled="! $deploy_health_enabled" />
                                <x-input-error :messages="$errors->get('deploy_health_attempts')" class="mt-2" />
                            </div>
                            <div class="sm:col-span-2 lg:col-span-1">
                                <x-input-label for="deploy_health_delay_ms" :value="__('Delay between attempts (ms)')" />
                                <x-text-input id="deploy_health_delay_ms" type="number" wire:model="deploy_health_delay_ms" class="mt-2 w-32" min="0" max="10000" step="50" :disabled="! $deploy_health_enabled" />
                                <x-input-error :messages="$errors->get('deploy_health_delay_ms')" class="mt-2" />
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            <section class="{{ $card }}">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Rollout') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Rollout and web server') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ $isNginx
                                    ? __('Release retention, deploy environment group, scheduler, Supervisor restarts, and optional Nginx snippets. Runtime ports and users are on Settings → Runtime.')
                                    : __('Release retention, deploy environment group, scheduler, and Supervisor restarts. Runtime ports and users are on Settings → Runtime.') }}
                            </p>
                            @if ($zero_downtime_enabled)
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                    <span class="inline-flex items-center gap-1">
                                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                        {{ __('atomic deploys enabled') }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="space-y-6 px-6 py-6 sm:px-7">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="releases_to_keep" :value="__('Releases to keep')" />
                            <x-text-input id="releases_to_keep" type="number" wire:model="releases_to_keep" class="mt-2 w-28" min="1" max="50" />
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Applies when zero downtime deployment is enabled.') }}</p>
                            <x-input-error :messages="$errors->get('releases_to_keep')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="deployment_environment" :value="__('Environment group')" />
                            <x-text-input id="deployment_environment" wire:model="deployment_environment" class="mt-2 block w-full text-sm" placeholder="production" />
                            <p class="mt-2 text-sm text-brand-moss">{{ __('Used when resolving key/value environment variables for deploys.') }}</p>
                            <x-input-error :messages="$errors->get('deployment_environment')" class="mt-2" />
                        </div>
                    </div>

                    <div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-brand-cream/50 p-4 sm:p-5">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Post-activate') }}</p>
                        <div class="space-y-4">
                            <div>
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" wire:model="laravel_scheduler" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-brand-ink">{{ $site->runtimeSchedulerRolloutFormLabel() }}</span>
                                        @if ($site->runtimeSchedulerCheckboxHelp())
                                            <span class="mt-1 block text-sm leading-relaxed text-brand-moss">{{ $site->runtimeSchedulerCheckboxHelp() }}</span>
                                        @endif
                                    </span>
                                </label>
                            </div>
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                <span class="text-sm font-semibold text-brand-ink">{{ __('Restart Supervisor programs after successful deploy') }}</span>
                            </label>
                        </div>
                    </div>

                    @if ($isNginx)
                    <div>
                        <x-input-label for="nginx_extra_raw" :value="__('Extra Nginx inside server block (advanced)')" />
                        <textarea
                            id="nginx_extra_raw"
                            wire:model="nginx_extra_raw"
                            rows="5"
                            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                            placeholder="# location /foo { ... }"
                        ></textarea>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Injected into the site’s Nginx server block. Validate syntax before relying on it in production.') }}</p>
                        <x-input-error :messages="$errors->get('nginx_extra_raw')" class="mt-2" />
                    </div>
                    @endif
                </div>

            </section>
        </div>
    </div>
@endif
