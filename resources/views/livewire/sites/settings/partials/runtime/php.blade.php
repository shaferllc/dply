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
@endphp

@if (! $isPhpSite && ! $showPhpStackDetails)
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-information-circle class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Runtime') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Not a PHP site') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('This site does not run PHP, so there are no PHP runtime settings to tune. Switch to the Overview tab for processes and detection.') }}</p>
            </div>
        </div>
    </section>
@else

@if ($supportsMachinePhp && is_array($sitePhpData) && $isPhpSite)
    <section class="dply-card overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('PHP') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('PHP workspace') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Machine-level PHP, extensions, and Composer auth are shared on the server. Below you set this site’s PHP version and per-site limits.') }}</p>
                </div>
            </div>
            <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="shrink-0 text-sm font-semibold text-brand-forest hover:text-brand-sage hover:underline">
                {{ __('Open server PHP workspace') }} →
            </a>
        </div>

        <div class="space-y-8 px-6 py-6 sm:px-7">

        @if ($sitePhpData['mismatch_version'])
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Warning') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('PHP version mismatch') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('This site references PHP :version, but that version is not currently installed on this server.', ['version' => $sitePhpData['mismatch_version']]) }}</p>
                            <p class="mt-2 text-sm">
                                <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="font-medium text-amber-900 underline">
                                    {{ __('Install or switch versions on the server PHP page') }}
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <div class="space-y-4">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Server PHP status') }}</h3>
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <dt class="text-xs font-medium text-brand-moss">{{ __('Current site version') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">{{ $sitePhpData['current_version_label'] ?? __('Not set') }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <dt class="text-xs font-medium text-brand-moss">{{ __('Installed on this server') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">
                        @if ($supportedInstalledPhpVersions->isNotEmpty())
                            {{ $supportedInstalledPhpVersions->pluck('label')->implode(', ') }}
                        @else
                            {{ __('No supported installed versions recorded yet') }}
                        @endif
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <dt class="text-xs font-medium text-brand-moss">{{ __('OPcache') }}</dt>
                    <dd class="mt-1 text-brand-ink">{{ __('Shared at server level; tune on the server PHP workspace.') }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <dt class="text-xs font-medium text-brand-moss">{{ __('Composer auth') }}</dt>
                    <dd class="mt-1 text-brand-ink">{{ __('Managed from the server PHP workspace.') }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('Extensions') }}</p>
            <p class="mt-1">{{ __('Extensions are server-owned and shared across sites. Review them on the server PHP workspace.') }}</p>
        </div>

        <div class="space-y-4 border-t border-brand-ink/10 pt-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Site PHP limits') }}</h3>
            <form wire:submit="savePhpSettings" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <x-input-label for="php_version" value="PHP version" />
                        <select id="php_version" wire:model="php_version" class="mt-1 block w-full rounded-md border-brand-ink/15 shadow-sm text-sm">
                            @foreach ($supportedInstalledPhpVersions as $version)
                                <option value="{{ $version['id'] }}">{{ $version['label'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('php_version')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="php_memory_limit" value="Memory limit" />
                        <x-text-input id="php_memory_limit" wire:model="php_memory_limit" class="mt-1 block w-full font-mono text-sm" placeholder="512M" />
                        <x-input-error :messages="$errors->get('php_memory_limit')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="php_upload_max_filesize" value="Upload max filesize" />
                        <x-text-input id="php_upload_max_filesize" wire:model="php_upload_max_filesize" class="mt-1 block w-full font-mono text-sm" placeholder="64M" />
                        <x-input-error :messages="$errors->get('php_upload_max_filesize')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="php_max_execution_time" value="Max execution time" />
                        <x-text-input id="php_max_execution_time" wire:model="php_max_execution_time" class="mt-1 block w-full font-mono text-sm" placeholder="120" />
                        <x-input-error :messages="$errors->get('php_max_execution_time')" class="mt-1" />
                    </div>
                </div>

                <x-primary-button type="submit">{{ __('Save PHP settings') }}</x-primary-button>
            </form>
        </div>
        </div>
    </section>
@endif

@if ($showPhpStackDetails)
    <section class="dply-card overflow-hidden mt-6">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-cog-6-tooth class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Process') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $site->runtimePhpProcessSectionTitle() }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Process-level PHP knobs that shape how this site runs: pool user, scheduler, and Octane application server.') }}</p>
            </div>
        </div>

        <form wire:submit="saveRuntimePreferences">
            <div class="space-y-6 px-6 py-6 sm:px-7">
                @include('livewire.sites.settings.partials.laravel.octane-fields', ['site' => $site])

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @if (! $this->shouldShowSystemUserPanel())
                        <div>
                            <x-input-label for="runtime_php_fpm_user" :value="__('PHP-FPM pool user')" />
                            <x-text-input id="runtime_php_fpm_user" wire:model="php_fpm_user" class="mt-1 block w-full text-sm" placeholder="www-data" />
                            <x-input-error :messages="$errors->get('php_fpm_user')" class="mt-1" />
                        </div>
                    @endif
                    {{-- Hidden for confidently-non-Laravel PHP stacks (Symfony,
                         WordPress…), where `php artisan schedule:run` doesn't exist. --}}
                    @if ($site->supportsLaravelScheduler())
                    <div class="flex flex-col justify-end">
                        <label class="flex items-center gap-2 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-brand-ink/15 text-brand-forest shadow-sm focus:ring-brand-forest">
                            {{ $site->runtimeSchedulerCheckboxLabel() }}
                        </label>
                        @if ($site->runtimeSchedulerCheckboxHelp())
                            <p class="mt-1 pl-6 text-xs text-brand-moss">{{ $site->runtimeSchedulerCheckboxHelp() }}</p>
                        @endif
                    </div>
                    @endif
                </div>
            </div>

            <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
                <x-primary-button type="submit">{{ __('Save PHP runtime settings') }}</x-primary-button>
            </div>
        </form>
    </section>
@endif

<x-cli-snippet :commands="[
    ['label' => __('Set PHP version'), 'command' => 'dply sites:runtime:set '.$site->slug.' --runtime=php --runtime-version=8.4'],
    ['label' => __('Open server PHP workspace'), 'command' => 'dply:server:php '.($server->name ?? 'SERVER')],
]" />

@endif
