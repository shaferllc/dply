<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppLogRecord;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * The dply Logs drain receiver (Phase 5). A TCP line server — TLS-terminated by
 * default — for sites whose logging config has a dply Logs channel (a Monolog
 * SocketHandler + LineFormatter that writes one newline-framed line per record:
 * `<token> <LEVEL> <message> <context>`). It accepts connections, reads complete
 * lines, maps the `dly_` token back to a site, and stores each in `app_logs` for
 * the App logs panel.
 *
 *   php artisan dply:log-drain:listen [--port=] [--host=0.0.0.0]
 *
 * Run as a supervised long-lived process on whichever box owns
 * DPLY_LOG_DRAIN_HOST (see docs/LOG_DRAIN_RECEIVER.md). TLS uses the cert/key in
 * config('log_drains.dply_realtime.tls_*'); set tls=false only for trusted-network
 * dev (sites then connect with plain tcp://).
 */
class LogDrainListen extends Command
{
    protected $signature = 'dply:log-drain:listen {--host=0.0.0.0} {--port=} {--once : Process a single line then exit (for diagnostics)}';

    protected $description = 'Receive dply Logs app-log drains over TLS/TCP and store them per-site.';

    /** Valid PSR/Monolog level names (lowercased); anything else falls back to info. */
    private const LEVELS = [
        'emergency', 'alert', 'critical', 'error',
        'warning', 'notice', 'info', 'debug',
    ];

    /** Drop a connection's buffer if it grows this large without a line break. */
    private const MAX_LINE_BYTES = 262144;

    /** @var array<string, string|null> token → site_id (null means unknown) */
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

        $tls = (bool) config('log_drains.dply_realtime.tls', true);
        $context = stream_context_create();
        if ($tls) {
            $cert = (string) config('log_drains.dply_realtime.tls_cert', '');
            $key = (string) config('log_drains.dply_realtime.tls_key', '');
            if ($cert === '' || ! is_file($cert)) {
                $this->error('TLS enabled but DPLY_LOG_DRAIN_TLS_CERT is missing or unreadable: '.$cert);

                return self::FAILURE;
            }
            $ssl = ['local_cert' => $cert, 'verify_peer' => false, 'allow_self_signed' => true];
            if ($key !== '' && is_file($key)) {
                $ssl['local_pk'] = $key;
            }
            $passphrase = (string) config('log_drains.dply_realtime.tls_passphrase', '');
            if ($passphrase !== '') {
                $ssl['passphrase'] = $passphrase;
            }
            stream_context_set_option($context, ['ssl' => $ssl]);
        }

        $transport = $tls ? 'tls' : 'tcp';
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(
            $transport.'://'.$host.':'.$port,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        if ($server === false) {
            $this->error(sprintf('Could not bind %s://%s:%d: %s', $transport, $host, $port, $errstr));

            return self::FAILURE;
        }

        $this->rateMax = max(0, (int) config('log_drains.rate_limit.max_per_window', 0));
        $this->rateWindow = max(1, (int) config('log_drains.rate_limit.window_seconds', 60));

        $this->info(sprintf('dply log drain listening on %s://%s:%d', $transport, $host, $port));

        /** @var array<int, array{sock: resource, buf: string}> */
        $clients = [];

        while (true) {
            $read = [$server];
            foreach ($clients as $client) {
                $read[] = $client['sock'];
            }
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, 1);
            if ($ready === false) {
                continue; // interrupted (e.g. signal); loop again
            }
            if ($ready === 0) {
                continue; // idle tick
            }

            foreach ($read as $sock) {
                if ($sock === $server) {
                    // accept() performs the TLS handshake when tls is on.
                    $conn = @stream_socket_accept($server, 5);
                    if ($conn !== false) {
                        stream_set_blocking($conn, false);
                        $clients[(int) $conn] = ['sock' => $conn, 'buf' => ''];
                    }

                    continue;
                }

                $id = (int) $sock;
                $chunk = @fread($sock, 65535);
                if ($chunk === '' || $chunk === false) {
                    @fclose($sock); // EOF / error → drop the connection
                    unset($clients[$id]);

                    continue;
                }

                $buf = ($clients[$id]['buf'] ?? '').$chunk;
                while (($pos = strpos($buf, "\n")) !== false) {
                    $line = rtrim(substr($buf, 0, $pos), "\r");
                    $buf = substr($buf, $pos + 1);
                    if ($line !== '') {
                        try {
                            $this->ingestLine($line);
                        } catch (\Throwable $e) {
                            $this->warn('drop: '.$e->getMessage()); // never fatal
                        }
                    }
                    if ($this->option('once')) {
                        foreach ($clients as $c) {
                            @fclose($c['sock']);
                        }
                        @fclose($server);

                        return self::SUCCESS;
                    }
                }
                // Guard against an endless line (malformed / abusive sender).
                $clients[$id]['buf'] = strlen($buf) > self::MAX_LINE_BYTES ? '' : $buf;
            }
        }
    }

    /**
     * Parse and store one wire line: `<dly_token> <LEVEL> <message> <context>`.
     */
    private function ingestLine(string $line): void
    {
        $token = $this->extractToken($line);
        if ($token === null) {
            return; // not a dply-tokened record
        }

        $siteId = $this->siteIdForToken($token);
        if ($siteId === null) {
            return; // unknown token
        }

        if (! $this->withinRateLimit($siteId)) {
            return; // site over its per-window cap; drop the excess
        }

        AppLogRecord::create([
            'site_id' => $siteId,
            'channel' => 'dply_realtime',
            'level' => $this->levelAfterToken($line, $token),
            'message' => $this->messageAfterToken($line, $token),
            'context' => null,
            'logged_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function extractToken(string $line): ?string
    {
        return preg_match('/\b(dly_[a-z0-9]+)\b/', $line, $m) ? $m[1] : null;
    }

    /** The level word immediately after the token; defaults to info. */
    private function levelAfterToken(string $line, string $token): string
    {
        $after = ltrim((string) substr($line, (int) strpos($line, $token) + strlen($token)));
        $word = strtolower((string) strtok($after, " \t"));

        return in_array($word, self::LEVELS, true) ? $word : 'info';
    }

    /** Everything after the token + level word, truncated. */
    private function messageAfterToken(string $line, string $token): string
    {
        $after = ltrim((string) substr($line, (int) strpos($line, $token) + strlen($token)));
        // Strip the leading level word.
        $after = (string) preg_replace('/^\S+\s*/', '', $after);

        return mb_substr(trim($after), 0, 8000);
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
}
