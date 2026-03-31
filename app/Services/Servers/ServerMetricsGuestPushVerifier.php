<?php

namespace App\Services\Servers;

use App\Models\Server;
use RuntimeException;

class ServerMetricsGuestPushVerifier
{
    /**
     * @return array{
     *   configured: bool,
     *   callback_env_deployed: bool,
     *   cron_installed: bool,
     *   cron_current: bool,
     *   token_present: bool,
     *   script_current: bool,
     *   expected_cron: string,
     *   current_cron: ?string,
     *   bundled_sha: string,
     *   remote_sha: ?string,
     *   callback_env_deployed_at: ?string,
     *   cron_installed_at: ?string,
     *   last_guest_sample_at: ?string
     * }
     */
    public function summary(Server $server): array
    {
        $meta = $server->meta ?? [];
        $push = app(ServerMetricsGuestPushService::class);
        $guest = app(ServerMetricsGuestScript::class);

        $expectedCron = $push->normalizedGuestPushCronExpression();
        $currentCron = is_string($meta['monitoring_guest_push_cron_expression'] ?? null)
            ? $meta['monitoring_guest_push_cron_expression']
            : null;
        $remoteSha = is_string($meta['monitoring_guest_script_sha'] ?? null)
            ? $meta['monitoring_guest_script_sha']
            : (is_string($meta['monitoring_guest_script_sha256'] ?? null) ? $meta['monitoring_guest_script_sha256'] : null);
        $lastGuestSampleAt = is_string($meta['monitoring_guest_push_last_sample_at'] ?? null)
            ? $meta['monitoring_guest_push_last_sample_at']
            : null;
        $tokenPresent = ! empty($meta['monitoring_guest_push_token_hash']);
        $callbackEnvMarked = ! empty($meta['monitoring_callback_env_deployed']);
        $cronMarked = ! empty($meta['monitoring_guest_cron_installed_at']);
        $remoteEnvPresent = array_key_exists('monitoring_callback_env_present_remote', $meta)
            ? (bool) $meta['monitoring_callback_env_present_remote']
            : $callbackEnvMarked;
        $remoteCronPresent = array_key_exists('monitoring_guest_cron_present_remote', $meta)
            ? (bool) $meta['monitoring_guest_cron_present_remote']
            : $cronMarked;
        $callbackEnvDeployed = $callbackEnvMarked && $remoteEnvPresent;
        $cronInstalled = $cronMarked && $remoteCronPresent;
        $cronCurrent = $cronInstalled && $currentCron === $expectedCron;
        $bundledSha = $guest->bundledSha256();
        $scriptCurrent = $remoteSha !== null && $remoteSha === $bundledSha;

        return [
            'configured' => $callbackEnvDeployed && $cronInstalled && $tokenPresent && $scriptCurrent,
            'callback_env_deployed' => $callbackEnvDeployed,
            'cron_installed' => $cronInstalled,
            'cron_current' => $cronCurrent,
            'token_present' => $tokenPresent,
            'script_current' => $scriptCurrent,
            'expected_cron' => $expectedCron,
            'current_cron' => $currentCron,
            'bundled_sha' => $bundledSha,
            'remote_sha' => $remoteSha,
            'callback_env_deployed_at' => is_string($meta['monitoring_callback_env_deployed_at'] ?? null) ? $meta['monitoring_callback_env_deployed_at'] : null,
            'cron_installed_at' => is_string($meta['monitoring_guest_cron_installed_at'] ?? null) ? $meta['monitoring_guest_cron_installed_at'] : null,
            'last_guest_sample_at' => $lastGuestSampleAt,
        ];
    }

    public function refreshRemoteState(Server $server): array
    {
        $out = app(ExecuteRemoteTaskOnServer::class)->runInlineBash(
            $server,
            'verify-guest-metrics-push',
            $this->verificationBash(),
            45,
            false,
        );

        if (! $out->isSuccessful()) {
            throw new RuntimeException(__('Could not verify guest monitoring setup over SSH right now.'));
        }

        $buffer = ServerManageSshExecutor::stripSshClientNoise($out->getBuffer());
        $state = $this->parseVerificationBuffer($buffer);

        $meta = $server->fresh()->meta ?? [];
        $meta['monitoring_guest_verify_checked_at'] = now()->toIso8601String();
        $meta['monitoring_guest_script_sha'] = $state['remote_sha'];
        $meta['monitoring_callback_env_present_remote'] = $state['env_present'];
        $meta['monitoring_guest_cron_present_remote'] = $state['cron_present'];

        $server->forceFill(['meta' => $meta])->saveQuietly();

        return $this->summary($server->fresh());
    }

    protected function verificationBash(): string
    {
        return <<<'BASH'
SCRIPT="$HOME/.dply/bin/server-metrics-snapshot.py"
ENV_FILE="$HOME/.dply/metrics-callback.env"

if [ -f "$SCRIPT" ]; then
  echo "DPLY_MONITOR_SCRIPT_SHA=$(sha256sum "$SCRIPT" | awk '{print $1}')"
else
  echo "DPLY_MONITOR_SCRIPT_SHA=MISSING"
fi

if [ -f "$ENV_FILE" ]; then
  echo "DPLY_MONITOR_ENV=1"
else
  echo "DPLY_MONITOR_ENV=0"
fi

if (crontab -l 2>/dev/null || true) | grep -Fq '# BEGIN DPLY METRICS GUEST'; then
  echo "DPLY_MONITOR_CRON=1"
else
  echo "DPLY_MONITOR_CRON=0"
fi
BASH;
    }

    /**
     * @return array{remote_sha: ?string, env_present: bool, cron_present: bool}
     */
    protected function parseVerificationBuffer(string $buffer): array
    {
        $state = [
            'remote_sha' => null,
            'env_present' => false,
            'cron_present' => false,
        ];

        foreach (preg_split('/\R/u', $buffer) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'DPLY_MONITOR_SCRIPT_SHA=')) {
                $sha = trim(substr($line, strlen('DPLY_MONITOR_SCRIPT_SHA=')));
                $state['remote_sha'] = $sha !== '' && $sha !== 'MISSING' ? $sha : null;
            }

            if ($line === 'DPLY_MONITOR_ENV=1') {
                $state['env_present'] = true;
            }

            if ($line === 'DPLY_MONITOR_CRON=1') {
                $state['cron_present'] = true;
            }
        }

        return $state;
    }
}
