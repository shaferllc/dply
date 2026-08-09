@php
    $functionsHost = $functionsHost ?? $server->hostCapabilities()->supportsFunctionDeploy();
    $rolloutNested = (bool) ($isEmbedded ?? false);
    $card = 'border-b border-brand-ink/10';
    $panelBody = 'px-5 py-3 sm:px-6';
    $fieldHelp = 'mt-1 text-xs text-brand-moss';
    $isNginx = $site->webserver() === 'nginx';

    $showScheduler = $site->supportsLaravelScheduler();
    $showSupervisor = $site->hasRestartableSupervisorPrograms();
    $vmManagedCron = $site->supportsVmManagedCron();
    $schedulerCronLink = ! $showScheduler && $vmManagedCron;
    $supervisorDaemonsLink = ! $showSupervisor && $vmManagedCron;
    $showPostActivate = $showScheduler || $showSupervisor || $schedulerCronLink || $supervisorDaemonsLink;

    $rolloutSummaryParts = [__('Release retention'), __('deploy environment group')];
    if ($showScheduler) { $rolloutSummaryParts[] = __('scheduler'); }
    if ($showSupervisor) { $rolloutSummaryParts[] = __('Supervisor restarts'); }
    if ($isNginx) { $rolloutSummaryParts[] = __('optional Nginx snippets'); }
    $rolloutSummary = collect($rolloutSummaryParts)->join(', ', __(', and ')).'.';
@endphp

