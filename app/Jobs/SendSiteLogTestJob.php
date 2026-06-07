<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AppLogRecord;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Logging\LoggingChannelCatalog;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emits one real log record through a single channel of the site's deployed
 * config/logging.php, to confirm the pipe works (Phase 4, Q11). It runs on the
 * SITE's server because that's where logging runs at runtime (the drain host's
 * IP, the app's vendor/, the live .env all matter), booting the app so the test
 * exercises exactly the config dply generated on the last deploy.
 *
 * "Success" is honest about scope — most destinations have no read-back:
 *   - file channels  → we grep the log file for the token: genuine confirmation.
 *   - dply Realtime  → sent to dply's endpoint (surfaced in App logs once Phase 5
 *                      lands); the emit itself not throwing is the signal.
 *   - third-party    → "sent — check your provider dashboard" (no arrival proof).
 *   - stderr/custom  → "sent" (the channel constructed and accepted the record).
 * A channel that can't even be built (missing handler class) fails loudly here.
 */
class SendSiteLogTestJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $bindingId,
        public string $channelName,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(SshConnectionFactory $factory): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        $binding = SiteBinding::query()->find($this->bindingId);
        $action = ConsoleAction::query()->find($this->consoleActionId);

        if ($site === null || $binding === null || $action === null || $site->server === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);
        $conn = null;

        try {
            $channel = trim($this->channelName);
            $type = $this->channelType($binding, $channel);
            if ($channel === '') {
                $emit->warn('No channel selected to test.', 'test');
                $this->finish($emit, false, 'No channel.', failed: true);

                return;
            }

            $base = rtrim($site->effectiveEnvDirectory(), '/');
            $autoload = $base.'/vendor/autoload.php';
            $token = 'dplylogtest_'.Str::lower(Str::random(16));
            $scriptPath = '/tmp/dply-logtest-'.$this->consoleActionId.'.php';

            $emit->step('test', sprintf('From %s — emitting a test record through the “%s” channel …', (string) $site->server->name, $channel));

            $conn = $factory->forServer($site->server);
            if (! $conn->connect(10)) {
                throw new \RuntimeException('Could not open SSH to '.$site->server->name.'.');
            }

            $check = $conn->exec('test -f '.escapeshellarg($autoload).' && echo DPLY_VENDOR_OK || echo DPLY_VENDOR_MISSING', 15);
            if (! str_contains($check, 'DPLY_VENDOR_OK')) {
                $emit->warn('No vendor/ found for this site yet — deploy the app first, then test a channel.', 'test');
                $this->finish($emit, false, 'App not deployed (vendor/ missing).', failed: true);

                return;
            }

            $write = 'cat > '.escapeshellarg($scriptPath)." <<'DPLYPHP'\n".$this->runnerScript()."\nDPLYPHP\n";
            $conn->exec($write, 15);

            $cmd = implode(' ', [
                'DPLY_BASE='.escapeshellarg($base),
                'DPLY_CHANNEL='.escapeshellarg($channel),
                'DPLY_TOKEN='.escapeshellarg($token),
                'php', escapeshellarg($scriptPath), '2>&1',
            ]);
            $out = trim((string) $conn->exec($cmd, 60));
            $conn->exec('rm -f '.escapeshellarg($scriptPath), 10);

            if (! str_contains($out, 'DPLY_LOGTEST_OK')) {
                $detail = trim(str_replace(['DPLY_LOGTEST_FAIL', 'DPLY_LOGTEST_OK'], '', $out));
                $detail = $detail !== '' ? $detail : 'The channel produced no output.';
                $emit->error('Could not emit through “'.$channel.'”: '.mb_substr($detail, 0, 1000), 'test');
                if (isset(LoggingChannelCatalog::TRANSPORT_PACKAGES[$type])) {
                    $emit->step('test', sprintf('This channel needs the %s package in your app — add it and redeploy.', LoggingChannelCatalog::TRANSPORT_PACKAGES[$type]));
                }
                $this->finish($emit, false, mb_substr($detail, 0, 1000), failed: true);

                return;
            }

            // Emit succeeded — now report with honest, type-specific confirmation.
            $this->reportSuccess($emit, $conn, $site, $base, $type, $channel, $token);
            $this->finish($emit, true, null);
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $emit->error('Channel test failed: '.$message, 'test');
            $this->finish($emit, false, 'Test did not complete: '.$message, failed: true);
        } finally {
            try {
                $conn?->disconnect();
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    private function reportSuccess(ConsoleEmitter $emit, mixed $conn, Site $site, string $base, string $type, string $channel, string $token): void
    {
        if (in_array($type, [LoggingChannelCatalog::FILE_SINGLE, LoggingChannelCatalog::FILE_DAILY], true)) {
            // Genuine read-back: grep the log dir for the token we just wrote.
            $grep = trim((string) $conn->exec(
                sprintf('grep -lF %s %s 2>/dev/null | head -n1', escapeshellarg($token), escapeshellarg($base.'/storage/logs').'/*.log'),
                20,
            ));
            if ($grep !== '' && ! str_contains($grep, 'No such file')) {
                $emit->success('test', sprintf('Confirmed — the test record landed in %s.', $grep));

                return;
            }
            $emit->warn('The record was emitted, but it could not be found in storage/logs — check the path/permissions.', 'test');

            return;
        }

        if ($type === LoggingChannelCatalog::DPLY_REALTIME) {
            // True round-trip: poll app_logs for the token we just emitted. The
            // record only lands if the drain receiver is running and reachable.
            for ($i = 0; $i < 6; $i++) {
                $seen = AppLogRecord::query()
                    ->where('site_id', $site->id)
                    ->where('message', 'like', '%'.$token.'%')
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->exists();
                if ($seen) {
                    $emit->success('test', 'Confirmed — dply received the record and it is in App logs.');

                    return;
                }
                sleep(1);
            }
            $emit->warn('Sent to the dply Realtime endpoint, but it hasn’t appeared in App logs yet — confirm the drain receiver is running.', 'test');

            return;
        }

        if (LoggingChannelCatalog::isDrain($type)) {
            $emit->success('test', sprintf('Sent through “%s” — confirm it arrived in your provider’s dashboard (we can’t read it back).', $channel));

            return;
        }

        $emit->success('test', sprintf('Sent — the “%s” channel accepted the record.', $channel));
    }

    /**
     * The catalog type of the named channel, from the binding's stored spec.
     */
    private function channelType(SiteBinding $binding, string $channel): string
    {
        $config = is_array($binding->config) ? $binding->config : [];
        foreach ((array) ($config['channels'] ?? []) as $c) {
            if (is_array($c) && ($c['name'] ?? null) === $channel) {
                return (string) ($c['type'] ?? '');
            }
        }

        return '';
    }

    /**
     * Secret-free runner: boots the app at $base and emits one record through the
     * requested channel of the live (deployed) config/logging.php. The marker is
     * how the job knows it worked (SSH exec doesn't surface exit codes reliably).
     */
    private function runnerScript(): string
    {
        return <<<'PHP'
<?php
$base = getenv('DPLY_BASE');
$channel = getenv('DPLY_CHANNEL');
$token = getenv('DPLY_TOKEN');
if (! $base || ! is_dir($base) || ! is_file($base.'/vendor/autoload.php')) {
    fwrite(STDERR, "site not deployed at {$base}");
    echo "DPLY_LOGTEST_FAIL\n";
    exit(1);
}
chdir($base);
require $base.'/vendor/autoload.php';
try {
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $app->make('log')->channel($channel)->info('dply log channel test ['.$token.'] — if you can read this, the "'.$channel.'" channel is delivering.');
    echo "DPLY_LOGTEST_OK\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    echo "DPLY_LOGTEST_FAIL\n";
    exit(1);
}
PHP;
    }

    private function finish(ConsoleEmitter $emit, bool $ok, ?string $error, bool $failed = false): void
    {
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => $failed ? ConsoleAction::STATUS_FAILED : ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => $failed ? $error : null,
            'updated_at' => now(),
        ]);
    }
}
