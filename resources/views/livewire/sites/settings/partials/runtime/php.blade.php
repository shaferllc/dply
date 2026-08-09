@php
    $supportsMachinePhp = $server->hostCapabilities()->supportsMachinePhpManagement();
    $isPhpSite = $site->type === \App\Enums\SiteType::Php;
    $detectedFramework = strtolower((string) ($site->resolvedRuntimeAppDetection()['framework'] ?? ''));
    $showPhpStackDetails = $isPhpSite
        || in_array($detectedFramework, ['laravel', 'php_generic', 'symfony'], true);
    $supportedInstalledPhpVersions = ($supportsMachinePhp && is_array($sitePhpData))
        ? collect($sitePhpData['installed_versions'] ?? [])
            ->filter(fn (array $version) => (bool) ($version['is_supported'] ?? false))
            ->values()
        : collect();

    $panelBody = 'px-5 py-3 sm:px-6';
    $fieldHelp = 'mt-1 text-[11px] text-brand-moss';
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
@endphp

@if (! $isPhpSite && ! $showPhpStackDetails)
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-information-circle"
            :title="__('Not a PHP site')"
            :note="__('No PHP runtime settings to tune. Switch to Overview for processes and detection.')"
        />
    </section>
@else

@if ($supportsMachinePhp && is_array($sitePhpData) && $isPhpSite)
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-command-line"
            :title="__('PHP workspace')"
            :note="__('Site PHP version and limits. Machine PHP, extensions, and Composer auth live on the server.')"
        >
            <x-slot:actions>
                <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:text-brand-sage hover:underline">
                    {{ __('Server PHP') }} →
                </a>
            </x-slot:actions>
        </x-workspace-panel-head>

        @if ($sitePhpData['mismatch_version'])
            <div class="flex flex-wrap items-start gap-2 border-b border-amber-200/80 bg-amber-50/50 px-3 py-2 sm:px-4">
                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                <div class="min-w-0 flex-1 text-xs text-amber-900">
                    <p class="font-semibold">{{ __('PHP version mismatch') }}</p>
                    <p class="mt-0.5 text-amber-800/90">{{ __('This site references PHP :version, but that version is not installed on this server.', ['version' => $sitePhpData['mismatch_version']]) }}
                        <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="font-semibold underline">{{ __('Install on server PHP') }}</a>
                    </p>
                </div>
            </div>
        @endif

        <div class="{{ $panelBody }} space-y-3">
            <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <x-fact-row :label="__('Site version')" :value="$sitePhpData['current_version_label'] ?? __('Not set')" :mono="false" />
                <x-fact-row :label="__('Installed')" :mono="false">
                    @if ($supportedInstalledPhpVersions->isNotEmpty())
                        {{ $supportedInstalledPhpVersions->pluck('label')->implode(', ') }}
                    @else
                        {{ __('None recorded yet') }}
                    @endif
                </x-fact-row>
                <x-fact-row :label="__('OPcache')" :value="__('Server-level')" :mono="false" tone="muted" />
                <x-fact-row :label="__('Composer auth')" :value="__('Server-level')" :mono="false" tone="muted" />
            </dl>

            <p class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-[11px] text-brand-moss">
                <span class="font-semibold text-brand-ink">{{ __('Extensions') }}</span>
                {{ __('are server-owned and shared across sites — review them on the server PHP workspace.') }}
            </p>
        </div>
    </section>

    <form wire:submit="savePhpSettings" class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-adjustments-horizontal"
            :title="__('Site PHP limits')"
            :note="__('Per-site overrides for memory, uploads, and execution time.')"
        >
            <x-slot:actions>
                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="savePhpSettings">
                    <span wire:loading.remove wire:target="savePhpSettings">{{ __('Save') }}</span>
                    <span wire:loading wire:target="savePhpSettings">{{ __('Saving…') }}</span>
                </x-primary-button>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="{{ $panelBody }}">
            <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <x-input-label for="php_version" value="PHP version" class="!text-xs" />
                    <select id="php_version" wire:model="php_version" class="mt-1 block w-full rounded-md border-brand-ink/15 shadow-sm text-sm">
                        @foreach ($supportedInstalledPhpVersions as $version)
                            <option value="{{ $version['id'] }}">{{ $version['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('php_version')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="php_memory_limit" value="Memory limit" class="!text-xs" />
                    <x-text-input id="php_memory_limit" wire:model="php_memory_limit" class="mt-1 block w-full font-mono text-sm" placeholder="512M" />
                    <x-input-error :messages="$errors->get('php_memory_limit')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="php_upload_max_filesize" value="Upload max filesize" class="!text-xs" />
                    <x-text-input id="php_upload_max_filesize" wire:model="php_upload_max_filesize" class="mt-1 block w-full font-mono text-sm" placeholder="64M" />
                    <x-input-error :messages="$errors->get('php_upload_max_filesize')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="php_post_max_size" value="Post max size" class="!text-xs" />
                    <x-text-input id="php_post_max_size" wire:model="php_post_max_size" class="mt-1 block w-full font-mono text-sm" placeholder="64M" />
                    <x-input-error :messages="$errors->get('php_post_max_size')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('Must be ≥ upload max filesize.') }}</p>
                </div>
                <div>
                    <x-input-label for="php_max_execution_time" value="Max execution time" class="!text-xs" />
                    <x-text-input id="php_max_execution_time" wire:model="php_max_execution_time" class="mt-1 block w-full font-mono text-sm" placeholder="120" />
                    <x-input-error :messages="$errors->get('php_max_execution_time')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('Seconds. Soft (CPU-time) limit under FPM.') }}</p>
                </div>
                <div>
                    <x-input-label for="php_max_input_time" value="Max input time" class="!text-xs" />
                    <x-text-input id="php_max_input_time" wire:model="php_max_input_time" class="mt-1 block w-full font-mono text-sm" placeholder="60" />
                    <x-input-error :messages="$errors->get('php_max_input_time')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('Seconds. Use -1 to derive from execution time.') }}</p>
                </div>
                <div>
                    <x-input-label for="php_max_input_vars" value="Max input vars" class="!text-xs" />
                    <x-text-input id="php_max_input_vars" wire:model="php_max_input_vars" class="mt-1 block w-full font-mono text-sm" placeholder="1000" />
                    <x-input-error :messages="$errors->get('php_max_input_vars')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('Raise for large forms (e.g. WordPress).') }}</p>
                </div>
                <div>
                    <x-input-label for="php_max_file_uploads" value="Max file uploads" class="!text-xs" />
                    <x-text-input id="php_max_file_uploads" wire:model="php_max_file_uploads" class="mt-1 block w-full font-mono text-sm" placeholder="20" />
                    <x-input-error :messages="$errors->get('php_max_file_uploads')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="php_timezone" value="Default timezone" class="!text-xs" />
                    <x-text-input id="php_timezone" wire:model="php_timezone" class="mt-1 block w-full font-mono text-sm" placeholder="UTC" />
                    <x-input-error :messages="$errors->get('php_timezone')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('date.timezone — e.g. UTC or America/New_York.') }}</p>
                </div>
            </div>
        </div>
    </form>
@endif

@if ($supportsMachinePhp && $site->usesDedicatedPhpFpmPool())
    @php
        $poolServerRamMb = \App\Support\Servers\InstalledStack::fromMeta($server)->totalMemoryMb;
        $poolMemLimitBytes = \App\Services\Sites\SitePhpRuntimeDirectivesBuilder::shorthandBytes(
            (string) (is_array($site->meta['php_runtime'] ?? null) ? ($site->meta['php_runtime']['memory_limit'] ?? '') : '')
        );
        $poolWorkerMb = $poolMemLimitBytes > 0 ? (int) round($poolMemLimitBytes / 1048576) : 128;
        $poolReserveMb = $poolServerRamMb !== null ? max(256, (int) round($poolServerRamMb * 0.25)) : 512;
    @endphp
    <form wire:submit="savePhpFpmPool" class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-rectangle-stack"
            :title="__('PHP-FPM pool')"
            :note="__('Dedicated process pool for this site — :socket', ['socket' => $site->phpFpmListenSocketPath()])"
        >
            <x-slot:actions>
                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="savePhpFpmPool">
                    <span wire:loading.remove wire:target="savePhpFpmPool">{{ __('Save') }}</span>
                    <span wire:loading wire:target="savePhpFpmPool">{{ __('Saving…') }}</span>
                </x-primary-button>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="{{ $panelBody }} space-y-3">
            <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <x-input-label for="fpm_pm" value="Process manager" class="!text-xs" />
                    <select id="fpm_pm" wire:model="fpm_pm" class="mt-1 block w-full rounded-md border-brand-ink/15 shadow-sm text-sm">
                        <option value="dynamic">{{ __('dynamic') }}</option>
                        <option value="static">{{ __('static') }}</option>
                        <option value="ondemand">{{ __('ondemand') }}</option>
                    </select>
                    <x-input-error :messages="$errors->get('fpm_pm')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('start/spare servers are derived from max children.') }}</p>
                </div>
                <div>
                    <x-input-label for="fpm_max_children" value="Max children" class="!text-xs" />
                    <x-text-input id="fpm_max_children" wire:model="fpm_max_children" class="mt-1 block w-full font-mono text-sm" placeholder="10" />
                    <x-input-error :messages="$errors->get('fpm_max_children')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('Worker ceiling for this site.') }}</p>
                </div>
                <div>
                    <x-input-label for="fpm_max_requests" value="Max requests" class="!text-xs" />
                    <x-text-input id="fpm_max_requests" wire:model="fpm_max_requests" class="mt-1 block w-full font-mono text-sm" placeholder="500" />
                    <x-input-error :messages="$errors->get('fpm_max_requests')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('Recycle a worker after N requests (0 = never).') }}</p>
                </div>
                <div>
                    <x-input-label for="fpm_request_terminate_timeout" value="Request terminate timeout" class="!text-xs" />
                    <x-text-input id="fpm_request_terminate_timeout" wire:model="fpm_request_terminate_timeout" class="mt-1 block w-full font-mono text-sm" placeholder="120" />
                    <x-input-error :messages="$errors->get('fpm_request_terminate_timeout')" class="mt-1" />
                    <p class="{{ $fieldHelp }}">{{ __('Seconds. Hard wall-clock kill of a stuck worker.') }}</p>
                </div>
            </div>

            <div
                x-data="{
                    ram: @js($poolServerRamMb),
                    reserve: @js($poolReserveMb),
                    worker: @js($poolWorkerMb),
                    get usable() { return Math.max(0, (Number(this.ram) || 0) - (Number(this.reserve) || 0)); },
                    get suggested() {
                        const w = Number(this.worker) || 0;
                        if (w <= 0 || this.usable <= 0) return null;
                        return Math.max(1, Math.floor(this.usable / w));
                    },
                }"
                class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                            <x-heroicon-o-calculator class="h-3.5 w-3.5 text-brand-sage" aria-hidden="true" />
                            {{ __('Max children sizing') }}
                        </p>
                        <p class="{{ $fieldHelp }}">{{ __('(RAM − reserve) ÷ per-worker memory.') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs text-brand-ink">
                            {{ __('Suggested:') }}
                            <span class="font-mono font-semibold" x-text="suggested ?? '—'"></span>
                        </p>
                        <button
                            type="button"
                            x-show="suggested !== null"
                            x-on:click="$wire.set('fpm_max_children', String(suggested))"
                            class="{{ $btnOutline }}"
                        >
                            {{ __('Use this') }}
                        </button>
                    </div>
                </div>

                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <div>
                        <label class="text-[11px] font-medium text-brand-moss">{{ __('Server RAM (MB)') }}</label>
                        <input type="number" min="0" x-model.number="ram" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm shadow-sm" placeholder="2048" />
                    </div>
                    <div>
                        <label class="text-[11px] font-medium text-brand-moss">{{ __('Reserve (MB)') }}</label>
                        <input type="number" min="0" x-model.number="reserve" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm shadow-sm" />
                    </div>
                    <div>
                        <label class="text-[11px] font-medium text-brand-moss">{{ __('Per-worker (MB)') }}</label>
                        <input type="number" min="1" x-model.number="worker" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm shadow-sm" />
                    </div>
                </div>
            </div>
        </div>
    </form>
@endif

@if ($showPhpStackDetails)
    <form wire:submit="saveRuntimePreferences" class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-cog-6-tooth"
            :title="$site->runtimePhpProcessSectionTitle()"
            :note="__('Pool user, Laravel scheduler opt-in, and Octane when detected.')"
        >
            <x-slot:actions>
                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveRuntimePreferences">
                    <span wire:loading.remove wire:target="saveRuntimePreferences">{{ __('Save') }}</span>
                    <span wire:loading wire:target="saveRuntimePreferences">{{ __('Saving…') }}</span>
                </x-primary-button>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="{{ $panelBody }} space-y-3">
            @include('livewire.sites.settings.partials.laravel.octane-fields', ['site' => $site])

            <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                @if (! $this->shouldShowSystemUserPanel())
                    <div>
                        <x-input-label for="runtime_php_fpm_user" :value="__('PHP-FPM pool user')" class="!text-xs" />
                        <x-text-input id="runtime_php_fpm_user" wire:model="php_fpm_user" class="mt-1 block w-full text-sm" placeholder="www-data" />
                        <x-input-error :messages="$errors->get('php_fpm_user')" class="mt-1" />
                    </div>
                @endif
                @if ($site->supportsLaravelScheduler())
                    <div class="flex flex-col justify-end">
                        <label class="flex items-center gap-2 text-xs text-brand-ink">
                            <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-brand-ink/15 text-brand-forest shadow-sm focus:ring-brand-forest">
                            {{ $site->runtimeSchedulerCheckboxLabel() }}
                        </label>
                        @if ($site->runtimeSchedulerCheckboxHelp())
                            <p class="{{ $fieldHelp }} pl-6">{{ $site->runtimeSchedulerCheckboxHelp() }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </form>
@endif

<div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
    <x-cli-snippet :commands="[
        ['label' => __('Set PHP version'), 'command' => 'dply sites:runtime:set '.$site->slug.' --runtime=php --runtime-version=8.4'],
        ['label' => __('Open server PHP workspace'), 'command' => 'dply:server:php '.($server->name ?? 'SERVER')],
    ]" />
</div>

@endif