@if (! $functionsHost)
    <div class="min-w-0">
        <div class="{{ $card }}">
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-arrows-right-left"
                :title="__('Zero downtime deployment')"
                :note="__('New release directory + symlink swap. Off = simple in-place git deploy.')"
            />
            <div class="{{ $panelBody }}">
                <label class="flex items-start gap-2.5">
                    <input type="checkbox" wire:model="zero_downtime_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                    <span class="text-xs font-semibold text-brand-ink">{{ __('Enable zero-downtime rollout') }}</span>
                </label>
                <x-input-error :messages="$errors->get('zero_downtime_enabled')" class="mt-1" />
            </div>
        </div>

        @if (ephemeral_deploy_credentials_active($site->organization))
            <div class="{{ $card }}">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-key"
                    :title="__('Ephemeral deploy credentials')"
                    :note="__('One-time ed25519 SSH key per deploy, revoked when the run finishes.')"
                />
                <div class="{{ $panelBody }} space-y-1.5">
                    <label class="flex items-start gap-2.5">
                        <input type="checkbox" wire:model="ephemeral_deploy_credentials_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                        <span class="text-xs font-semibold text-brand-ink">{{ __('Use ephemeral SSH credentials for deploys') }}</span>
                    </label>
                    <x-input-error :messages="$errors->get('ephemeral_deploy_credentials_enabled')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('Fingerprint lands in the audit log. Your operational SSH key still manages deploy keys.') }}</p>
                </div>
            </div>
        @endif

        @if ($zero_downtime_enabled)
            <section class="{{ $card }}">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-heart"
                    :title="__('After deploy verification')"
                    :note="__('Optional HTTP(S) check from the server after activate (e.g. Laravel /up).')"
                />

                <div class="{{ $panelBody }} space-y-3" x-data="{ healthEnabled: @js($deploy_health_enabled) }">
                    <div class="space-y-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                        <label class="flex items-start gap-2.5">
                            <input type="checkbox" wire:model="deploy_health_enabled" x-on:change="healthEnabled = $event.target.checked" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                            <span class="text-xs font-semibold text-brand-ink">{{ __('Run health check after each atomic deploy') }}</span>
                        </label>
                        <label class="flex items-start gap-2.5">
                            <input type="checkbox" wire:model="deploy_health_auto_rollback" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" x-bind:disabled="! healthEnabled" @disabled(! $deploy_health_enabled)>
                            <span class="text-xs font-semibold text-brand-ink" x-bind:class="{ 'opacity-50': ! healthEnabled }" @class(['opacity-50' => ! $deploy_health_enabled])>{{ __('Auto-rollback if the check fails') }}</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3" x-bind:class="{ 'opacity-50 pointer-events-none': ! healthEnabled }" @class(['opacity-50 pointer-events-none' => ! $deploy_health_enabled])>
                        <div>
                            <x-input-label for="deploy_health_scheme" :value="__('URL scheme')" class="!text-xs" />
                            <select id="deploy_health_scheme" wire:model="deploy_health_scheme" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage/30" x-bind:disabled="! healthEnabled" @disabled(! $deploy_health_enabled)>
                                <option value="http">http</option>
                                <option value="https">https</option>
                            </select>
                            <x-input-error :messages="$errors->get('deploy_health_scheme')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_host" :value="__('Target host')" class="!text-xs" />
                            <x-text-input id="deploy_health_host" wire:model="deploy_health_host" class="mt-1 block w-full font-mono text-xs" placeholder="127.0.0.1" x-bind:disabled="! healthEnabled" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_host')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_port" :value="__('Port (optional)')" class="!text-xs" />
                            <x-text-input id="deploy_health_port" type="number" wire:model="deploy_health_port" class="mt-1 block w-full font-mono text-xs" placeholder="80 / 443" min="1" max="65535" x-bind:disabled="! healthEnabled" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_port')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_path" :value="__('Health path')" class="!text-xs" />
                            <x-text-input id="deploy_health_path" wire:model="deploy_health_path" class="mt-1 block w-full font-mono text-xs" placeholder="/up" x-bind:disabled="! healthEnabled" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_path')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_expect_status" :value="__('Expected status')" class="!text-xs" />
                            <x-text-input id="deploy_health_expect_status" type="number" wire:model="deploy_health_expect_status" class="mt-1 w-24 text-xs" min="100" max="599" x-bind:disabled="! healthEnabled" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_expect_status')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="deploy_health_attempts" :value="__('Attempts')" class="!text-xs" />
                            <x-text-input id="deploy_health_attempts" type="number" wire:model="deploy_health_attempts" class="mt-1 w-24 text-xs" min="1" max="30" x-bind:disabled="! healthEnabled" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_attempts')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2 lg:col-span-1">
                            <x-input-label for="deploy_health_delay_ms" :value="__('Delay between attempts (ms)')" class="!text-xs" />
                            <x-text-input id="deploy_health_delay_ms" type="number" wire:model="deploy_health_delay_ms" class="mt-1 w-28 text-xs" min="0" max="10000" step="50" x-bind:disabled="! healthEnabled" :disabled="! $deploy_health_enabled" />
                            <x-input-error :messages="$errors->get('deploy_health_delay_ms')" class="mt-1" />
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="{{ $card }}">
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-server-stack"
                :title="__('Rollout and web server')"
                :note="$rolloutSummary"
                :count="$zero_downtime_enabled ? __('Atomic') : null"
            />

            <div class="{{ $panelBody }} space-y-3">
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="releases_to_keep" :value="__('Releases to keep')" class="!text-xs" />
                        <x-text-input id="releases_to_keep" type="number" wire:model="releases_to_keep" class="mt-1 w-24 text-xs" min="1" max="50" />
                        <p class="{{ $fieldHelp }}">{{ __('When zero downtime is enabled.') }}</p>
                        <x-input-error :messages="$errors->get('releases_to_keep')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="deployment_environment" :value="__('Environment group')" class="!text-xs" />
                        <x-text-input id="deployment_environment" wire:model="deployment_environment" class="mt-1 block w-full text-xs" placeholder="production" />
                        <p class="{{ $fieldHelp }}">{{ __('Used when resolving key/value env vars for deploys.') }}</p>
                        <x-input-error :messages="$errors->get('deployment_environment')" class="mt-1" />
                    </div>
                </div>

                @if ($showPostActivate)
                    <div class="space-y-2.5 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                        <p class="text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Post-activate') }}</p>
                        @if ($showScheduler)
                            <label class="flex items-start gap-2.5">
                                <input type="checkbox" wire:model="laravel_scheduler" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                <span class="min-w-0">
                                    <span class="block text-xs font-semibold text-brand-ink">{{ $site->runtimeSchedulerRolloutFormLabel() }}</span>
                                    @if ($site->runtimeSchedulerCheckboxHelp())
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ $site->runtimeSchedulerCheckboxHelp() }}</span>
                                    @endif
                                </span>
                            </label>
                        @elseif ($schedulerCronLink)
                            <p class="text-xs leading-relaxed text-brand-moss">
                                {{ __('Need a recurring task for this stack?') }}
                                <a href="{{ route('servers.cron', ['server' => $server, 'site' => $site]) }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Set one up in Cron →') }}</a>
                            </p>
                        @endif

                        @if ($showSupervisor)
                            <label class="flex items-start gap-2.5">
                                <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                                <span class="text-xs font-semibold text-brand-ink">{{ __('Restart Supervisor programs after successful deploy') }}</span>
                            </label>
                        @elseif ($supervisorDaemonsLink)
                            <p class="text-xs leading-relaxed text-brand-moss">
                                {{ __('Run background workers for this site?') }}
                                <a href="{{ route('sites.daemons', [$server, $site]) }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Manage them in Daemons →') }}</a>
                            </p>
                        @endif
                    </div>
                @endif

                @if ($isNginx)
                    <div>
                        <x-input-label for="nginx_extra_raw" :value="__('Extra Nginx inside server block (advanced)')" class="!text-xs" />
                        <textarea
                            id="nginx_extra_raw"
                            wire:model="nginx_extra_raw"
                            rows="4"
                            class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage/30"
                            placeholder="# location /foo { ... }"
                        ></textarea>
                        <p class="{{ $fieldHelp }}">{{ __('Injected into the site’s Nginx server block. Validate before production.') }}</p>
                        <x-input-error :messages="$errors->get('nginx_extra_raw')" class="mt-1" />
                    </div>
                @endif
            </div>
        </section>
    </div>
@endif
