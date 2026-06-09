<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppLogRecord;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * The dply Realtime drain receiver (Phase 5). Listens for syslog datagrams sent
 * by sites whose logging config has a dply Realtime channel (a Monolog
 * SyslogUdpHandler stamped with the site's routing token as its ident), parses
 * each, maps it back to a site, and stores it in `app_logs` for the App logs
 * panel.
 *
 *   php artisan dply:log-drain:listen [--port=] [--host=0.0.0.0]
 *
 * Run as a supervised long-lived process on whichever box owns
 * DPLY_LOG_DRAIN_HOST (see docs/LOG_DRAIN_RECEIVER.md). This is a UDP listener,
 * so it must be deployed/supervised — it is NOT exercised by the test suite.
 *
 * Routing is by token, not by exact syslog framing: every dply token carries the
 * `dly_` prefix, so we can pluck it out of the datagram regardless of RFC3164 vs
 * RFC5424 differences. Severity comes from the `<PRI>` value; the message is the
 * best-effort tail after the syslog header.
 */
class LogDrainListen extends Command
{
    protected $signature = 'dply:log-drain:listen {--host=0.0.0.0} {--port=} {--once : Process a single datagram then exit (for diagnostics)}';

    protected $description = 'Receive dply Realtime app-log drains over UDP and store them per-site.';

    /** PRI severity (value & 7) → PSR/Monolog level name. */
    private const SEVERITY = [
        0 => 'emergency', 1 => 'alert', 2 => 'critical', 3 => 'error',
        4 => 'warning', 5 => 'notice', 6 => 'info', 7 => 'debug',
    ];

    /** @var array<string, string|null> token → site_id (false-y means unknown) */
    private array $tokenCache = [];

    /** @var array<string, array{count: int, window: int}> per-site ingest rate state */
    private array $rateState = [];

    private int $rateMax = 0;

    private int $rateWindow = 60;

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) ($this->option('port') ?: (int) config('log_drains.dply_realtime.port', 0));
        if ($port <= 0) {
            $this->error('No listen port. Pass --port or set DPLY_LOG_DRAIN_PORT.');

            return self::FAILURE;
        }

        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            $this->error('Could not create UDP socket: '.socket_strerror(socket_last_error()));

            return self::FAILURE;
        }
        if (! @socket_bind($sock, $host, $port)) {
            $this->error(sprintf('Could not bind %s:%d: %s', $host, $port, socket_strerror(socket_last_error($sock))));
            socket_close($sock);

            return self::FAILURE;
        }

        $this->rateMax = max(0, (int) config('log_drains.rate_limit.max_per_window', 0));
        $this->rateWindow = max(1, (int) config('log_drains.rate_limit.window_seconds', 60));

        $this->info(sprintf('dply log drain listening on udp://%s:%d', $host, $port));

        while (true) {
            $buf = '';
            $from = '';
            $fromPort = 0;
            $n = @socket_recvfrom($sock, $buf, 65535, 0, $from, $fromPort);
            if ($n === false || $buf === '') {
                if ($this->option('once')) {
                    break;
                }

                continue;
            }

            try {
                $this->ingest($buf);
            } catch (\Throwable $e) {
                // Never let one bad datagram kill the listener.
                $this->warn('drop: '.$e->getMessage());
            }

            if ($this->option('once')) {
                break;
            }
        }

        socket_close($sock);

        return self::SUCCESS;
    }

    private function ingest(string $datagram): void
    {
        $token = $this->extractToken($datagram);
        if ($token === null) {
            return; // not a dply-tokened record; ignore
        }

        $siteId = $this->siteIdForToken($token);
        if ($siteId === null) {
            return; // unknown token
        }

        if (! $this->withinRateLimit($siteId)) {
            return; // site is over its per-window cap; drop the excess
        }

        AppLogRecord::create([
            'site_id' => $siteId,
            'channel' => 'dply_realtime',
            'level' => $this->severity($datagram),
            'message' => $this->message($datagram, $token),
            'context' => null,
            'logged_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Per-site fixed-window cap so one chatty/abusive app can't flood app_logs.
     * In-process state is fine: a single supervised listener owns the socket.
     * max_per_window <= 0 disables the limit.
     */
    private function withinRateLimit(string $siteId): bool
    {
        if ($this->rateMax <= 0) {
            return true;
        }

        $now = time();
        $state = $this->rateState[$siteId] ?? ['count' => 0, 'window' => $now];
        if ($now - $state['window'] >= $this->rateWindow) {
            $state = ['count' => 0, 'window' => $now];
        }
        $state['count']++;
        $this->rateState[$siteId] = $state;

        return $state['count'] <= $this->rateMax;
    }

    private function extractToken(string $datagram): ?string
    {
        return preg_match('/\b(dly_[a-z0-9]+)\b/', $datagram, $m) ? $m[1] : null;
    }

    private function siteIdForToken(string $token): ?string
    {
        if (array_key_exists($token, $this->tokenCache)) {
            return $this->tokenCache[$token];
        }
        $id = Site::query()->where('log_drain_token', $token)->value('id');
        $this->tokenCache[$token] = $id ? (string) $id : null;

        return $this->tokenCache[$token];
    }

    private function severity(string $datagram): string
    {
        if (preg_match('/^<(\d+)>/', $datagram, $m)) {
            return self::SEVERITY[((int) $m[1]) & 7] ?? 'info';
        }

        return 'info';
    }

    /**
     * Best-effort message extraction: drop the leading `<PRI>` and everything up
     * to and including the routing token (the syslog header), leaving the human
     * message. Falls back to the de-PRI'd datagram if the token isn't mid-line.
     */
    private function message(string $datagram, string $token): string
    {
        $body = (string) preg_replace('/^<\d+>\S*\s*/', '', $datagram);
        $pos = strpos($body, $token);
        if ($pos !== false) {
            $after = ltrim(substr($body, $pos + strlen($token)));
            // Skip a leading PROCID/MSGID/SD run ("- - " or "[...]") if present.
            $after = (string) preg_replace('/^(?:-|\[[^\]]*\]|\d+)(?:\s+(?:-|\[[^\]]*\]))*\s+/', '', $after);
            if ($after !== '') {
                return mb_substr(trim($after), 0, 8000);
            }
        }

        return mb_substr(trim($body), 0, 8000);
    }
}
