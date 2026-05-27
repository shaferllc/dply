@props([
    'server',
    /** @var string|null Active server panel key (sites, overview, …) for nav highlight */
    'active' => null,
    'showNavigation' => null,
])

@php
    $card = 'dply-card overflow-hidden';
    $navLink = 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors';
    $workspaceNav = server_workspace_nav_for_server($server);
    // A host is navigable once READY. VM hosts additionally wait for the
    // setup script (setup_status === done); hosts with no SSH setup phase
    // — serverless / functions namespaces — are ready as soon as they are.
    $showNavigation = $showNavigation ?? (
        $server->status === \App\Models\Server::STATUS_READY
        && (
            $server->setup_status === \App\Models\Server::SETUP_STATUS_DONE
            || ! $server->hostCapabilities()->supportsSsh()
        )
    );
@endphp

@php
    $sshUserForUrl = trim((string) ($server->ssh_user ?? '')) !== '' ? trim((string) $server->ssh_user) : 'root';
    $sshPortForUrl = (int) ($server->ssh_port ?: 22);
    $sshUriHost = $server->ip_address ?? '';

    // ssh:// URL scheme handlers (macOS Terminal/iTerm, Windows Terminal, Termius) launch a terminal
    // and run `ssh user@host[:port]` for us. The user's SSH agent / default identity handles auth.
    $sshUri = $sshUriHost !== ''
        ? 'ssh://'.$sshUserForUrl.'@'.$sshUriHost.($sshPortForUrl !== 22 ? ':'.$sshPortForUrl : '')
        : '';

    // Build a full paste-fallback command. For the local fake-cloud server, ssh:// can't pass `-i`,
    // so include the bundled keyfile path so the copied command actually authenticates if pasted.
    $isLocalDockerServer = \App\Support\Servers\FakeCloudProvision::isFakeServer($server);
    $sshCommandParts = ['ssh'];
    if ($isLocalDockerServer) {
        $bundledKey = base_path('docker/ssh-dev/local_fake_cloud_ed25519');
        if (is_file($bundledKey)) {
            $sshCommandParts[] = '-i '.escapeshellarg($bundledKey);
            $sshCommandParts[] = '-o StrictHostKeyChecking=no';
            $sshCommandParts[] = '-o UserKnownHostsFile=/dev/null';
        }
    }
    if ($sshPortForUrl !== 22) {
        $sshCommandParts[] = '-p '.$sshPortForUrl;
    }
    $sshCommandParts[] = $sshUserForUrl.'@'.$sshUriHost;
    $sshCommand = $sshUriHost !== '' ? trim(implode(' ', $sshCommandParts)) : '';

    $localWebTerminalUrl = (string) config('server_provision.local_dev_web_terminal_url', '');
@endphp
<div
    class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
    x-data="{
        copiedIp: false,
        copiedSsh: false,
        async openTerminal(uri, command) {
            // Always copy the command to the clipboard so the user can paste in their own
            // terminal (paste-fallback for OSes without an ssh:// handler, and the only path
            // for docker-exec which has no URL scheme).
            try { await navigator.clipboard?.writeText(command); } catch (e) { /* ignore */ }
            this.copiedSsh = true;
            setTimeout(() => this.copiedSsh = false, 2400);
            // If we have a URL scheme (ssh://…), let the OS launch its handler.
            if (uri) {
                window.location.href = uri;
            }
        },
    }"
