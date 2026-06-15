@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
    ];

    $overallTone = match ($report['overall']) {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        'info' => $tonePalette['sky'],
        default => $tonePalette['emerald'],
    };

    $summary = $report['summary'] ?? [];
    $auth = $report['auth'] ?? [];
    $fail2ban = $report['fail2ban'] ?? [];
    $firewall = $report['firewall'] ?? [];
    $sshd = $report['sshd'] ?? [];
    $scan = $report['scan'] ?? [];
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;

    $statusBadge = static function (?string $value, array $good = [], array $bad = []) use ($tonePalette): string {
        if ($value === null || $value === '') {
            return $tonePalette['mist'];
        }
        $normalized = strtolower($value);
        if (in_array($normalized, $good, true)) {
            return $tonePalette['emerald'];
        }
        if (in_array($normalized, $bad, true)) {
            return $tonePalette['amber'];
        }

        return $tonePalette['mist'];
    };

    $formatBoolish = static function (?string $value): string {
        if ($value === null || $value === '') {
            return '—';
        }

        return match (strtolower($value)) {
            'yes', 'true', '1', 'active', 'running' => __('Yes'),
            'no', 'false', '0', 'inactive', 'missing' => __('No'),
            default => $value,
        };
    };
@endphp

<x-server-workspace-layout
    :server="$server"
    active="security-digest"
    :title="__('Security digest')"
    :description="__('SSH auth failure volume, fail2ban jails, host firewall posture, and sshd hardening — lightweight read-only digest over root SSH.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    {{-- In-page tabs: posture overview, auth/brute-force detail, host hardening,
         and notification routing for this server's server.security_digest.* events.
         Mirrors the cert-inventory workspace. --}}
    <div class="mb-6 border-b border-brand-ink/10">
        <nav class="-mb-px flex flex-wrap gap-6" aria-label="{{ __('Security digest sections') }}">
            @php
                $tabBase = 'inline-flex items-center gap-1.5 border-b-2 px-1 py-3 text-sm font-medium transition-colors';
                $tabOn = 'border-brand-forest text-brand-ink';
                $tabOff = 'border-transparent text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink';
            @endphp
            <button type="button" wire:click="setDigestTab('overview')" @class([$tabBase, $digest_tab === 'overview' ? $tabOn : $tabOff])>
                <x-heroicon-o-shield-exclamation class="h-4 w-4" aria-hidden="true" />
                {{ __('Overview') }}
            </button>
            <button type="button" wire:click="setDigestTab('auth')" @class([$tabBase, $digest_tab === 'auth' ? $tabOn : $tabOff])>
                <x-heroicon-o-document-text class="h-4 w-4" aria-hidden="true" />
                {{ __('Auth & fail2ban') }}
            </button>
            <button type="button" wire:click="setDigestTab('hardening')" @class([$tabBase, $digest_tab === 'hardening' ? $tabOn : $tabOff])>
                <x-heroicon-o-lock-closed class="h-4 w-4" aria-hidden="true" />
                {{ __('Hardening') }}
            </button>
            <button type="button" wire:click="setDigestTab('notifications')" @class([$tabBase, $digest_tab === 'notifications' ? $tabOn : $tabOff])>
                <x-heroicon-o-bell class="h-4 w-4" aria-hidden="true" />
                {{ __('Notifications') }}
            </button>
        </nav>
    </div>

    {{-- Overview --}}
    <div @class(['space-y-6', 'hidden' => $digest_tab !== 'overview'])>
        @if ($isDeployer)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <x-icon-badge tone="amber">
                            <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can review the digest but cannot run SSH scans.') }}</p>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        @if (! $opsReady)
            @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
        @endif

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $overallTone }}">
                            <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Overall') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                @switch($report['overall'])
                                    @case('critical') {{ __('Immediate review recommended') }} @break
                                    @case('warning') {{ __('Security signals need attention') }} @break
                                    @case('info') {{ __('Posture looks mostly healthy') }} @break
                                    @default {{ __('SSH surface looks calm') }}
                                @endswitch
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                @if ($scan['checked_at'] ?? null)
                                    {{ __('Last scan :time', ['time' => $scan['checked_at']->diffForHumans()]) }}
                                    @if ($scan['stale'] ?? false)
                                        · {{ __('Stale after :hours h', ['hours' => $scan['stale_hours'] ?? 24]) }}
                                    @endif
                                @else
                                    {{ __('No scan yet — refresh when SSH is ready') }}
                                @endif
                            </p>
                        </div>
                    </div>
                    @if ($opsReady && ! $isDeployer)
                        <button
                            type="button"
                            wire:click="refreshSecurityDigestScan"
                            wire:loading.attr="disabled"
                            wire:target="refreshSecurityDigestScan"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="refreshSecurityDigestScan" aria-hidden="true" />
                            <span wire:loading.remove wire:target="refreshSecurityDigestScan">{{ __('Refresh digest') }}</span>
                            <span wire:loading wire:target="refreshSecurityDigestScan">{{ __('Scanning…') }}</span>
                        </button>
                    @endif
                </div>
            </div>

            @if (($report['alert_count'] ?? 0) > 0)
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['alerts'] as $alert)
                        @php
                            $alertTone = match ($alert['severity']) {
                                'critical' => $tonePalette['rose'],
                                'warning' => $tonePalette['amber'],
                                default => $tonePalette['sky'],
                            };
                        @endphp
                        <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $alertTone }}">
                                    <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p>
                                </div>
                            </div>
                            @if ($alert['href'] && $alert['link_label'])
                                <a href="{{ $alert['href'] }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                                    {{ $alert['link_label'] }}
                                    <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="grid gap-4 border-t border-brand-ink/10 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-4">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('auth.log failures') }}</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ $auth['failed_lines'] ?? '—' }}</p>
                    <p class="mt-1 text-[11px] text-brand-moss">{{ __('Total Failed password + Invalid user') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Recent burst') }}</p>
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ $auth['recent_lines'] ?? '—' }}</p>
                    <p class="mt-1 text-[11px] text-brand-moss">{{ __('Last ~5000 auth.log lines') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('fail2ban') }}</p>
                    <p class="mt-1 text-lg font-semibold text-brand-ink">{{ $fail2ban['active'] ?? '—' }}</p>
                    <p class="mt-1 text-[11px] text-brand-moss">
                        {{ trans_choice(':count jail|:count jails', $summary['jail_count'] ?? 0, ['count' => $summary['jail_count'] ?? 0]) }}
                        · {{ trans_choice(':count banned now|:count banned now', $summary['banned_now'] ?? 0, ['count' => $summary['banned_now'] ?? 0]) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('UFW firewall') }}</p>
                    <p class="mt-1 text-lg font-semibold text-brand-ink">{{ $firewall['ufw_active'] ?? '—' }}</p>
                    <p class="mt-1 text-[11px] text-brand-moss">{{ __('Host packet filter status') }}</p>
                </div>
            </div>
        </section>
    </div>

    {{-- Auth & fail2ban --}}
    <div @class(['space-y-6', 'hidden' => $digest_tab !== 'auth'])>
        <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <x-icon-badge>
                            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Auth log breakdown') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Brute-force indicators') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Warning ≥ :warning · Critical ≥ :critical · Recent burst ≥ :recent', [
                                    'warning' => $summary['warning_threshold'] ?? 50,
                                    'critical' => $summary['critical_threshold'] ?? 200,
                                    'recent' => config('server_security_digest.thresholds.auth_failed_recent_warning', 25),
                                ]) }}
                            </p>
                        </div>
                    </div>
                </div>
                <dl class="grid gap-4 px-6 py-6 sm:grid-cols-2 sm:px-7">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Invalid user attempts') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $auth['invalid_user_lines'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Failed password attempts') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $auth['failed_password_lines'] ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Volume severity') }}</dt>
                        <dd class="mt-1">
                            @php
                                $authSeverity = $auth['severity'] ?? 'unknown';
                                $authTone = match ($authSeverity) {
                                    'critical' => $tonePalette['rose'],
                                    'warning' => $tonePalette['amber'],
                                    'ok' => $tonePalette['emerald'],
                                    default => $tonePalette['mist'],
                                };
                            @endphp
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $authTone }}">
                                {{ $authSeverity }}
                            </span>
                        </dd>
                    </div>
                </dl>
                <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                    <a href="{{ route('servers.logs', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                        {{ __('Open system logs') }}
                        <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                    </a>
                </div>
            </section>

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sky'] }}">
                            <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('fail2ban jails') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Ban activity by jail') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Per-jail stats from fail2ban-client status during scan.') }}</p>
                        </div>
                    </div>
                    <a href="{{ route('servers.firewall', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        {{ __('Firewall') }}
                    </a>
                </div>
            </div>

            @if (count($fail2ban['jail_rows'] ?? []) === 0)
                <div class="px-6 py-10 text-center sm:px-7">
                    <p class="text-sm font-medium text-brand-ink">
                        @if ($scan['never_scanned'] ?? true)
                            {{ __('Run a digest scan to populate jail stats') }}
                        @elseif (($fail2ban['active'] ?? '') === 'missing')
                            {{ __('fail2ban is not installed on this host') }}
                        @else
                            {{ __('No jail detail captured yet') }}
                        @endif
                    </p>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Refresh digest when SSH is ready — sshd jail stats appear here automatically.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-brand-sand/30 text-brand-moss">
                            <tr>
                                <th class="px-3 py-2 font-semibold">{{ __('Jail') }}</th>
                                <th class="px-3 py-2 font-semibold text-right">{{ __('Banned now') }}</th>
                                <th class="px-3 py-2 font-semibold text-right">{{ __('Total banned') }}</th>
                                <th class="px-3 py-2 font-semibold text-right">{{ __('Failed now') }}</th>
                                <th class="px-3 py-2 font-semibold text-right">{{ __('Total failed') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Banned IPs') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($fail2ban['jail_rows'] as $jail)
                                <tr @class(['bg-amber-50/30' => ($jail['currently_banned'] ?? 0) >= 1])>
                                    <td class="px-3 py-2 font-medium text-brand-ink">{{ $jail['name'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-brand-ink">{{ $jail['currently_banned'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-brand-moss">{{ $jail['total_banned'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-brand-moss">{{ $jail['currently_failed'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums text-brand-moss">{{ $jail['total_failed'] ?? '—' }}</td>
                                    <td class="max-w-[14rem] px-3 py-2 font-mono text-[10px] text-brand-moss">
                                        @if (count($jail['banned_ips'] ?? []) > 0)
                                            {{ implode(', ', array_slice($jail['banned_ips'], 0, 4)) }}
                                            @if (count($jail['banned_ips']) > 4)
                                                <span class="text-brand-mist">+{{ count($jail['banned_ips']) - 4 }}</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    {{-- Hardening --}}
    <div @class(['space-y-6', 'hidden' => $digest_tab !== 'hardening'])>
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('SSH hardening') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Effective sshd settings') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Sampled with sshd -T on the host during scan.') }}</p>
                    </div>
                </div>
            </div>
            <dl class="grid gap-4 px-6 py-6 sm:grid-cols-2 sm:px-7">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('PasswordAuthentication') }}</dt>
                    <dd class="mt-1">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $statusBadge($sshd['password_authentication'] ?? null, ['no', 'false', '0'], ['yes', 'true', '1']) }}">
                            {{ $formatBoolish($sshd['password_authentication'] ?? null) }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('PermitRootLogin') }}</dt>
                    <dd class="mt-1">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $statusBadge($sshd['permit_root_login'] ?? null, ['no', 'false', '0', 'prohibit-password', 'without-password', 'forced-commands-only'], ['yes', 'true', '1']) }}">
                            {{ $sshd['permit_root_login'] ?? '—' }}
                        </span>
                    </dd>
                </div>
            </dl>
            <div class="flex flex-wrap gap-4 border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                    {{ __('SSH keys') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                </a>
                @if ($sshAccessEnabled)
                    <a href="{{ route('servers.ssh-access', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                        {{ __('Access graph') }}
                        <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                    </a>
                @endif
            </div>
        </section>

        @if ($sshAccessEnabled && $sshAccess)
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <x-icon-badge>
                                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Access graph rollup') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                    {{ trans_choice(':count authorized key|:count authorized keys', $sshAccess['total_keys'], ['count' => $sshAccess['total_keys']]) }}
                                </h2>
                                <p class="mt-1 text-sm text-brand-moss">
                                    @if ($sshAccess['review_overdue'] > 0)
                                        {{ trans_choice(':count overdue review|:count overdue reviews', $sshAccess['review_overdue'], ['count' => $sshAccess['review_overdue']]) }}
                                    @elseif ($sshAccess['never_synced'] > 0)
                                        {{ trans_choice(':count key never synced|:count keys never synced', $sshAccess['never_synced'], ['count' => $sshAccess['never_synced']]) }}
                                    @else
                                        {{ __('Key sync and review posture from the access graph') }}
                                    @endif
                                    @if ($sshAccess['active_sessions'] > 0)
                                        · {{ trans_choice(':count active session|:count active sessions', $sshAccess['active_sessions'], ['count' => $sshAccess['active_sessions']]) }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('servers.ssh-access', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            {{ __('Open access graph') }}
                        </a>
                    </div>
                </div>
            </section>
        @endif
    </div>

    {{-- Notifications --}}
    <div @class(['space-y-6', 'hidden' => $digest_tab !== 'notifications'])>
        @include('livewire.servers.partials.security-digest.notifications-tab')
    </div>

    {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
         shared with the Notifications tab so an operator can add a channel without
         leaving the page; the new channel is auto-selected on success. --}}
    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
