<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Services\SshConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * Per-server SSH login scan. SSHes in as root, parses `last -F -i -n 50`, fires
 * `server.ssh_login` notifications for any login seen since the last scan.
 *
 * Dispatched from the scheduler every 5 minutes for servers that have at least
 * one active `server.ssh_login` NotificationSubscription. Servers without a
 * subscriber are skipped entirely — no point paying the SSH cost when nothing
 * is listening. Latency: up to one scheduler tick (~5 min); acceptable for an
 * audit trail event.
 *
 * Dedup strategy: `meta.ssh_login_last_seen_at` is the unix timestamp of the
 * most recent login emitted. Each scan filters to entries strictly newer than
 * that and updates the cursor on success. First-ever scan emits nothing — the
 * `last` history pre-dates dply's interest in it — and sets the cursor to the
 * newest entry so the next scan picks up only fresh activity.
 */
class ScanServerSshLoginsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public string $serverId) {}

    public function handle(NotificationPublisher $publisher): void
    {
        $server = Server::find($this->serverId);
        if ($server === null || ! $server->isReady() || empty($server->ip_address) || ! $server->hasAnySshPrivateKey()) {
            return;
        }

        // Belt-and-braces: scheduler enumerates eligible servers but a row
        // could lose its subscription between enumeration and job pickup.
        if (! $this->serverHasSshLoginSubscription($server)) {
            return;
        }

        $output = $this->fetchLastOutput($server);
        if ($output === null) {
            return;
        }

        $entries = $this->parseLastOutput($output);
        if ($entries === []) {
            return;
        }

        $meta = $server->meta ?? [];
        $lastSeenAt = isset($meta['ssh_login_last_seen_at']) && is_numeric($meta['ssh_login_last_seen_at'])
            ? (int) $meta['ssh_login_last_seen_at']
            : null;

        // First scan: just record the cursor, don't backfill notifications for
        // history. Otherwise operators get flooded with every login from the
        // last apt cycle the moment they subscribe.
        if ($lastSeenAt === null) {
            $newestTs = max(array_map(static fn (array $e) => $e['ts'], $entries));
            $meta['ssh_login_last_seen_at'] = $newestTs;
            $server->update(['meta' => $meta]);

            return;
        }

        $newEntries = array_values(array_filter($entries, static fn (array $e) => $e['ts'] > $lastSeenAt));
        if ($newEntries === []) {
            return;
        }

        // Emit oldest-first so the notification ordering matches the timeline.
        usort($newEntries, static fn (array $a, array $b) => $a['ts'] <=> $b['ts']);

        foreach ($newEntries as $entry) {
            $this->publishLoginEvent($publisher, $server, $entry);
        }

        $meta['ssh_login_last_seen_at'] = end($newEntries)['ts'];
        $server->update(['meta' => $meta]);
    }

    /**
     * Run `last -F -i -n 50` as root over SSH. Falls back to the deploy user
     * if root login isn't allowed. Returns null on any failure — we never want
     * to half-emit notifications on a flaky scan; better to skip this tick.
     */
    protected function fetchLastOutput(Server $server): ?string
    {
        // -F prints full login + logout dates (year included so we don't have
        // to guess), -i resolves the source IP, -n caps the rows. Most modern
        // distros ship util-linux's `last` which supports all three flags.
        $cmd = '/bin/sh -c '.escapeshellarg('last -F -i -n 50 2>/dev/null || true');
        $deploy = trim((string) $server->ssh_user) ?: 'root';

        $candidates = ['root'];
        if ($deploy !== 'root') {
            $candidates[] = $deploy;
        }

        foreach ($candidates as $loginUser) {
            try {
                $ssh = new SshConnection($server, $loginUser);
                $out = trim($ssh->execWithCallback($cmd, fn (string $chunk) => null, 30));
                $ssh->disconnect();
                if ($out !== '') {
                    return $out;
                }
            } catch (\Throwable) {
                // Try the next candidate; quiet failure mirrors the inventory probe.
            }
        }

        return null;
    }

    /**
     * Parse `last -F -i` output into [{user, ip, ts, raw}, ...] entries.
     *
     * Sample line shapes (util-linux):
     *   tom    pts/0  192.168.1.10  Mon May 18 09:13:24 2026 - still logged in
     *   tom    pts/1  198.51.100.5  Mon May 18 08:00:00 2026 - Mon May 18 09:00:00 2026  (01:00)
     *   reboot system boot 6.5.0-1...  Mon May 18 07:00:00 2026 - still running
     *
     * We skip pseudo-users (reboot, shutdown, runlevel, wtmp), unsourced lines,
     * and the "wtmp begins" footer.
     *
     * @return list<array{user: string, ip: string, ts: int, raw: string}>
     */
    public function parseLastOutput(string $output): array
    {
        $entries = [];
        $pseudoUsers = ['reboot', 'shutdown', 'runlevel', 'wtmp'];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with($line, 'wtmp begins')) {
                continue;
            }

            // Tokens: user tty ip <Day Mon DD HH:MM:SS YYYY> - <…>
            // Match: user (\S+), tty (\S+), ip (\S+), then the start date.
            if (! preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+([A-Z][a-z]{2}\s+[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4})/u', $line, $m)) {
                continue;
            }

            $user = $m[1];
            $ip = $m[3];
            $dateStr = $m[4];

            if (in_array($user, $pseudoUsers, true)) {
                continue;
            }

            // No source address = local console or unresolved; skip — operators
            // care about who logged in from where, not local-console boots.
            if ($ip === '-' || ! preg_match('/^[0-9a-f:.]+$/i', $ip)) {
                continue;
            }

            $ts = strtotime($dateStr);
            if ($ts === false || $ts <= 0) {
                continue;
            }

            $entries[] = [
                'user' => $user,
                'ip' => $ip,
                'ts' => $ts,
                'raw' => $line,
            ];
        }

        return $entries;
    }

    /**
     * @param  array{user: string, ip: string, ts: int, raw: string}  $entry
     */
    protected function publishLoginEvent(NotificationPublisher $publisher, Server $server, array $entry): void
    {
        $when = Carbon::createFromTimestamp($entry['ts']);

        try {
            $publisher->publish(
                eventKey: 'server.ssh_login',
                subject: $server,
                title: __('SSH login on :host: :user from :ip', [
                    'host' => $server->name,
                    'user' => $entry['user'],
                    'ip' => $entry['ip'],
                ]),
                body: __(':user logged in at :time (UTC) from :ip.', [
                    'user' => $entry['user'],
                    'time' => $when->utc()->toDateTimeString(),
                    'ip' => $entry['ip'],
                ]),
                url: route('servers.manage', ['server' => $server, 'section' => 'overview'], false),
                metadata: [
                    'user' => $entry['user'],
                    'ip' => $entry['ip'],
                    'occurred_at' => $when->toIso8601String(),
                    'raw' => $entry['raw'],
                ],
            );

            $server->loadMissing('organization');
            if ($server->organization) {
                audit_log($server->organization, null, 'server.ssh_login_detected', $server, null, [
                    'remote_user' => $entry['user'],
                    'remote_ip' => $entry['ip'],
                    'occurred_at' => $when->toIso8601String(),
                ]);
            }
        } catch (\Throwable) {
            // Swallow per-event failures — a bad row shouldn't poison the
            // remaining events in the same scan.
        }
    }

    protected function serverHasSshLoginSubscription(Server $server): bool
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $server->id)
            ->where('event_key', 'server.ssh_login')
            ->exists();
    }

    /**
     * Servers eligible for an SSH login scan: ready, SSH'able, and at least
     * one active `server.ssh_login` subscription. Called from the scheduler;
     * exposed as a static for unit testing.
     *
     * @return LazyCollection<int, Server>
     */
    public static function eligibleServers(): LazyCollection
    {
        $subscribedIds = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('event_key', 'server.ssh_login')
            ->pluck('subscribable_id')
            ->unique()
            ->values()
            ->all();

        if ($subscribedIds === []) {
            return LazyCollection::empty();
        }

        return Server::query()
            ->whereIn('id', $subscribedIds)
            ->whereNotNull('ip_address')
            ->cursor();
    }
}
