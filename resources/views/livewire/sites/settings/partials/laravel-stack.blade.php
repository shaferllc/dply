@php
    use App\Models\SiteDeployStep;
    use App\Services\Sites\LaravelSiteSshSetupRunner;

    $canLaravelSshSetup = $site->canRunLaravelSshSetupActions();
    $hasLaravelPackages = $site->shouldShowPhpOctaneRolloutSettings() && count($site->detectedLaravelPackageKeys()) > 0;
    $runner = app(LaravelSiteSshSetupRunner::class);
    $allowedSshActions = $canLaravelSshSetup ? $runner->allowedActions($site) : [];
@endphp

<section class="space-y-6 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Laravel stack') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Composer-detected Laravel packages, local ports, and dashboard paths for Supervisor and reverse-proxy configuration. PHP version and Octane fields stay under Runtime.') }}
        </p>
    </div>

    @if ($canLaravelSshSetup)
        @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 14])

        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-5">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Remote setup (SSH)') }}</h3>
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('Runs a single command on :host in :dir over SSH. Output streams to Live while the request runs; large installs can take several minutes.', [
                    'host' => $server->name,
                    'dir' => $site->effectiveEnvDirectory(),
                ]) }}
            </p>
            @if ($laravel_ssh_setup_error ?? null)
                <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $laravel_ssh_setup_error }}</p>
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($allowedSshActions as $action)
                    @php
                        $label = match ($action) {
                            LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL => __('Composer install (no dev)'),
                            LaravelSiteSshSetupRunner::ACTION_ARTISAN_OPTIMIZE => __('artisan optimize'),
                            LaravelSiteSshSetupRunner::ACTION_OCTANE_INSTALL => __('artisan octane:install'),
                            LaravelSiteSshSetupRunner::ACTION_REVERB_INSTALL => __('artisan reverb:install'),
                            default => SiteDeployStep::typeLabels()[$action] ?? $action,
                        };
                    @endphp
                    <button
                        type="button"
                        wire:click="openLaravelSshSetupModal('{{ $action }}')"
                        class="inline-flex rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($hasLaravelPackages)
        <form wire:submit="saveLaravelStackSettings" class="space-y-6">
            @include('livewire.sites.settings.partials.laravel-ecosystem')

            <div class="flex flex-wrap items-center gap-3 border-t border-brand-ink/10 pt-6">
                <x-primary-button type="submit">{{ __('Save Laravel stack') }}</x-primary-button>
            </div>
        </form>
    @elseif (! $canLaravelSshSetup)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('Nothing detected yet') }}</p>
            <p class="mt-1">
                {{ __('After a deploy picks up `composer.json` (including VM sites), Horizon, Pulse, Reverb, and Octane hints appear here when those packages are present.') }}
            </p>
        </div>
    @else
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('Optional packages not listed yet') }}</p>
            <p class="mt-1">
                {{ __('Horizon, Octane, Reverb, and similar entries appear after deploy detection updates `composer.lock`. You can still run Composer install above.') }}
            </p>
        </div>
    @endif
</section>
