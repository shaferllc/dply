{{-- One merged card, dense head, hairline-separated bodies — the same chrome
     as System users / Files / FTP accounts. This section previously stacked a
     floating header card, a floating pill nav and a floating body card with
     space-y-6 between them, which read as three unrelated panels and used
     roughly twice the vertical space of every neighbouring surface. --}}
<div>
    {{-- The wp-config tab's editor mounts on a tab-switch morph, where an @vite
         inside the partial never executes — register the loader on first render. --}}
    @vite(['resources/js/file-browser-editor-lazy.js'])

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
                'config' => ['label' => __('wp-config.php'), 'icon' => 'heroicon-o-document-text'],
            ] as $key => $meta)
                @continue($key === 'git' && ! $gitSourcesSupported)
                @continue($key === 'config' && ! $canDestroy)
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
            $cronState = (array) data_get($site->meta, 'wp_cron', []);
            $handler = $cronState['handler'] ?? 'wp_cron';
            $switchingTo = $cronState['switching_to'] ?? null;
            $cronError = $cronState['error'] ?? null;
        @endphp
        <div class="border-b border-brand-ink/10 last:border-b-0" @if ($switchingTo) wire:poll.5s @endif>
            <div class="flex flex-wrap items-start justify-between gap-3 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Cron handler') }}</h3>
                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('WordPress\'s built-in wp-cron runs on page loads — fine for quiet sites, unreliable and slow once you grow. System cron runs `wp cron event run --due-now` every minute from a real crontab entry instead.') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span @class([
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1',
                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $handler === 'system_cron' && ! $switchingTo,
                        'bg-brand-sand/40 text-brand-ink ring-brand-ink/10' => $handler !== 'system_cron' && ! $switchingTo,
                        'bg-amber-50 text-amber-800 ring-amber-200' => (bool) $switchingTo,
                    ])>
                        @if ($switchingTo)
                            <x-spinner size="sm" /> {{ __('switching…') }}
                        @else
                            {{ $handler === 'system_cron' ? __('system cron') : __('wp-cron (HTTP)') }}
                        @endif
                    </span>
                    @unless ($switchingTo)
                        @if ($handler === 'system_cron')
                            <x-spinner-button size="xs" variant="secondary" type="button" target="switchToWpCron" wire:click="switchToWpCron">{{ __('Switch back to wp-cron') }}</x-spinner-button>
                        @else
                            <x-spinner-button size="xs" variant="primary" type="button" icon="heroicon-o-bolt" target="switchToSystemCron" wire:click="switchToSystemCron">{{ __('Switch to system cron') }}</x-spinner-button>
                        @endif
                    @endunless
                </div>
            </div>
            <div class="px-3 py-2 sm:px-4">
                <p class="text-xs text-brand-moss">
                    @if ($handler === 'system_cron')
                        {{ __('System cron (recommended) — a managed crontab entry runs due events every minute; wp-cron is disabled.') }}
                    @else
                        {{ __('wp-cron via HTTP (default) — events run when someone visits the site.') }}
                    @endif
                </p>
                @if ($cronError)
                    <p class="mt-1.5 rounded bg-rose-50 px-2 py-1 text-xs text-rose-800">{{ __('Last switch failed: :err', ['err' => $cronError]) }}</p>
                @endif
                <x-input-error :messages="$errors->get('cron')" class="mt-1.5" />
            </div>
        </div>
    @endif

    {{-- PLUGINS --}}
    {{-- Scheduled events: what WordPress has queued, what is overdue, and the
         controls to run or unschedule them. --}}
    @if ($tab === 'cron')
        @php $cronView = $this->cronEventRows(); @endphp
        <div class="border-b border-brand-ink/10 last:border-b-0">
            <div class="flex flex-wrap items-center justify-between gap-3 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Scheduled events') }}</h3>
                    <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Everything WordPress has queued and when it is next due. Events long overdue mean cron is not running.') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($canMutate)
                        <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-play" target="runDueCronEvents" wire:click="runDueCronEvents">{{ __('Run all due') }}</x-spinner-button>
                    @endif
                    <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-arrow-path" target="loadCronEvents" wire:click="loadCronEvents">{{ $cronEventsLoaded ? __('Refresh') : __('Load events') }}</x-spinner-button>
                </div>
            </div>

            @if ($cronEventsLoaded)
                @if ($cronView['overdue'] > 0)
                    <p class="border-b border-amber-200/70 bg-amber-50/70 px-3 py-2 text-xs text-amber-900 sm:px-4">
                        {{ trans_choice('{1} 1 event is overdue by more than 10 minutes.|[2,*] :count events are overdue by more than 10 minutes.', $cronView['overdue'], ['count' => $cronView['overdue']]) }}
                        {{ data_get($site->meta, 'wp_cron.handler') === 'system_cron' ? __('The system crontab entry may not be running.') : __('wp-cron only runs when someone visits — consider system cron.') }}
                    </p>
                @endif

                @if ($cronEvents === [])
                    <p class="px-3 py-3 text-xs text-brand-moss sm:px-4">{{ __('No events scheduled.') }}</p>
                @else
                    <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                        <input type="search" wire:model.live.debounce.250ms="cronEventFilter" aria-label="{{ __('Filter events') }}" placeholder="{{ __('Filter by hook') }}" class="block w-full rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest sm:max-w-xs" />
                        <span class="text-2xs text-brand-mist">{{ __(':shown of :total', ['shown' => count($cronView['rows']), 'total' => count($cronEvents)]) }}</span>
                    </div>
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
                                @foreach ($cronView['rows'] as $event)
                                    <tr wire:key="cron-{{ $event['hook'] }}-{{ $event['next_run'] }}" @class(['bg-amber-50/40' => $event['overdue']])>
                                        <td class="px-3 py-2 font-mono text-brand-ink sm:px-4">{{ $event['hook'] ?: '—' }}</td>
                                        <td class="px-3 py-2 text-brand-moss">
                                            {{ $event['relative'] ?: ($event['next_run'] ?: '—') }}
                                            @if ($event['overdue'])
                                                <span class="ml-1 rounded-full bg-amber-100 px-1.5 text-2xs font-semibold text-amber-900">{{ __('overdue') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-brand-moss">{{ $event['recurrence'] ?: '—' }}</td>
                                        <td class="px-3 py-2 text-right sm:px-4">
                                            @if ($canMutate)
                                                <div class="inline-flex gap-1.5">
                                                    <x-spinner-button size="xs" variant="secondary" type="button" target="runCronEvent" wire:click="runCronEvent(@js($event['hook']))">{{ __('Run now') }}</x-spinner-button>
                                                    <button type="button" wire:click="confirmDeleteCronEvent(@js($event['hook']))" class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100">{{ __('Unschedule') }}</button>
                                                </div>
                                            @endif
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
        @include('livewire.sites.wordpress.partials.hardening-tab')
    @endif

    @if ($tab === 'config')
        @include('livewire.sites.wordpress.partials.config-tab')
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