>
    <div @class([
        'lg:grid lg:gap-10' => $showNavigation,
        'lg:grid-cols-12' => $showNavigation,
    ])>
        @if ($showNavigation)
        @php
            // Deterministic gradient + initials avatar from the server name. Two hue stops
            // pulled from a stable hash so the same name always renders the same swatch —
            // no external service, no network roundtrip.
            $avatarSeed = (string) ($server->name ?: $server->id);
            $avatarHash = hexdec(substr(sha1($avatarSeed), 0, 12));
            $avatarHueA = $avatarHash % 360;
            $avatarHueB = ($avatarHueA + 60 + ((int) (($avatarHash >> 4) % 120))) % 360;
            $avatarInitials = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $avatarSeed) ?: 'S', 0, 2));
            $avatarStyle = "background-image: linear-gradient(135deg, hsl({$avatarHueA}deg 65% 56%) 0%, hsl({$avatarHueB}deg 65% 42%) 100%);";
        @endphp
        <aside class="lg:col-span-3 mb-8 lg:mb-0">
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 p-4 sm:p-5">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white font-semibold text-base shadow-sm ring-1 ring-brand-ink/10" style="{{ $avatarStyle }}">
                            {{ $avatarInitials }}
                        </span>
                        <div class="min-w-0 flex-1 leading-tight">
                            <p class="truncate text-base font-semibold text-brand-ink">{{ $server->name }}</p>
                            @if ($server->workspace)
                                @feature('surface.projects')
                                    <p class="mt-0.5 truncate text-xs text-brand-moss">
                                        <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">{{ $server->workspace->name }}</a>
                                    </p>
                                @endfeature
                            @endif
                        </div>
                    </div>

                    @php
                        $sshOneLine = ($server->ssh_user ?: 'root').'@'.$server->ip_address;
                        if ((int) ($server->ssh_port ?? 22) !== 22) {
                            $sshOneLine .= ':'.$server->ssh_port;
                        }
                    @endphp
                    <div class="mt-3 flex items-center gap-1.5">
                        <span class="shrink-0 rounded-md bg-brand-sand/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">SSH</span>
                        @if ($server->ip_address)
                            <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink" title="{{ $sshOneLine }}">{{ $sshOneLine }}</span>
                            <button
                                type="button"
                                class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                                title="{{ __('Copy IP') }}"
                                @click="navigator.clipboard.writeText(@js($server->ip_address)); copiedIp = true; setTimeout(() => copiedIp = false, 2000)"
                            >
                                <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                            </button>
                            @if ($sshCommand !== '')
                                <button
                                    type="button"
                                    @click="openTerminal(@js($sshUri), @js($sshCommand))"
                                    title="{{ __('Open SSH terminal') }} · {{ $sshCommand }}"
                                    class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                                >
                                    <x-heroicon-o-command-line class="h-3.5 w-3.5" />
                                </button>
                            @endif
                            @if ($localWebTerminalUrl !== '')
                                <a
                                    href="{{ $localWebTerminalUrl }}"
                                    target="_blank"
                                    rel="noopener"
                                    title="{{ __('Open the configured web terminal in a new tab.') }}"
                                    class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                                >
                                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5" />
                                </a>
                            @endif
                            <span x-show="copiedIp" x-cloak class="text-[10px] font-medium text-brand-forest">{{ __('Copied') }}</span>
                            <span x-show="copiedSsh" x-cloak class="text-[10px] font-medium text-brand-forest">{{ __('Cmd copied') }}</span>
                        @else
                            <span class="text-xs text-brand-mist">—</span>
                        @endif
                    </div>
                </div>
                <nav class="flex flex-col gap-0.5 p-2" aria-label="{{ __('Server sections') }}">
                    @php
                        // Cluster the flat nav into groups by their `group` key. Items
                        // without a group end up in `_ungrouped` and render headerless
                        // (back-compat for any nav entries that haven't been tagged yet).
                        $navGroups = collect($workspaceNav)
                            ->groupBy(fn ($i) => $i['group'] ?? '_ungrouped')
                            ->all();
                        $groupLabels = (array) config('server_workspace.nav_groups', []);
                        // Render groups in the order they first appear in the flat config,
                        // so a future config rearrangement reorders the sidebar with no
                        // additional code change.
                        $orderedGroupKeys = [];
                        foreach ($workspaceNav as $i) {
                            $gk = $i['group'] ?? '_ungrouped';
                            if (! in_array($gk, $orderedGroupKeys, true)) {
                                $orderedGroupKeys[] = $gk;
                            }
                        }
                    @endphp
                    @foreach ($orderedGroupKeys as $groupKey)
                        @php $itemsInGroup = $navGroups[$groupKey] ?? collect(); @endphp
                        @if ($itemsInGroup->isEmpty())
                            @continue
                        @endif
                        @if ($groupKey !== '_ungrouped' && isset($groupLabels[$groupKey]))
                            <p class="{{ ! $loop->first ? 'mt-3 ' : '' }}px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                                {{ __($groupLabels[$groupKey]) }}
                            </p>
                        @endif
                        @foreach ($itemsInGroup as $item)
                        @php
                            $key = $item['key'];
                            $icon = $item['icon'];
                            $label = __($item['label']);
                            $navHref = server_workspace_nav_item_url($server, $item);
                            $needsSetup = (bool) ($item['needs_setup'] ?? false);
                        @endphp
                        <a
                            href="{{ $navHref }}"
                            wire:navigate
                            @class([
                                $navLink,
                                'bg-brand-sand/60 text-brand-ink' => $active === $key,
                                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active !== $key,
                            ])
                        >
                            @switch($icon)
                                @case('globe-alt')
                                    <x-heroicon-o-globe-alt class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('cpu-chip')
                                    <x-heroicon-o-cpu-chip class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('circle-stack')
                                    <x-heroicon-o-circle-stack class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('clock')
                                    <x-heroicon-o-clock class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('server-stack')
                                    <x-heroicon-o-server-stack class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('shield-check')
                                    <x-heroicon-o-shield-check class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('key')
                                    <x-heroicon-o-key class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('document-text')
                                    <x-heroicon-o-document-text class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('rocket-launch')
                                    <x-heroicon-o-rocket-launch class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('clipboard-document-list')
                                    <x-heroicon-o-clipboard-document-list class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('wrench-screwdriver')
                                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('cog-8-tooth')
                                    <x-heroicon-o-cog-8-tooth class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('chart-bar')
                                    <x-heroicon-o-chart-bar class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('light-bulb')
                                    <x-heroicon-o-light-bulb class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('rectangle-stack')
                                    <x-heroicon-o-rectangle-stack class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('command-line')
                                    <x-heroicon-o-command-line class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('play-circle')
                                    <x-heroicon-o-play-circle class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('bolt')
                                    <x-heroicon-o-bolt class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('user-group')
                                    <x-heroicon-o-user-group class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('calendar-days')
                                    <x-heroicon-o-calendar-days class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('archive-box')
                                    <x-heroicon-o-archive-box class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('folder')
                                    <x-heroicon-o-folder class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                            @endswitch
                            <span class="flex-1 truncate">{{ $label }}</span>
                            @if ($needsSetup)
                                <span
                                    class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"
                                    role="img"
                                    aria-label="{{ __('Setup required') }}"
                                    title="{{ __('Supervisor is not installed yet. Open this section to set it up.') }}"
                                ></span>
                            @endif
                        </a>
                        @endforeach
                    @endforeach
                </nav>
                <div class="border-t border-brand-ink/10 p-3">
                    <a
                        href="{{ route('servers.index') }}"
                        wire:navigate
                        class="flex items-center gap-2 text-xs font-medium text-brand-moss hover:text-brand-ink"
                    >
                        <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                        {{ __('All servers') }}
                    </a>
                </div>
            </div>
        </aside>
        @endif

        <div @class([
            'min-w-0',
            'lg:col-span-9' => $showNavigation,
        ])>
            <x-trial-pause-banner :organization="$server->organization" />
            {{ $slot }}
        </div>
    </div>

</div>
