<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Sends a test email from the SITE's server using a mail binding's transport,
 * to confirm the deployed app can actually deliver. Mail has to be tested from
 * the box that will send at runtime (its IP/sending domain is what providers
 * gate on), and the transport packages already live in the app's vendor/ (the
 * deploy ran the app's own `composer install`), so this opens SSH and runs a
 * tiny standalone PHP script that:
 *
 *   - autoloads the app's vendor/ (so the installed Symfony transport bridges
 *     are available — a missing bridge package surfaces as the exact error the
 *     operator needs to see),
 *   - builds a Symfony Mailer DSN straight from the binding's injected MAIL_*
 *     values (a pure function of the credential fields — no app boot, no DB, no
 *     config cache, so the signal is "can this server, with these creds, reach
 *     the provider" and nothing else),
 *   - sends one message to the operator-chosen recipient.
 *
 * Secrets are passed to the remote PHP via inline environment variables (only
 * readable by the process owner), never argv (world-visible in `ps`). The
 * result streams into the page-top console banner.
 */
class SendBindingTestEmailJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $bindingId,
        public string $recipient,
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
            $env = $binding->connectionEnv();
            $provider = strtolower(trim((string) (is_array($binding->config) ? ($binding->config['provider'] ?? '') : '')));

            if ($provider === 'log') {
                $emit->info('The "log" mailer writes to the application log and never delivers — there is nothing to test.');
                $this->finish($emit, true, null);

                return;
            }

            $legs = is_array($binding->config) && is_array($binding->config['legs'] ?? null)
                ? array_values($binding->config['legs'])
                : [];
            $dsn = $this->buildDsn($provider, $env, $legs);
            if ($dsn === null) {
                $emit->warn('This mail binding has no transport to test.', 'send');
                $this->finish($emit, true, null);

                return;
            }

            $from = trim((string) ($env['MAIL_FROM_ADDRESS'] ?? ''));
            $fromName = trim((string) ($env['MAIL_FROM_NAME'] ?? ''));
            if ($from === '') {
                $emit->warn('No MAIL_FROM_ADDRESS on this binding — set a "from" address and try again.', 'send');
                $this->finish($emit, false, 'Missing from-address.');

                return;
            }

            $autoload = rtrim($site->effectiveEnvDirectory(), '/').'/vendor/autoload.php';
            $scriptPath = '/tmp/dply-mailtest-'.$this->consoleActionId.'.php';

            $emit->step('send', sprintf('From %s — sending a test email via %s to %s …', (string) $site->server->name, $provider, $this->recipient));

            $conn = $factory->forServer($site->server);
            if (! $conn->connect(10)) {
                throw new \RuntimeException('Could not open SSH to '.$site->server->name.'.');
            }

            // Bail early with a clear message if the app isn't deployed yet
            // (vendor/ absent) — the transports live there.
            $check = $conn->exec('test -f '.escapeshellarg($autoload).' && echo DPLY_VENDOR_OK || echo DPLY_VENDOR_MISSING', 15);
            if (! str_contains($check, 'DPLY_VENDOR_OK')) {
                $emit->warn('No vendor/ found for this site yet — deploy the app first, then send a test email.', 'send');
                $this->finish($emit, false, 'App not deployed (vendor/ missing).');

                return;
            }

            // Write a secret-free runner: it reads the DSN + addresses from the
            // environment we pass on the exec line. Single-quoted heredoc so the
            // server shell doesn't interpolate anything in the script body.
            $script = $this->runnerScript();
            $write = 'cat > '.escapeshellarg($scriptPath)." <<'DPLYPHP'\n".$script."\nDPLYPHP\n";
            $conn->exec($write, 15);

            $cmd = implode(' ', [
                'DPLY_AUTOLOAD='.escapeshellarg($autoload),
                'DPLY_MAIL_DSN='.escapeshellarg($dsn),
                'DPLY_MAIL_FROM='.escapeshellarg($from),
                'DPLY_MAIL_FROMNAME='.escapeshellarg($fromName),
                'DPLY_MAIL_TO='.escapeshellarg($this->recipient),
                'php', escapeshellarg($scriptPath), '2>&1',
            ]);

            $out = trim((string) $conn->exec($cmd, 60));

            // Best-effort cleanup of the temp runner.
            $conn->exec('rm -f '.escapeshellarg($scriptPath), 10);

            if (str_contains($out, 'DPLY_MAIL_OK')) {
                $emit->success('send', sprintf('Sent — %s delivered a test email to %s via %s.', (string) $site->server->name, $this->recipient, $provider));
                $this->finish($emit, true, null);

                return;
            }

            // Surface the transport error verbatim (strip our marker line). This
            // is where a missing bridge package shows up.
            $detail = trim(str_replace(['DPLY_MAIL_FAIL', 'DPLY_MAIL_OK'], '', $out));
            $detail = $detail !== '' ? $detail : 'The transport reported no output.';
            $emit->error('Send failed: '.mb_substr($detail, 0, 1000), 'send');
            $emit->step('send', 'If this names a missing class/package, add the provider\'s transport package to your app\'s composer.json and redeploy.');
            $this->finish($emit, false, mb_substr($detail, 0, 1000), failed: true);
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 1000);
            $emit->error('Test email failed: '.$message, 'send');
            $this->finish($emit, false, 'Test email did not complete: '.$message, failed: true);
        } finally {
            try {
                $conn?->disconnect();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
    }

    /**
     * Build a Symfony Mailer DSN from the binding's injected MAIL_* values.
     * Returns null for providers/shapes with nothing to dial. For failover /
     * roundrobin, wraps each leg's DSN in Symfony's composite syntax
     * `failover(dsn1 dsn2)` / `roundrobin(dsn1 dsn2)`.
     *
     * @param  array<string, string>  $env
     * @param  list<string>  $legs
     */
    private function buildDsn(string $provider, array $env, array $legs = []): ?string
    {
        if (in_array($provider, ['failover', 'roundrobin'], true)) {
            // Build each leg's DSN from the merged env; `log` is a Laravel mailer
            // with no Symfony transport, so it's skipped in the live test.
            $legDsns = [];
            foreach ($legs as $leg) {
                $slug = strtolower(trim((string) $leg));
                if ($slug === 'log') {
                    continue;
                }
                $legDsn = $this->buildDsn($slug, $env);
                if ($legDsn !== null) {
                    $legDsns[] = $legDsn;
                }
            }
            $legDsns = array_values(array_unique($legDsns));

            return $legDsns === [] ? null : $provider.'('.implode(' ', $legDsns).')';
        }

        $enc = fn (string $v): string => rawurlencode($v);

        return match ($provider) {
            'smtp' => $this->smtpDsn($env, $enc),
            'mailgun' => ($env['MAILGUN_SECRET'] ?? '') !== '' && ($env['MAILGUN_DOMAIN'] ?? '') !== ''
                ? sprintf('mailgun+https://%s:%s@%s', $enc($env['MAILGUN_SECRET']), $enc($env['MAILGUN_DOMAIN']), ($env['MAILGUN_ENDPOINT'] ?? '') !== '' ? $env['MAILGUN_ENDPOINT'] : 'default')
                : null,
            'postmark' => ($env['POSTMARK_TOKEN'] ?? '') !== ''
                ? sprintf('postmark+api://%s@default', $enc($env['POSTMARK_TOKEN']))
                : null,
            'ses' => ($env['AWS_ACCESS_KEY_ID'] ?? '') !== '' && ($env['AWS_SECRET_ACCESS_KEY'] ?? '') !== ''
                ? sprintf('ses+api://%s:%s@default?region=%s', $enc($env['AWS_ACCESS_KEY_ID']), $enc($env['AWS_SECRET_ACCESS_KEY']), $enc($env['AWS_DEFAULT_REGION'] ?? ''))
                : null,
            'resend' => ($env['RESEND_KEY'] ?? '') !== ''
                ? sprintf('resend+api://%s@default', $enc($env['RESEND_KEY']))
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $env
     * @param  callable(string): string  $enc
     */
    private function smtpDsn(array $env, callable $enc): ?string
    {
        $host = trim((string) ($env['MAIL_HOST'] ?? ''));
        if ($host === '') {
            return null;
        }

        $port = trim((string) ($env['MAIL_PORT'] ?? ''));
        $user = (string) ($env['MAIL_USERNAME'] ?? '');
        $pass = (string) ($env['MAIL_PASSWORD'] ?? '');
        // SSL → smtps (implicit TLS, typically :465); tls/none → smtp with
        // STARTTLS auto-negotiated. Honor MAIL_SCHEME (L11) first, then fall
        // back to MAIL_ENCRYPTION (≤L10).
        $scheme = (strtolower((string) ($env['MAIL_SCHEME'] ?? '')) === 'smtps'
            || strtolower((string) ($env['MAIL_ENCRYPTION'] ?? '')) === 'ssl')
            ? 'smtps' : 'smtp';

        $auth = $user !== '' ? $enc($user).':'.$enc($pass).'@' : '';
        $hostPort = $host.($port !== '' ? ':'.$port : '');

        return $scheme.'://'.$auth.$hostPort;
    }

    /**
     * Secret-free PHP runner executed on the site's server. Reads everything
     * from the environment the exec line sets; emits a DPLY_MAIL_OK/FAIL marker
     * the job parses (SSH exec doesn't surface exit codes reliably).
     */
    private function runnerScript(): string
    {
        return <<<'PHP'
<?php
$autoload = getenv('DPLY_AUTOLOAD');
if (! $autoload || ! is_file($autoload)) {
    fwrite(STDERR, "vendor autoload not found at {$autoload}\n");
    echo "DPLY_MAIL_FAIL\n";
    exit(1);
}
require $autoload;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

try {
    $transport = Transport::fromDsn((string) getenv('DPLY_MAIL_DSN'));
    $mailer = new Mailer($transport);

    $fromName = (string) getenv('DPLY_MAIL_FROMNAME');
    $from = $fromName !== ''
        ? new Address((string) getenv('DPLY_MAIL_FROM'), $fromName)
        : new Address((string) getenv('DPLY_MAIL_FROM'));

    $email = (new Email())
        ->from($from)
        ->to((string) getenv('DPLY_MAIL_TO'))
        ->subject('dply test email')
        ->text("This is a test email sent by dply to verify your site's mail resource configuration.\n\nIf you received this, the transport is working.");

    $mailer->send($email);
    echo "DPLY_MAIL_OK\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    echo "DPLY_MAIL_FAIL\n";
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
