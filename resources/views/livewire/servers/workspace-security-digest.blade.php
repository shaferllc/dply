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

    $overallLabel = match ($report['overall']) {
        'critical' => __('Needs attention'),
        'warning' => __('Review signals'),
        'info' => __('Mostly healthy'),
        default => __('Calm'),
    };

    $summary = $report['summary'] ?? [];
    $auth = $report['auth'] ?? [];
    $fail2ban = $report['fail2ban'] ?? [];
    $firewall = $report['firewall'] ?? [];
    $sshd = $report['sshd'] ?? [];
    $scan = $report['scan'] ?? [];
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;

    // Scan freshness used to be a whole "Overall" section under the tabs; it is
    // one clause beside the verdict pill now.
    $scanLabel = ($scan['checked_at'] ?? null)
        ? __('Scanned :time', ['time' => $scan['checked_at']->diffForHumans()])
            . (($scan['stale'] ?? false) ? ' · ' . __('stale after :hours h', ['hours' => $scan['stale_hours'] ?? 24]) : '')
        : __('Never scanned');

    // Tone keys for x-workspace-stat-strip ('ok' | 'warn' | 'bad' | null) —
    // replaces the ring/bg badge classes the old tile grid needed.
    $statusTone = static function (?string $value, array $good = [], array $bad = []): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = strtolower($value);
        if (in_array($normalized, $good, true)) {
            return 'ok';
        }
        if (in_array($normalized, $bad, true)) {
            return 'warn';
        }

        return null;
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

    $severityTone = match ($auth['severity'] ?? null) {
        'critical' => 'bad',
        'warning' => 'warn',
        'ok' => 'ok',
        default => null,
    };
@endphp

