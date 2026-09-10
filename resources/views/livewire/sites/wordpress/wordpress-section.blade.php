{{-- One merged card, dense head, hairline-separated bodies — the same chrome
     as System users / Files / FTP accounts. This section previously stacked a
     floating header card, a floating pill nav and a floating body card with
     space-y-6 between them, which read as three unrelated panels and used
     roughly twice the vertical space of every neighbouring surface. --}}
<div>
    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-globe-alt"
            :title="__('WordPress')"
            :note="__('Run wp-cli commands, update plugins, themes and core, take database snapshots, switch the cron handler, and apply hardening defaults.')"
        />

    @if (! $site->isWordPressDetected())
        <div class="border-b border-brand-ink/10 last:border-b-0">
            <div class="px-3 py-2.5 sm:px-4">
                <p class="max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('This section appears when the site is detected as a WordPress install — either from a wp-config.php in the repo or from a successful WordPress scaffold.') }}</p>
            </div>
        </div>
    @else

    {{-- Flush tab strip, not a floating pill bar. --}}
    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <x-server-workspace-tablist :aria-label="__('WordPress sections')" scroll bare class="!mb-0 w-full">
            @foreach ([
                'console' => ['label' => __('Console'), 'icon' => 'heroicon-o-command-line'],
                'plugins' => ['label' => __('Plugins'), 'icon' => 'heroicon-o-puzzle-piece'],
                'themes' => ['label' => __('Themes'), 'icon' => 'heroicon-o-paint-brush'],
                'git' => ['label' => __('Git'), 'icon' => 'heroicon-o-code-bracket'],
                'users' => ['label' => __('Users'), 'icon' => 'heroicon-o-users'],
                'core' => ['label' => __('Core'), 'icon' => 'heroicon-o-cube'],
                'database' => ['label' => __('Database'), 'icon' => 'heroicon-o-circle-stack'],
                'cron' => ['label' => __('Cron'), 'icon' => 'heroicon-o-clock'],
                'tools' => ['label' => __('Tools'), 'icon' => 'heroicon-o-wrench-screwdriver'],
                'hardening' => ['label' => __('Hardening'), 'icon' => 'heroicon-o-shield-check'],
            ] as $key => $meta)
                @continue($key === 'git' && ! $gitSourcesSupported)
                <x-server-workspace-tab :icon="$meta['icon']" :active="$tab === $key" wire:click="$set('tab', '{{ $key }}')">
                    {{ $meta['label'] }}
                </x-server-workspace-tab>
            @endforeach
        </x-server-workspace-tablist>
    </div>

    {{-- CONSOLE --}}
    @if ($tab === 'console')
        <div class="border-b border-brand-ink/10 last:border-b-0">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('wp-cli Console') }}</h3>
                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Run any wp-cli command. Inspect commands return inline; mutating commands queue and stream their output.') }}</p>
                    @if (! $canMutate)
                        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Your role can inspect this site. Mutating wp-cli (install, users, SQL) needs edit access. Secret values stay hidden.') }}</p>
                    @endif
                </div>
            </div>

            <div class="px-3 py-2.5 sm:px-4">
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
                            'rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                            'bg-brand-sage/15 text-brand-forest' => $latestRun->status === 'completed',
                            'bg-rose-100 text-rose-700' => $latestRun->status === 'failed',
                            'bg-brand-gold/20 text-brand-ink animate-pulse' => in_array($latestRun->status, ['queued', 'running'], true),
                        ])>{{ $latestRun->status }}</span>
                        @if ($latestRun->exit_code !== null)
                            <span class="text-brand-mist">exit {{ $latestRun->exit_code }}</span>
                        @endif
                    </div>
                    @if ($latestRun->stdout)
                        <pre class="mt-3 max-h-72 overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-xs leading-relaxed text-brand-cream">{{ $latestRun->stdout }}</pre>
                    @endif
                    @if ($latestRun->stderr)
                        <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-rose-950/95 p-3 font-mono text-xs leading-relaxed text-rose-100">{{ $latestRun->stderr }}</pre>
                    @endif
                </div>
            @endif

            @if ($history->isNotEmpty())
                <div class="mt-6">
                    <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Recent runs') }}</h4>
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
        </div>
    @endif

    {{-- CRON --}}
    @if ($tab === 'cron')
        @php
            $handler = data_get($site->meta, 'wp_cron.handler', 'wp_cron');
        @endphp
        <div class="border-b border-brand-ink/10 last:border-b-0">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Cron handler') }}</h3>
                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('WordPress\'s built-in wp-cron runs on every page load — fine for low-traffic sites, awful for performance once you grow. Switch to system cron and dply runs `wp cron event run --due-now` every minute via a real crontab entry.') }}</p>
                </div>
            </div>

            <div class="px-3 py-2.5 sm:px-4">
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
        </div>
    @endif

    {{-- PLUGINS --}}
    {{-- Scheduled events. The Cron tab could switch the handler but never showed
         what was scheduled, so a stuck job was invisible from here. --}}
    @if ($tab === 'cron')
        <div class="border-b border-brand-ink/10 last:border-b-0">
            <div class="flex flex-wrap items-center justify-between gap-3 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Scheduled events') }}</h3>
                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Everything WordPress has queued, and when it is next due. Run one now to test it.') }}</p>
                </div>
                <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-arrow-path" target="loadCronEvents" wire:click="loadCronEvents">{{ __('Load events') }}</x-spinner-button>
            </div>

            @if ($cronEventsLoaded)
                @if ($cronEvents === [])
                    <p class="px-3 py-3 text-xs text-brand-moss sm:px-4">{{ __('No events scheduled.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-brand-sand/30 text-2xs uppercase tracking-wide text-brand-moss">
                                <tr>
                                    <th class="px-3 py-2 sm:px-4">{{ __('Hook') }}</th>
                                    <th class="px-3 py-2">{{ __('Next run') }}</th>
                                    <th class="px-3 py-2">{{ __('Recurrence') }}</th>
                                    <th class="px-3 py-2 text-right sm:px-4">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ($cronEvents as $event)
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-brand-ink sm:px-4">{{ $event['hook'] ?? '—' }}</td>
                                        <td class="px-3 py-2 text-brand-moss">{{ $event['next_run_relative'] ?? ($event['next_run'] ?? '—') }}</td>
                                        <td class="px-3 py-2 text-brand-moss">{{ $event['recurrence'] ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right sm:px-4">
                                            <x-spinner-button size="xs" variant="secondary" type="button" target="runCronEvent" wire:click="runCronEvent('{{ $event['hook'] ?? '' }}')">{{ __('Run now') }}</x-spinner-button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    @endif

    @if ($tab === 'plugins')
        @include('livewire.sites.wordpress.partials.plugins-tab')
    @endif

    {{-- THEMES --}}
    @if ($tab === 'themes')
        @include('livewire.sites.wordpress.partials.themes-tab')
    @endif

    {{-- USERS --}}
    {{-- Themes & plugins from their own repos — its own component (a separate
         form and job flow), embedded so the WordPress tabs stay one click away. --}}
    @if ($tab === 'git' && $gitSourcesSupported)
        @livewire('sites.git-sources', ['server' => $site->server, 'site' => $site], key('wp-git-sources-'.$site->id))
    @endif

    @if ($tab === 'users')
        @include('livewire.sites.wordpress.partials.users-tab')
    @endif

    {{-- CORE --}}
    @if ($tab === 'core')
        @include('livewire.sites.wordpress.partials.core-tab')
    @endif

    {{-- DATABASE --}}
    @if ($tab === 'database')
        @include('livewire.sites.wordpress.partials.database-tab')
    @endif

    {{-- HARDENING --}}
    @if ($tab === 'tools')
        @include('livewire.sites.wordpress.partials.tools-tab')
    @endif

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
        <div class="border-b border-brand-ink/10 last:border-b-0">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Hardening defaults') }}</h3>
                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Each toggle below is an opinion the WordPress scaffold pipeline applied. Flip any of them off if your site has a specific reason — your audit log records every change.') }}</p>
                </div>
            </div>

            <div class="px-3 py-2.5 sm:px-4">
            <x-input-error :messages="$errors->get('hardening')" class="mb-3" />

            <div class="space-y-3">
                @foreach ($opinions as $key => $copy)
                    @php $enabled = (bool) ($hardeningOpinions[$key]['enabled'] ?? false); @endphp
                    <div class="flex items-start justify-between gap-4 rounded-xl border border-brand-ink/10 bg-brand-cream/20 p-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-semibold text-brand-ink">{{ $copy['title'] }}</p>
                                <span class="rounded bg-brand-ink/[0.04] px-1.5 py-0.5 font-mono text-2xs text-brand-moss">{{ $copy['wp_constant'] }}</span>
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
        </div>
    @endif

    {{-- Footer strip, matching every other workspace card (see logs.blade.php).
         Rendered bare, the disclosure sat flush against the card edge with no
         padding and no top rule — it read as escaping the card rather than
         closing it. --}}
    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
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
    </div>

    @include('livewire.partials.confirm-action-modal')
    @endif
    </section>
</div>
