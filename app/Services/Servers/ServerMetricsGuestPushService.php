<?php

namespace App\Services\Servers;

use App\Jobs\DeployGuestMetricsCallbackEnvJob;
use App\Models\Server;
use Illuminate\Support\Str;

/**
 * Per-server token for {@see GuestMetricsPushController}, ~/.dply/metrics-callback.env,
 * and the guest user crontab block installed by {@see DeployGuestMetricsCallbackEnvJob}.
 */
class ServerMetricsGuestPushService
{
    public function isEnabled(): bool
    {
        return (bool) config('server_metrics.guest_push.enabled', true);
    }

    /**
     * Ensure a push token exists when guest push is enabled. Dispatches sync when
     * ~/.dply/metrics-callback.env or the guest crontab block is not yet applied.
     */
    public function ensureConfigured(Server $server): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $server = $server->fresh();
        $meta = $server->meta ?? [];

        if (empty($meta['monitoring_guest_push_token_hash'])) {
            $this->generateAndStoreToken($server);
            $server = $server->fresh();
            $meta = $server->meta ?? [];
        }

        if (empty($meta['monitoring_guest_push_token_hash'])) {
            return;
        }

        $desiredCron = $this->normalizedGuestPushCronExpression();
        $storedCronRaw = $meta['monitoring_guest_push_cron_expression'] ?? null;
        $storedCronStr = is_string($storedCronRaw) ? $storedCronRaw : '';
        $missingStoredExpr = ! empty($meta['monitoring_guest_cron_installed_at']) && $storedCronStr === '';
        $cronOutOfDate = $missingStoredExpr || ($storedCronStr !== '' && $storedCronStr !== $desiredCron);

        if (! empty($meta['monitoring_callback_env_deployed'])
            && ! empty($meta['monitoring_guest_cron_installed_at'])
            && ! $cronOutOfDate) {
            return;
        }

        DeployGuestMetricsCallbackEnvJob::dispatch($server->id);
    }

    /**
     * After “Install monitoring” completes: ensure a push token exists and queue env + crontab sync on the guest.
     */
    public function syncPushArtifactsAfterInstall(Server $server): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $server = $server->fresh();
        $meta = $server->meta ?? [];
        if (empty($meta['monitoring_guest_push_token_hash'])) {
            $this->generateAndStoreToken($server);
        }

        DeployGuestMetricsCallbackEnvJob::dispatch($server->id);
    }

    public function generateAndStoreToken(Server $server): string
    {
        $plain = Str::random(64);
        $meta = $server->meta ?? [];
        $meta['monitoring_guest_push_token_hash'] = hash('sha256', $plain);
        $meta['monitoring_guest_push_cipher'] = encrypt($plain);
        unset($meta['monitoring_callback_env_deployed'], $meta['monitoring_guest_cron_installed_at']);
        $server->forceFill(['meta' => $meta])->saveQuietly();

        return $plain;
    }

    public function verifyToken(Server $server, string $plainToken): bool
    {
        $hash = $server->meta['monitoring_guest_push_token_hash'] ?? null;

        return is_string($hash)
            && $hash !== ''
            && hash_equals($hash, hash('sha256', $plainToken));
    }

    public function plainTokenForDeploy(Server $server): ?string
    {
        $cipher = $server->meta['monitoring_guest_push_cipher'] ?? null;
        if (! is_string($cipher) || $cipher === '') {
            return null;
        }

        try {
            return decrypt($cipher);
        } catch (\Throwable) {
            return null;
        }
    }

    public function guestPushUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/metrics/guest-push';
    }

    /**
     * Five-field cron schedule for the guest user crontab (invalid config falls back to every 5 minutes).
     */
    public function normalizedGuestPushCronExpression(): string
    {
        $expr = trim((string) config('server_metrics.guest_push.cron_expression', '*/5 * * * *'));
        $parts = preg_split('/\s+/', $expr, 6, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) !== 5) {
            return '*/5 * * * *';
        }

        return implode(' ', $parts);
    }

    /**
     * Bash: write ~/.dply/metrics-callback.env (mode 600). Content is base64-encoded in the fragment for safe quoting.
     */
    public function writeCallbackEnvFileBash(Server $server): string
    {
        $plain = $this->plainTokenForDeploy($server);
        if ($plain === null) {
            return "# skip: no push token\n";
        }

        $content = sprintf(
            "DPLY_METRICS_CALLBACK_URL=%s\nDPLY_METRICS_SERVER_ID=%s\nDPLY_METRICS_CALLBACK_TOKEN=%s\n",
            $this->guestPushUrl(),
            $server->id,
            $plain
        );
        $b64 = base64_encode($content);

        return <<<BASH
mkdir -p "\$HOME/.dply"
printf '%s' '{$b64}' | base64 -d > "\$HOME/.dply/metrics-callback.env"
chmod 600 "\$HOME/.dply/metrics-callback.env"
BASH;
    }

    /**
     * Bash: merge a marked crontab block so the guest script runs on a schedule (idempotent).
     */
    public function installGuestMetricsCronBash(): string
    {
        $expr = $this->normalizedGuestPushCronExpression();
        $line = $expr.' /usr/bin/python3 $HOME/.dply/bin/server-metrics-snapshot.py >/dev/null 2>&1';
        $b64 = base64_encode($line);

        return <<<BASH
MARK_B='# BEGIN DPLY METRICS GUEST'
MARK_E='# END DPLY METRICS GUEST'
TMP_CUR="\$(mktemp)"
TMP_NEW="\$(mktemp)"
(crontab -l 2>/dev/null || true) > "\$TMP_CUR"
awk -v b="\$MARK_B" -v e="\$MARK_E" '
  \$0==b {skip=1; next}
  \$0==e {skip=0; next}
  !skip {print}
' "\$TMP_CUR" > "\$TMP_NEW" || true
CRON_LINE="\$(printf '%s' '{$b64}' | base64 -d)"
{
  cat "\$TMP_NEW"
  echo "\$MARK_B"
  echo "\$CRON_LINE"
  echo "\$MARK_E"
} | crontab -
rm -f "\$TMP_CUR" "\$TMP_NEW"
BASH;
    }
}