<x-server-workspace-layout
    :server="$server"
    active="security-digest"
    :title="__('Security digest')"
    :description="__('SSH auth failure volume, fail2ban jails, host firewall posture, and sshd hardening — lightweight read-only digest over root SSH.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching Docker / Databases / Cron. The icon-badge +
             title + prose stack this replaced restated the breadcrumb, and the
             verdict, scan age, and Refresh each had their own full-width row
             below the tab strip — roughly 200px of chrome above the first
             number. Refresh rides the head so it works from every sub-tab. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-shield-exclamation"
            :tone="in_array($report['overall'], ['critical', 'warning'], true) ? 'amber' : null"
            :title="__('Security')"
            :note="__('SSH auth failures, fail2ban jails, host firewall posture, and sshd hardening — a read-only digest over root SSH.')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <span @class(['inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-xs font-semibold ring-1', $overallTone])>
                    @switch($report['overall'])
                        @case('critical')
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @case('warning')
                            <x-heroicon-m-exclamation-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @default
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    @endswitch
                    {{ $overallLabel }}
                </span>

                <span class="hidden whitespace-nowrap text-xs text-brand-mist sm:inline">{{ $scanLabel }}</span>

                @if ($opsReady && ! $isDeployer)
                    <button
                        type="button"
                        wire:click="refreshSecurityDigestScan"
                        wire:loading.attr="disabled"
                        wire:target="refreshSecurityDigestScan"
                        class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="refreshSecurityDigestScan" class="inline-flex">
                            <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </span>
                        <span wire:loading wire:target="refreshSecurityDigestScan" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                            <x-spinner variant="forest" size="sm" />
                        </span>
                        <span wire:loading.remove wire:target="refreshSecurityDigestScan">{{ __('Refresh digest') }}</span>
                        <span wire:loading wire:target="refreshSecurityDigestScan">{{ __('Scanning…') }}</span>
                    </button>
                @endif
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Security digest sections')" scroll bare class="!mb-0">
                <x-server-workspace-tab
                    id="digest-tab-overview"
                    icon="heroicon-o-shield-exclamation"
                    :active="$digest_tab === 'overview'"
                    wire:click="setDigestTab('overview')"
                >
                    {{ __('Overview') }}
                    @if (($report['alert_count'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-amber-900">{{ number_format($report['alert_count']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="digest-tab-auth"
                    icon="heroicon-o-document-text"
                    :active="$digest_tab === 'auth'"
                    wire:click="setDigestTab('auth')"
                >
                    {{ __('Auth & fail2ban') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="digest-tab-hardening"
                    icon="heroicon-o-lock-closed"
                    :active="$digest_tab === 'hardening'"
                    wire:click="setDigestTab('hardening')"
                >
                    {{ __('Hardening') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="digest-tab-notifications"
                    icon="heroicon-o-bell"
                    :active="$digest_tab === 'notifications'"
                    wire:click="setDigestTab('notifications')"
                >
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        <div wire:loading.block wire:target="setDigestTab" class="px-4 py-3 sm:px-5" aria-busy="true">
            <span class="sr-only">{{ __('Loading…') }}</span>
            <div class="space-y-2.5" aria-hidden="true">
                <div class="flex items-center gap-2">
                    <span class="h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-3 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
                </div>
                @foreach (range(1, 3) as $row)
                    <div class="flex items-center gap-2 border-t border-brand-ink/10 pt-2.5">
                        <span class="h-4 w-12 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                        <span class="h-3 w-2/3 max-w-sm animate-pulse rounded bg-brand-ink/10"></span>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:loading.remove wire:target="setDigestTab">
            @if ($digest_tab === 'overview')
                <div>
                    @if ($isDeployer)
                        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-brand-ink/10 bg-amber-50/60 px-4 py-2 text-xs text-amber-900 sm:px-5">
                            <x-heroicon-m-eye class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Read-only — deployers can review the digest but cannot run SSH scans.') }}
                        </p>
                    @endif

                    @if (! $opsReady)
                        <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
                            @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
                        </div>
                    @endif

                    @if (($report['alert_count'] ?? 0) > 0)
                        <ul class="divide-y divide-brand-ink/10 border-b border-brand-ink/10">
                            @foreach ($report['alerts'] as $alert)
                                @php
                                    $alertIconTone = match ($alert['severity']) {
                                        'critical' => 'text-rose-600',
                                        'warning' => 'text-amber-600',
                                        default => 'text-sky-600',
                                    };
                                @endphp
                                <li class="flex flex-wrap items-start gap-x-2 gap-y-1 px-4 py-2 sm:px-5">
                                    <x-heroicon-m-exclamation-triangle class="mt-px h-3.5 w-3.5 shrink-0 {{ $alertIconTone }}" aria-hidden="true" />
                                    <p class="min-w-0 flex-1 text-xs leading-relaxed text-brand-moss">
                                        <span class="text-xs font-semibold text-brand-ink">{{ $alert['title'] }}</span>
                                        <span class="text-brand-mist" aria-hidden="true">·</span>
                                        {{ $alert['message'] }}
                                    </p>
                                    @if ($alert['href'] && $alert['link_label'])
                                        <a href="{{ $alert['href'] }}" wire:navigate class="ml-auto inline-flex shrink-0 items-center gap-0.5 whitespace-nowrap text-xs font-semibold text-brand-forest hover:underline">
                                            {{ $alert['link_label'] }}
                                            <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Six figures in one hairline strip, replacing four `text-2xl`
                         tiles that hid the jail and ban counts in a caption. --}}
                    <x-workspace-stat-strip
                        :columns="3"
                        :stats="[
                            [
                                'label' => __('Distinct IPs (24h)'),
                                'value' => $auth['failed_24h_ips'] ?? '—',
                                'tone' => $severityTone,
                                'hint' => __('Unique source IPs with a failed SSH auth in the last 24 hours'),
                            ],
                            [
                                'label' => __('Lifetime auth.log'),
                                'value' => $auth['failed_lines'] ?? '—',
                                'hint' => __('All Failed password + Invalid user lines still in auth.log (scanners accumulate)'),
                            ],
                            [
                                'label' => __('fail2ban'),
                                'value' => $fail2ban['active'] ?? '—',
                                'tone' => $statusTone($fail2ban['active'] ?? null, ['active', 'running', 'yes'], ['inactive', 'missing', 'no']),
                                'hint' => __('fail2ban service state during the scan'),
                            ],
                            [
                                'label' => __('Jails'),
                                'value' => number_format($summary['jail_count'] ?? 0),
                                'hint' => __('Jails reported by fail2ban-client'),
                            ],
                            [
                                'label' => __('Banned now'),
                                'value' => number_format($summary['banned_now'] ?? 0),
                                'tone' => ($summary['banned_now'] ?? 0) > 0 ? 'warn' : null,
                                'hint' => __('IPs currently banned across all jails'),
                            ],
                            [
                                'label' => __('UFW firewall'),
                                'value' => $firewall['ufw_active'] ?? '—',
                                'tone' => $statusTone($firewall['ufw_active'] ?? null, ['active', 'yes'], ['inactive', 'missing', 'no']),
                                'hint' => __('Host packet filter status'),
                            ],
                        ]"
                    />
                </div>
            @endif

            @if ($digest_tab === 'auth')
                <div>
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-document-text"
                        :title="__('Brute-force indicators')"
                        :note="__('Alert on distinct IPs in 24h — warning ≥ :warning · critical ≥ :critical', [
                            'warning' => $summary['warning_threshold'] ?? 15,
                            'critical' => $summary['critical_threshold'] ?? 40,
                        ])"
                        class="border-b border-brand-ink/10"
                    >
                        <x-slot:actions>
                            <a
                                href="{{ route('servers.logs', $server) }}"
                                wire:navigate
                                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                {{ __('System logs') }}
                                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                            </a>
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    <x-workspace-stat-strip
                        class="border-b border-brand-ink/10"
                        :columns="3"
                        :stats="[
                            [
                                'label' => __('Distinct IPs (24h)'),
                                'value' => $auth['failed_24h_ips'] ?? '—',
                            ],
                            [
                                'label' => __('Matching lines (24h)'),
                                'value' => $auth['failed_24h_lines'] ?? '—',
                            ],
                            [
                                'label' => __('Volume severity'),
                                'value' => $auth['severity'] ?? '—',
                                'tone' => $severityTone,
                            ],
                        ]"
                    />

                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-shield-check"
                        :title="__('fail2ban jails')"
                        :count="count($fail2ban['jail_rows'] ?? []) > 0
                            ? trans_choice('{1} :count jail|[2,*] :count jails', count($fail2ban['jail_rows']), ['count' => count($fail2ban['jail_rows'])])
                            : null"
                        :note="__('Per-jail stats from fail2ban-client status during scan.')"
                        class="border-b border-brand-ink/10"
                    >
                        <x-slot:actions>
                            <a
                                href="{{ route('servers.firewall', $server) }}"
                                wire:navigate
                                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                {{ __('Firewall') }}
                            </a>
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    @if (count($fail2ban['jail_rows'] ?? []) === 0)
                        <div class="px-4 py-6 text-center sm:px-5">
                            <p class="text-xs font-semibold text-brand-ink">
                                @if ($scan['never_scanned'] ?? true)
                                    {{ __('Run a digest scan to populate jail stats') }}
                                @elseif (($fail2ban['active'] ?? '') === 'missing')
                                    {{ __('fail2ban is not installed on this host') }}
                                @else
                                    {{ __('No jail detail captured yet') }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Refresh digest when SSH is ready — sshd jail stats appear here automatically.') }}</p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-xs">
                                <thead class="bg-brand-sand/30 text-brand-moss">
                                    <tr>
                                        <th class="px-3 py-1.5 font-semibold sm:px-5">{{ __('Jail') }}</th>
                                        <th class="px-3 py-1.5 font-semibold text-right">{{ __('Banned now') }}</th>
                                        <th class="px-3 py-1.5 font-semibold text-right">{{ __('Total banned') }}</th>
                                        <th class="px-3 py-1.5 font-semibold text-right">{{ __('Failed now') }}</th>
                                        <th class="px-3 py-1.5 font-semibold text-right">{{ __('Total failed') }}</th>
                                        <th class="px-3 py-1.5 font-semibold sm:pr-5">{{ __('Banned IPs') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5 bg-white">
                                    @foreach ($fail2ban['jail_rows'] as $jail)
                                        <tr @class(['bg-amber-50/30' => ($jail['currently_banned'] ?? 0) >= 1])>
                                            <td class="px-3 py-1.5 font-medium text-brand-ink sm:px-5">{{ $jail['name'] }}</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums text-brand-ink">{{ $jail['currently_banned'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums text-brand-moss">{{ $jail['total_banned'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums text-brand-moss">{{ $jail['currently_failed'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 text-right font-mono tabular-nums text-brand-moss">{{ $jail['total_failed'] ?? '—' }}</td>
                                            <td class="max-w-[14rem] px-3 py-1.5 font-mono text-2xs text-brand-moss sm:pr-5">
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
                </div>
            @endif

            @if ($digest_tab === 'hardening')
                <div>
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-lock-closed"
                        :title="__('Effective sshd settings')"
                        :note="__('Sampled with sshd -T on the host during scan.')"
                        class="border-b border-brand-ink/10"
                    >
                        <x-slot:actions>
                            <a
                                href="{{ route('servers.ssh-keys', $server) }}"
                                wire:navigate
                                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                {{ __('SSH keys') }}
                                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                            </a>
                            @if ($sshAccessEnabled)
                                <a
                                    href="{{ route('servers.ssh-access', $server) }}"
                                    wire:navigate
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    {{ __('Access graph') }}
                                    <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                                </a>
                            @endif
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    {{-- The two sshd values carry their own verdict through the
                         strip's tone, so they no longer need pill badges in a
                         padded definition list. --}}
                    <x-workspace-stat-strip
                        :columns="2"
                        :stats="[
                            [
                                'label' => __('PasswordAuthentication'),
                                'value' => $formatBoolish($sshd['password_authentication'] ?? null),
                                'tone' => $statusTone($sshd['password_authentication'] ?? null, ['no', 'false', '0'], ['yes', 'true', '1']),
                                'hint' => __('Password logins should be off — keys only.'),
                            ],
                            [
                                'label' => __('PermitRootLogin'),
                                'value' => $sshd['permit_root_login'] ?? '—',
                                'tone' => $statusTone($sshd['permit_root_login'] ?? null, ['no', 'false', '0', 'prohibit-password', 'without-password', 'forced-commands-only'], ['yes', 'true', '1']),
                                'hint' => __('prohibit-password or no is the hardened setting.'),
                            ],
                        ]"
                    />

                    @if ($sshAccessEnabled && $sshAccess)
                        <x-workspace-panel-head
                            dense
                            icon="heroicon-o-key"
                            :title="__('Access graph rollup')"
                            :count="trans_choice('{1} :count key|[2,*] :count keys', $sshAccess['total_keys'], ['count' => $sshAccess['total_keys']])"
                            :note="collect([
                                $sshAccess['review_overdue'] > 0
                                    ? trans_choice('{1} :count overdue review|[2,*] :count overdue reviews', $sshAccess['review_overdue'], ['count' => $sshAccess['review_overdue']])
                                    : ($sshAccess['never_synced'] > 0
                                        ? trans_choice('{1} :count key never synced|[2,*] :count keys never synced', $sshAccess['never_synced'], ['count' => $sshAccess['never_synced']])
                                        : __('Key sync and review posture from the access graph')),
                                $sshAccess['active_sessions'] > 0
                                    ? trans_choice('{1} :count active session|[2,*] :count active sessions', $sshAccess['active_sessions'], ['count' => $sshAccess['active_sessions']])
                                    : null,
                            ])->filter()->join(' · ')"
                            class="border-t border-brand-ink/10"
                        >
                            <x-slot:actions>
                                <a
                                    href="{{ route('servers.ssh-access', $server) }}"
                                    wire:navigate
                                    class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    {{ __('Open access graph') }}
                                </a>
                            </x-slot:actions>
                        </x-workspace-panel-head>
                    @endif
                </div>
            @endif

            @if ($digest_tab === 'notifications')
                @include('livewire.servers.partials.security-digest.notifications-tab')
            @endif
        </div>
    </section>

    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
