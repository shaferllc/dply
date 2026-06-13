<div class="space-y-6">

    <x-hero-card
        :eyebrow="__('Site')"
        :title="__('WordPress')"
        :description="__('Manage this WordPress install — run wp-cli commands, update plugins, themes and core, take database snapshots, switch the cron handler, and apply hardening defaults.')"
        icon="globe-alt"
    />

    @if (! $site->isWordPressDetected())
        <section class="dply-card overflow-hidden">
            <div class="px-6 py-6 sm:px-7">
                <p class="max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('This section appears when the site is detected as a WordPress install — either from a wp-config.php in the repo or from a successful WordPress scaffold.') }}</p>
            </div>
        </section>
    @else

    {{-- Sub-tab nav --}}
    <nav class="flex flex-wrap items-center gap-1 rounded-2xl border border-brand-ink/10 bg-white p-1 shadow-sm">
        @foreach ([
            'console' => ['label' => __('Console'), 'enabled' => true],
            'plugins' => ['label' => __('Plugins'), 'enabled' => true],
            'themes' => ['label' => __('Themes'), 'enabled' => true],
            'users' => ['label' => __('Users'), 'enabled' => true],
            'core' => ['label' => __('Core'), 'enabled' => true],
            'database' => ['label' => __('Database'), 'enabled' => true],
            'cron' => ['label' => __('Cron'), 'enabled' => true],
            'hardening' => ['label' => __('Hardening'), 'enabled' => true],
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
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Console') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('wp-cli Console') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Run any wp-cli command. Inspect commands return inline; mutating commands queue and stream their output.') }}</p>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-7">
            <div class="grid gap-3 sm:grid-cols-[1fr_2fr_auto]">
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
            </div>
        </section>
    @endif

    {{-- CRON --}}
    @if ($tab === 'cron')
        @php
            $handler = data_get($site->meta, 'wp_cron.handler', 'wp_cron');
        @endphp
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Cron') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cron handler') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('WordPress\'s built-in wp-cron runs on every page load — fine for low-traffic sites, awful for performance once you grow. Switch to system cron and dply runs `wp cron event run --due-now` every minute via a real crontab entry.') }}</p>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-7">
            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4">
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
                <div class="mt-5">
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
            </div>
        </section>
    @endif

    {{-- PLUGINS --}}
    @if ($tab === 'plugins')
        @include('livewire.sites.wordpress.partials.plugins-tab')
    @endif

    {{-- THEMES --}}
    @if ($tab === 'themes')
        @include('livewire.sites.wordpress.partials.themes-tab')
    @endif

    {{-- USERS --}}
    @if ($tab === 'users')
        @include('livewire.sites.wordpress.partials.users-tab')
    @endif

    {{-- CORE --}}
    @if ($tab === 'core')
        @include('livewire.sites.wordpress.partials.core-tab')
    @endif

    {{-- DATABASE --}}
    @if ($tab === 'database')
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Database') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Database snapshots') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Manual `mysqldump` / `pg_dump` snapshots stored on this server (7-day TTL). BYO S3 destinations land in a follow-up PR.') }}</p>
                </div>
                <button
                    type="button"
                    wire:click="takeSnapshot"
                    wire:loading.attr="disabled"
                    wire:target="takeSnapshot"
                    class="ml-auto inline-flex h-9 shrink-0 items-center gap-2 rounded-xl bg-brand-ink px-4 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                >
                    <x-heroicon-o-camera wire:loading.remove wire:target="takeSnapshot" class="h-4 w-4" />
                    <x-spinner wire:loading wire:target="takeSnapshot" variant="cream" size="sm" />
                    <span wire:loading.remove wire:target="takeSnapshot">{{ __('Take snapshot') }}</span>
                    <span wire:loading wire:target="takeSnapshot">{{ __('Dumping…') }}</span>
                </button>
            </div>

            <div class="px-6 py-6 sm:px-7">
            <x-input-error :messages="$errors->get('snapshots')" class="mb-3" />

            @if ($snapshots->isEmpty())
                <p class="mt-5 text-center text-sm text-brand-mist">{{ __('No snapshots yet. Click "Take snapshot" to capture one.') }}</p>
            @else
                <ul class="mt-5 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                    @foreach ($snapshots as $snapshot)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-mono text-brand-ink">snap-{{ $snapshot->id }}</p>
                                <p class="mt-0.5 text-xs text-brand-mist">
                                    {{ $snapshot->reason }} · {{ number_format(($snapshot->bytes ?? 0) / 1024, 1) }}&nbsp;KB · {{ $snapshot->created_at?->diffForHumans() }}
                                    @if ($snapshot->expires_at)
                                        · expires {{ $snapshot->expires_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                'bg-brand-sand/40 text-brand-ink' => $snapshot->destination === 'local_disk',
                                'bg-brand-sage/15 text-brand-forest' => $snapshot->destination === 's3',
                            ])>{{ $snapshot->destination }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            </div>
        </section>
    @endif

    {{-- HARDENING --}}
    @if ($tab === 'hardening')
        @php
            $hardeningOpinions = collect(data_get($site->meta, 'scaffold.hardening', []))->keyBy('key');
            $opinions = [
                'disallow_file_edit' => [
                    'title' => __('Disallow in-admin file editor'),
                    'description' => __('Removes the Plugins / Themes file editor from wp-admin. Common attack vector for compromised admin accounts.'),
                    'wp_constant' => 'DISALLOW_FILE_EDIT',
                ],
                'force_ssl_admin' => [
                    'title' => __('Force SSL on /wp-admin'),
                    'description' => __('Refuses unencrypted login + admin pages. Required for the placeholder URL since it ships with HTTPS.'),
                    'wp_constant' => 'FORCE_SSL_ADMIN',
                ],
                'disable_wp_cron' => [
                    'title' => __('Disable wp-cron'),
                    'description' => __('Prevents WP from running cron on every page load. Pair with the Cron tab\'s "system cron" switch for the recommended setup.'),
                    'wp_constant' => 'DISABLE_WP_CRON',
                ],
            ];
        @endphp
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hardening') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Hardening defaults') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Each toggle below is an opinion the WordPress scaffold pipeline applied. Flip any of them off if your site has a specific reason — your audit log records every change.') }}</p>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-7">
            <x-input-error :messages="$errors->get('hardening')" class="mb-3" />

            <div class="space-y-3">
                @foreach ($opinions as $key => $copy)
                    @php $enabled = (bool) ($hardeningOpinions[$key]['enabled'] ?? false); @endphp
                    <div class="flex items-start justify-between gap-4 rounded-xl border border-brand-ink/10 bg-brand-cream/20 p-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-semibold text-brand-ink">{{ $copy['title'] }}</p>
                                <span class="rounded bg-brand-ink/[0.04] px-1.5 py-0.5 font-mono text-[10px] text-brand-moss">{{ $copy['wp_constant'] }}</span>
                            </div>
                            <p class="mt-1 text-xs text-brand-moss">{{ $copy['description'] }}</p>
                        </div>
                        <button
                            type="button"
                            wire:click="toggleHardening('{{ $key }}')"
                            wire:loading.attr="disabled"
                            wire:target="toggleHardening"
                            @class([
                                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                'bg-brand-sage' => $enabled,
                                'bg-brand-mist/40' => ! $enabled,
                            ])
                            aria-pressed="{{ $enabled ? 'true' : 'false' }}"
                            aria-label="{{ $copy['title'] }}"
                        >
                            <span @class([
                                'inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform',
                                'translate-x-5' => $enabled,
                                'translate-x-1' => ! $enabled,
                            ])></span>
                        </button>
                    </div>
                @endforeach
            </div>
            </div>
        </section>
    @endif

    <x-cli-snippet :commands="[
        ['label' => __('Run any wp-cli command'), 'command' => 'dply:wp '.$site->slug.' -- option get blogname'],
        ['label' => __('Switch wp-cron mode'), 'command' => 'dply:wp:cron:switch '.$site->slug.' --to=system'],
        ['label' => __('Search/replace in DB'), 'command' => 'dply:wp:search-replace '.$site->slug.' http://old.example.com https://new.example.com --dry-run'],
        ['label' => __('Apply hardening'), 'command' => 'dply:wp:hardening:apply '.$site->slug],
        ['label' => __('Update all plugins'), 'command' => 'dply:wp:plugin:update-all '.$site->slug],
        ['label' => __('Snapshot database'), 'command' => 'dply:snapshot:take '.$site->slug.' --reason=manual'],
        ['label' => __('List snapshots'), 'command' => 'dply:snapshot:list '.$site->slug],
        ['label' => __('Restore from snapshot'), 'command' => 'dply:snapshot:restore SNAPSHOT_ID --no-confirm'],
    ]" />

    @include('livewire.partials.confirm-action-modal')
    @endif
</div>
