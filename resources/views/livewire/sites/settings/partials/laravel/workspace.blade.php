@php
    use App\Services\Sites\LaravelConsoleExecutor;
    use App\Services\Sites\LaravelSiteSshSetupRunner;

    $laravelExecutor = app(LaravelConsoleExecutor::class);
    $execProfile = $laravelExecutor->executionProfile($site);
    $canLaravelSshSetup = $site->canRunLaravelSshSetupActions();
    $allowedSshActions = $canLaravelSshSetup ? app(LaravelSiteSshSetupRunner::class)->allowedActions($site) : [];
    $presetCategories = config('laravel_site_console.preset_categories', []);
    $cronUrl = route('sites.cron', ['server' => $server, 'site' => $site]);
    $daemonsUrl = route('sites.daemons', ['server' => $server, 'site' => $site]);
    $cronAllServerUrl = route('servers.cron', $server);
    $daemonsAllServerUrl = route('servers.daemons', $server);
    $envUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'environment']);
    $laravelLogKey = 'site_'.$site->getKey().'_laravel';
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Laravel') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Artisan commands, Octane and Reverb hints, application logs, and links to server automation.') }}</p>
            @if ($execProfile === 'unsupported')
                <p class="mt-2 text-sm text-amber-800">{{ __('Remote Artisan from this panel requires a BYO VM with SSH, or a local Orbstack Docker/Kubernetes runtime. Other container hosts need your provider or SSH tooling.') }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <a
                href="{{ $envUrl }}"
                wire:navigate
                class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm hover:bg-slate-50"
            >
                {{ __('Edit environment') }}
            </a>
            <button
                type="button"
                wire:click="$set('laravel_tab', 'commands')"
                class="inline-flex items-center justify-center rounded-xl bg-brand-forest px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-forest/90"
            >
                {{ __('Custom commands') }}
            </button>
        </div>
    </div>

    <div class="border-b border-brand-ink/10">
        <nav class="-mb-px flex flex-wrap gap-4" aria-label="{{ __('Laravel sections') }}">
            @foreach ([
                'commands' => __('Commands'),
                'octane' => __('Octane'),
                'reverb' => __('Reverb'),
                'logs' => __('Logs'),
                'setup' => __('Setup'),
            ] as $tabId => $tabLabel)
                <button
                    type="button"
                    wire:click="$set('laravel_tab', '{{ $tabId }}')"
                    @class([
                        'border-b-2 py-3 text-sm font-semibold transition',
                        'border-brand-forest text-brand-forest' => $laravel_tab === $tabId,
                        'border-transparent text-brand-moss hover:text-brand-ink' => $laravel_tab !== $tabId,
                    ])
                >
                    {{ $tabLabel }}
                </button>
            @endforeach
        </nav>
    </div>

    @if ($laravel_tab === 'commands')
        <div class="space-y-6">
            @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 14])

            @if ($laravel_console_error)
                <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $laravel_console_error }}</p>
            @endif

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-5">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Preset commands') }}</h3>
                <div class="mt-4 space-y-4">
                    @foreach ($presetCategories as $category => $commands)
                        @if (is_array($commands) && $commands !== [])
                            <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ $category }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($commands as $cmd)
                                        <button
                                            type="button"
                                            wire:click='runLaravelArtisanPreset(@json($cmd))'
                                            class="inline-flex rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-forest/90"
                                        >
                                            {{ $cmd }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Discovered Artisan commands') }}</h3>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('From `php artisan list` on the app (cached). Run only preset or saved custom commands.') }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="loadLaravelArtisanDiscovery(true)"
                        class="text-sm font-medium text-brand-forest underline"
                    >
                        {{ __('Refresh') }}
                    </button>
                </div>
                @if (! empty($laravel_artisan_discovery['ok']))
                    <ul class="mt-4 max-h-64 overflow-y-auto rounded-lg border border-brand-ink/10 bg-slate-50/80 p-3 font-mono text-[11px] text-brand-ink">
                        @foreach (array_slice($laravel_artisan_discovery['commands'] ?? [], 0, 400) as $row)
                            <li class="py-0.5">{{ $row['name'] ?? '' }}@if (! empty($row['description']))<span class="text-brand-moss"> — {{ $row['description'] }}</span>@endif</li>
                        @endforeach
                    </ul>
                @elseif (! empty($laravel_artisan_discovery['error']))
                    <p class="mt-3 text-sm text-amber-800">{{ $laravel_artisan_discovery['error'] }}</p>
                @else
                    <p class="mt-3 text-sm text-brand-moss">{{ __('Load discovery with Refresh, or open this tab again.') }}</p>
                @endif
            </div>

            <form wire:submit="saveLaravelCustomCommands" class="space-y-3 rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Custom commands') }}</h3>
                <p class="text-xs text-brand-moss">{{ __('One Artisan tail per line (e.g. `migrate --force`). These appear as runnable alongside presets.') }}</p>
                <textarea
                    wire:model="laravel_custom_commands_text"
                    rows="5"
                    class="mt-2 w-full rounded-lg border border-slate-300 font-mono text-sm shadow-sm"
                    placeholder="migrate --force"
                ></textarea>
                <x-input-error :messages="$errors->get('laravel_custom_commands_text')" class="mt-1" />
                <x-primary-button type="submit">{{ __('Save custom commands') }}</x-primary-button>
            </form>

            @if ($canLaravelSshSetup ?? false)
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-5">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Remote setup (SSH)') }}</h3>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('One-shot Composer and install steps on the server.') }}</p>
                    @if ($laravel_ssh_setup_error ?? null)
                        <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $laravel_ssh_setup_error }}</p>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($allowedSshActions as $action)
                            @php
                                $label = match ($action) {
                                    \App\Services\Sites\LaravelSiteSshSetupRunner::ACTION_COMPOSER_INSTALL => __('Composer install (no dev)'),
                                    \App\Services\Sites\LaravelSiteSshSetupRunner::ACTION_ARTISAN_OPTIMIZE => __('artisan optimize'),
                                    \App\Services\Sites\LaravelSiteSshSetupRunner::ACTION_OCTANE_INSTALL => __('artisan octane:install'),
                                    \App\Services\Sites\LaravelSiteSshSetupRunner::ACTION_REVERB_INSTALL => __('artisan reverb:install'),
                                    default => \App\Models\SiteDeployStep::typeLabels()[$action] ?? $action,
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
        </div>
    @endif

    @if ($laravel_tab === 'octane')
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-6">
            @include('livewire.sites.settings.partials.laravel.octane-fields', ['site' => $site])
            @if ($site->shouldShowPhpOctaneRolloutSettings() && $site->shouldShowOctaneRuntimeUi())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-6">
                    <a
                        href="{{ $daemonsUrl }}?preset=laravel-octane"
                        wire:navigate
                        class="text-sm font-medium text-brand-forest underline"
                    >
                        {{ __('Open Daemons with Octane preset') }}
                    </a>
                    <x-primary-button type="button" wire:click="saveLaravelOctaneTab">{{ __('Save Octane settings') }}</x-primary-button>
                </div>
            @else
                <p class="text-sm text-brand-moss">{{ __('Octane settings appear when `laravel/octane` is detected in composer.json.') }}</p>
            @endif
        </div>
    @endif

    @if ($laravel_tab === 'reverb')
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-6">
            @include('livewire.sites.settings.partials.laravel.reverb-fields', ['site' => $site, 'server' => $server])
            @if ($site->shouldShowPhpOctaneRolloutSettings() && ($site->shouldShowLaravelReverbRuntimeUi() || $site->shouldProxyReverbInWebserver()))
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-6">
                    <a
                        href="{{ $daemonsUrl }}?preset=reverb"
                        wire:navigate
                        class="text-sm font-medium text-brand-forest underline"
                    >
                        {{ __('Open Daemons with Reverb preset') }}
                    </a>
                    <x-primary-button type="button" wire:click="saveLaravelReverbTab">{{ __('Save Reverb settings') }}</x-primary-button>
                </div>
            @else
                <p class="text-sm text-brand-moss">{{ __('Reverb settings appear when `laravel/reverb` is detected or a Reverb port is saved.') }}</p>
            @endif
        </div>
    @endif

    @if ($laravel_tab === 'logs')
        <div class="space-y-6">
            @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 14])
            @if ($laravel_console_error)
                <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $laravel_console_error }}</p>
            @endif
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Tail Laravel log (remote)') }}</h3>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Streams `storage/logs/laravel.log` via SSH or your local container runtime.') }}</p>
                <div class="mt-4 flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="laravel_log_tail_lines" :value="__('Lines')" />
                        <x-text-input id="laravel_log_tail_lines" type="number" wire:model="laravel_log_tail_lines" class="mt-1 block w-28 font-mono text-sm" min="50" max="5000" />
                    </div>
                    <x-primary-button type="button" wire:click="runLaravelApplicationLogTail">{{ __('Tail log') }}</x-primary-button>
                </div>
            </div>

            @if ($execProfile === 'vm_ssh')
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Log viewer') }}</h3>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Same viewer as Site logs — Laravel and Horizon files when available.') }}</p>
                    <div class="mt-4">
                        <livewire:sites.site-log-viewer
                            :server="$server"
                            :site="$site"
                            :preferred-log-key="$laravelLogKey"
                            wire:key="laravel-workspace-log-{{ $site->id }}"
                        />
                    </div>
                </div>
            @else
                <p class="text-sm text-brand-moss">{{ __('SSH file-based log viewer is available on BYO VM sites. For local Docker/Kubernetes, use Tail above or your runtime diagnostics.') }}</p>
            @endif
        </div>
    @endif

    @if ($laravel_tab === 'setup')
        <div class="space-y-6">
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Scheduler & automation') }}</h3>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Add a per-minute cron entry on the server for `php artisan schedule:run` when you use Laravel’s scheduler.') }}</p>
                <a href="{{ $cronUrl }}" wire:navigate class="mt-3 inline-flex text-sm font-medium text-brand-forest underline">{{ __('Cron jobs for this site') }}</a>
                <a href="{{ $cronAllServerUrl }}" wire:navigate class="mt-2 ml-0 block text-xs font-medium text-brand-moss underline hover:text-brand-ink">{{ __('All cron jobs on server') }}</a>
            </div>

            <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-6">
                @include('livewire.sites.settings.partials.laravel.horizon-pulse-fields', ['site' => $site, 'server' => $server])
                <div class="flex justify-end border-t border-brand-ink/10 pt-6">
                    <x-primary-button type="button" wire:click="saveLaravelSetupTab">{{ __('Save setup notes') }}</x-primary-button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <h4 class="text-sm font-semibold text-brand-ink">{{ __('Supervisor daemons') }}</h4>
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Queue workers, Octane, Reverb, Horizon, and more.') }}</p>
                    <a href="{{ $daemonsUrl }}" wire:navigate class="mt-3 inline-flex text-sm font-medium text-brand-forest underline">{{ __('Queue workers for this site') }}</a>
                    <a href="{{ $daemonsAllServerUrl }}" wire:navigate class="mt-2 ml-0 block text-xs font-medium text-brand-moss underline hover:text-brand-ink">{{ __('All Supervisor programs on server') }}</a>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <h4 class="text-sm font-semibold text-brand-ink">{{ __('Deploy') }}</h4>
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Scheduler checkbox and Supervisor restart after deploy live under Runtime and Deploy.') }}</p>
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="mt-3 inline-flex text-sm font-medium text-brand-forest underline">{{ __('Open Deploy') }}</a>
                </div>
            </div>
        </div>
    @endif
</div>
