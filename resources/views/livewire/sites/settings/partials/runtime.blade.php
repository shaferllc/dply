@if ($supportsMachinePhp && is_array($sitePhpData) && $site->type === \App\Enums\SiteType::Php)
    @php
        $supportedInstalledPhpVersions = collect($sitePhpData['installed_versions'])
            ->filter(fn (array $version) => (bool) ($version['is_supported'] ?? false))
            ->values();
    @endphp

    <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('PHP') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Choose a site PHP version from the supported versions currently installed on this server and keep site-owned runtime limits here. OPcache, Composer auth, and extension management stay shared and server-owned on the server PHP workspace.') }}</p>
            </div>
            <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                {{ __('Open server PHP workspace') }}
            </a>
        </div>

        @if ($sitePhpData['mismatch_version'])
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-medium">{{ __('PHP version mismatch') }}</p>
                <p class="mt-1 text-amber-800">{{ __('This site references PHP :version, but that version is not currently installed on this server.', ['version' => $sitePhpData['mismatch_version']]) }}</p>
                <p class="mt-2">
                    <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="font-medium text-amber-900 underline">
                        {{ __('Install or switch versions on the server PHP page') }}
                    </a>
                </p>
            </div>
        @endif

        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <dt class="text-brand-moss">{{ __('Current site version') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ $sitePhpData['current_version_label'] ?? __('Not set') }}</dd>
            </div>
            <div>
                <dt class="text-brand-moss">{{ __('Installed on this server') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">
                    @if ($supportedInstalledPhpVersions->isNotEmpty())
                        {{ $supportedInstalledPhpVersions->pluck('label')->implode(', ') }}
                    @else
                        {{ __('No supported installed versions recorded yet') }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-brand-moss">{{ __('OPcache') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ __('Shared at the server level; review runtime config on the server PHP workspace.') }}</dd>
            </div>
            <div>
                <dt class="text-brand-moss">{{ __('Composer auth') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ __('Shared Composer credentials are managed from the server PHP workspace.') }}</dd>
            </div>
        </dl>

        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('Extensions') }}</p>
            <p class="mt-1">{{ __('Extensions are server-owned and shared across sites on this machine. Use the server PHP workspace to review versions and extension entry points.') }}</p>
        </div>

        <form wire:submit="savePhpSettings" class="space-y-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <x-input-label for="php_version" value="PHP version" />
                    <select id="php_version" wire:model="php_version" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
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
    </section>
@endif

<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Runtime') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            @if ($functionsHost)
                {{ __('Functions-backed sites expose inspectable runtime details here. Repository controls, build output, and rollout behavior now live in Deploy.') }}
            @else
                {{ __('Keep runtime-specific details here so the Deploy tab can stay focused on code delivery, no-downtime strategy, scripts, and hooks.') }}
            @endif
        </p>
    </div>

    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
            <dt class="text-brand-moss">{{ __('Runtime profile') }}</dt>
            <dd class="mt-1 font-medium text-brand-ink">{{ str((string) ($site->meta['runtime_profile'] ?? $site->type->value ?? __('Unknown')))->replace('_', ' ')->title() }}</dd>
        </div>
        <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
            <dt class="text-brand-moss">{{ __('Deploy path') }}</dt>
            <dd class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $site->effectiveRepositoryPath() }}</dd>
        </div>
        <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
            <dt class="text-brand-moss">{{ __('Env group') }}</dt>
            <dd class="mt-1 font-medium text-brand-ink">{{ $deployment_environment !== '' ? $deployment_environment : __('production') }}</dd>
        </div>
        @if (! $functionsHost)
            <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                <dt class="text-brand-moss">{{ __('Octane port') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ $octane_port !== '' ? $octane_port : __('Not set') }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                <dt class="text-brand-moss">{{ __('PHP-FPM user') }}</dt>
                <dd class="mt-1 font-medium text-brand-ink">{{ $php_fpm_user !== '' ? $php_fpm_user : __('Default') }}</dd>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/70 p-4">
                <dt class="text-brand-moss">{{ __('Scheduler + Supervisor') }}</dt>
                <dd class="mt-1 text-sm text-brand-ink">{{ $laravel_scheduler ? __('Scheduler enabled') : __('Scheduler disabled') }} · {{ $restart_supervisor_programs_after_deploy ? __('Restart after deploy enabled') : __('No Supervisor restart') }}</dd>
            </div>
        @endif
    </dl>

    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4 text-sm text-brand-moss">
        <p class="font-medium text-brand-ink">{{ __('Runtime editing moved') }}</p>
        <p class="mt-1">{{ __('Repository changes, no-downtime deploy strategy, hooks, and deploy scripts now live in the Deploy tab so this page can stay focused on how the application runs once it is live.') }}</p>
    </div>
</section>
