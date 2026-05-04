<div class="space-y-6">

    @if (! $site->isWordPressDetected())
        <section class="space-y-6 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('WordPress') }}</h2>
            <p class="text-sm text-brand-moss">{{ __('This section appears when the site is detected as a WordPress install — either from a wp-config.php in the repo or from a successful WordPress scaffold.') }}</p>
        </section>
    @else

    {{-- Sub-tab nav --}}
    <nav class="flex flex-wrap items-center gap-1 rounded-2xl border border-brand-ink/10 bg-white p-1 shadow-sm">
        @foreach ([
            'console' => ['label' => __('Console'), 'enabled' => true],
            'plugins' => ['label' => __('Plugins'), 'enabled' => true],
            'database' => ['label' => __('Database'), 'enabled' => false],
            'cron' => ['label' => __('Cron'), 'enabled' => true],
            'hardening' => ['label' => __('Hardening'), 'enabled' => false],
        ] as $key => $meta)
            <button
                type="button"
                wire:click="$set('tab', '{{ $key }}')"
                @if (! $meta['enabled']) disabled @endif
                @class([
                    'rounded-xl px-3 py-1.5 text-sm font-medium transition',
                    'bg-brand-ink text-brand-cream shadow-sm' => $tab === $key,
                    'text-brand-moss hover:bg-brand-sand/40' => $tab !== $key && $meta['enabled'],
                    'cursor-not-allowed text-brand-mist' => ! $meta['enabled'],
                ])
            >
                {{ $meta['label'] }}
                @if (! $meta['enabled'])
                    <span class="ml-1 text-[9px] uppercase tracking-wide">{{ __('soon') }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    {{-- CONSOLE --}}
    @if ($tab === 'console')
        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
            <header class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('wp-cli Console') }}</h3>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Run any wp-cli command. Inspect commands return inline; mutating commands queue and stream their output.') }}</p>
                </div>
            </header>

            <div class="mt-5 grid gap-3 sm:grid-cols-[1fr_2fr_auto]">
                <div>
                    <x-input-label for="wp_command" :value="__('Command')" />
                    <x-text-input id="wp_command" wire:model.live="consoleCommand" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="plugin list" />
                    <x-input-error :messages="$errors->get('consoleCommand')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="wp_args" :value="__('Args')" />
                    <x-text-input id="wp_args" wire:model.live="consoleArgs" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="--format=table" />
                </div>
                <div class="self-end">
                    <button
                        type="button"
                        wire:click="runConsoleCommand"
                        wire:loading.attr="disabled"
                        wire:target="runConsoleCommand"
                        class="inline-flex h-10 items-center gap-2 rounded-xl bg-brand-ink px-5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="runConsoleCommand">{{ __('Run') }}</span>
                        <span wire:loading wire:target="runConsoleCommand" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Running…') }}
                        </span>
                    </button>
                </div>
            </div>

            @if ($latestRun)
                <div class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-brand-moss">
                        <span class="font-mono">wp {{ $latestRun->command }}</span>
                        <span @class([
                            'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                            'bg-brand-sage/15 text-brand-forest' => $latestRun->status === 'completed',
                            'bg-rose-100 text-rose-700' => $latestRun->status === 'failed',
                            'bg-brand-gold/20 text-brand-ink animate-pulse' => in_array($latestRun->status, ['queued', 'running'], true),
                        ])>{{ $latestRun->status }}</span>
                        @if ($latestRun->exit_code !== null)
                            <span class="text-brand-mist">exit {{ $latestRun->exit_code }}</span>
                        @endif
                    </div>
                    @if ($latestRun->stdout)
                        <pre class="mt-3 max-h-72 overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-[11px] leading-relaxed text-brand-cream">{{ $latestRun->stdout }}</pre>
                    @endif
                    @if ($latestRun->stderr)
                        <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-rose-950/95 p-3 font-mono text-[11px] leading-relaxed text-rose-100">{{ $latestRun->stderr }}</pre>
                    @endif
                </div>
            @endif

            @if ($history->isNotEmpty())
                <div class="mt-6">
                    <h4 class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Recent runs') }}</h4>
                    <ul class="mt-2 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white text-sm">
                        @foreach ($history as $run)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <span class="font-mono text-xs text-brand-ink">wp {{ $run->command }}</span>
                                <span class="text-xs text-brand-mist">
                                    {{ $run->status }}{{ $run->exit_code !== null ? ' · exit '.$run->exit_code : '' }} · {{ $run->created_at?->diffForHumans() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    @endif

    {{-- CRON --}}
    @if ($tab === 'cron')
        @php
            $handler = data_get($site->meta, 'wp_cron.handler', 'wp_cron');
        @endphp
        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
            <header>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Cron handler') }}</h3>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('WordPress\'s built-in wp-cron runs on every page load — fine for low-traffic sites, awful for performance once you grow. Switch to system cron and dply runs `wp cron event run --due-now` every minute via a real crontab entry.') }}</p>
            </header>

            <div class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Currently') }}</p>
                <p class="mt-1 text-base font-semibold text-brand-ink">
                    @if ($handler === 'system_cron')
                        {{ __('System cron (recommended)') }}
                    @else
                        {{ __('wp-cron via HTTP (default)') }}
                    @endif
                </p>
            </div>

            @if ($handler !== 'system_cron')
                <div class="mt-4">
                    <button
                        type="button"
                        wire:click="switchToSystemCron"
                        wire:loading.attr="disabled"
                        wire:target="switchToSystemCron"
                        class="inline-flex h-10 items-center gap-2 rounded-xl bg-brand-ink px-5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                    >
                        <x-heroicon-o-bolt class="h-4 w-4" />
                        <span wire:loading.remove wire:target="switchToSystemCron">{{ __('Switch to system cron') }}</span>
                        <span wire:loading wire:target="switchToSystemCron">{{ __('Switching…') }}</span>
                    </button>
                    <x-input-error :messages="$errors->get('cron')" class="mt-2" />
                </div>
            @else
                <p class="mt-4 text-xs text-brand-moss">{{ __('System cron active — switching back to wp-cron lives in the Hardening tab once it ships.') }}</p>
            @endif
        </section>
    @endif

    {{-- PLUGINS --}}
    @if ($tab === 'plugins')
        <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
            <header class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Plugins') }}</h3>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Live list pulled from `wp plugin list`. Each row is cross-checked against Wordfence Intelligence for known CVEs.') }}</p>
                </div>
                @if ($pluginsLoaded && collect($plugins)->where('update', 'available')->isNotEmpty())
                    <button
                        type="button"
                        wire:click="updateAllPlugins"
                        wire:loading.attr="disabled"
                        class="inline-flex h-9 items-center gap-2 rounded-xl bg-brand-ink px-4 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                    >
                        <x-heroicon-o-arrow-up-circle class="h-4 w-4" />
                        {{ __('Update all') }}
                    </button>
                @endif
            </header>

            @if (! $pluginsLoaded)
                <div class="mt-5 text-center">
                    <button
                        type="button"
                        wire:click="loadPlugins"
                        wire:loading.attr="disabled"
                        wire:target="loadPlugins"
                        class="inline-flex h-10 items-center gap-2 rounded-xl bg-brand-ink px-5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                    >
                        <x-heroicon-o-arrow-down-tray wire:loading.remove wire:target="loadPlugins" class="h-4 w-4" />
                        <x-spinner wire:loading wire:target="loadPlugins" variant="cream" size="sm" />
                        <span wire:loading.remove wire:target="loadPlugins">{{ __('Load installed plugins') }}</span>
                        <span wire:loading wire:target="loadPlugins">{{ __('Loading…') }}</span>
                    </button>
                </div>
            @else
                @if (empty($plugins))
                    <p class="mt-5 text-center text-sm text-brand-mist">{{ __('No plugins installed.') }}</p>
                @else
                    <ul class="mt-5 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                        @foreach ($plugins as $plugin)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $plugin['name'] }}</p>
                                    <p class="mt-0.5 text-xs text-brand-mist">v{{ $plugin['version'] }} · {{ $plugin['status'] }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-[11px]">
                                    @if ($plugin['update'] === 'available')
                                        <span class="rounded-full bg-brand-gold/20 px-2 py-0.5 font-semibold text-brand-ink">{{ __('Update available') }}</span>
                                    @endif
                                    @foreach ($plugin['advisories'] as $advisory)
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-700"
                                            title="{{ $advisory['title'] }}{{ $advisory['cve'] ? ' ('.$advisory['cve'].')' : '' }}{{ $advisory['patched'] ? ' — patched in '.$advisory['patched'] : '' }}"
                                        >
                                            <x-heroicon-m-shield-exclamation class="h-3 w-3" />
                                            {{ strtoupper($advisory['severity']) }}
                                        </span>
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-[11px] text-brand-mist">{{ __('Vulnerability data: Wordfence Intelligence (free tier, 24h cache).') }}</p>
                @endif
            @endif
            <x-input-error :messages="$errors->get('plugins')" class="mt-3" />
        </section>
    @endif

    {{-- Placeholder sub-tabs --}}
    @if (in_array($tab, ['database', 'hardening'], true))
        <section class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-cream/20 p-8 text-center">
            <p class="text-sm text-brand-mist">{{ __('This sub-tab ships in the next release.') }}</p>
        </section>
    @endif
    @endif
</div>
