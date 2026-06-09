<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\SshConnectionFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * APP_KEY drift guard (W5) — the app-native replacement for the old
 * deploy/check-env-drift.sh. SSHes each adopted control-plane Server (via dply's
 * own connection), hashes the APP_KEY value ON the box, and fails + alerts if the
 * digests diverge. The original incident was three different APP_KEYs across
 * boxes (DecryptException + Livewire asset 404s); a value mismatch is fatal, so
 * this exits non-zero on divergence. The raw value never leaves the server.
 */
class SecretsCheckDriftCommand extends Command
{
    protected $signature = 'secrets:check-drift';

    protected $description = 'Detect APP_KEY value drift across the control-plane boxes.';

    public function handle(SshConnectionFactory $ssh): int
    {
        $targets = (array) config('secret_vault.drift.targets');
        if ($targets === []) {
            $this->info('No drift targets configured — nothing to check.');

            return self::SUCCESS;
        }

        $hashes = [];
        foreach ($targets as $target) {
            $serverId = (string) ($target['server_id'] ?? '');
            $envPath = (string) ($target['env_path'] ?? '');
            $server = $serverId !== '' ? Server::query()->find($serverId) : null;
            if ($server === null || $envPath === '') {
                $this->warn('Skipping invalid drift target: '.json_encode($target));

                continue;
            }

            // Hash on the box; only the 16-char digest comes back.
            $cmd = sprintf(
                'v=$(grep -E "^APP_KEY=" %s 2>/dev/null | head -1 | cut -d= -f2-); '
                .'[ -n "$v" ] && printf %%s "$v" | sha256sum | cut -c1-16 || echo MISSING',
                escapeshellarg($envPath),
            );

            try {
                $conn = $ssh->forServer($server);
                $digest = trim($conn->exec($cmd, 30));
                $conn->disconnect();
            } catch (\Throwable $e) {
                $this->error("SSH to {$server->name} failed: ".$e->getMessage());
                $digest = 'ERROR';
            }

            $hashes[$server->name] = $digest;
            $this->line("  {$server->name}: {$digest}");
        }

        $distinct = array_values(array_unique(array_filter($hashes, fn ($h) => $h !== 'ERROR')));
        $hasError = in_array('ERROR', $hashes, true) || in_array('MISSING', $hashes, true);

        if (count($distinct) > 1 || $hasError) {
            $msg = count($distinct) > 1
                ? 'APP_KEY VALUE DRIFT across control-plane boxes — fix before any deploy.'
                : 'APP_KEY drift check could not read every box (MISSING/ERROR).';
            $this->error($msg);
            $this->notifyAlert($msg);

            return self::FAILURE;
        }

        $this->info('APP_KEY is consistent across all control-plane boxes.');

        return self::SUCCESS;
    }

    private function notifyAlert(string $message): void
    {
        $hook = config('secret_vault.alert_webhook');
        if (! is_string($hook) || $hook === '') {
            return;
        }
        try {
            Http::timeout(10)->post($hook, ['text' => "[secret-vault] {$message}"]);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
