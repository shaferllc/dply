<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Servers\ServerProviderSpecSync;
use Illuminate\Console\Command;

/**
 * Compare the size dply has stored for a server against what its cloud
 * provider reports right now.
 *
 *   dply:server:size divineiv
 *   dply:server:size 594837801 --json
 *
 * Answers "the workspace says one size, the provider console says another —
 * which is lying?". Reads both sides and prints them side by side, plus the
 * resize breadcrumb ({@see \App\Jobs\ResizeServerJob}) so a half-finished or
 * failed resize is visible as the cause rather than a mystery.
 *
 * Strictly read-only: it never calls sync(), because a diagnostic that
 * repaired the drift it was measuring would erase the evidence. Use the
 * Settings → "Verify with provider" button (or a resize) to actually write.
 *
 * Exits 1 when the stored size disagrees with the provider.
 */
class ServerSizeCommand extends Command
{
    protected $signature = 'dply:server:size
        {server : Server ID, name, provider ID, or IP}
        {--json : Output as JSON}';

    protected $description = 'Compare a server\'s stored size against what the provider reports now.';

    public function handle(ServerProviderSpecSync $specSync): int
    {
        $needle = (string) $this->argument('server');

        $server = Server::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->orWhere('provider_id', $needle)
            ->orWhere('ip_address', $needle)
            ->first();

        if ($server === null) {
            $this->components->error('Server not found: '.$needle);

            return self::FAILURE;
        }

        $storedMeta = is_array($server->meta['provider_spec'] ?? null) ? $server->meta['provider_spec'] : [];
        $resize = is_array($server->meta['resize'] ?? null) ? $server->meta['resize'] : null;

        $live = null;
        $error = null;
        try {
            $live = $specSync->liveSpec($server);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $mismatch = $live !== null
            && filled($live['size'])
            && (string) $live['size'] !== (string) $server->size;

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'server' => $server->name,
                'provider' => $server->provider->value,
                'provider_id' => $server->provider_id,
                'stored' => [
                    'size' => $server->size,
                    'region' => $server->region,
                    'vcpus' => $storedMeta['vcpus'] ?? null,
                    'memory_mb' => $storedMeta['memory_mb'] ?? null,
                    'disk_gb' => $storedMeta['disk_gb'] ?? null,
                    'synced_at' => $storedMeta['synced_at'] ?? null,
                ],
                'live' => $live,
                'live_error' => $error,
                'mismatch' => $mismatch,
                'resize' => $resize,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $mismatch || $error !== null ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%s — %s (provider id %s)',
            $server->name,
            $server->provider->value,
            $server->provider_id ?: '—',
        ));

        $this->table(
            ['', 'size', 'vCPU', 'memory', 'disk', 'region'],
            [
                [
                    'stored in dply',
                    $server->size ?: '—',
                    $storedMeta['vcpus'] ?? '—',
                    isset($storedMeta['memory_mb']) ? $storedMeta['memory_mb'].' MB' : '—',
                    isset($storedMeta['disk_gb']) ? $storedMeta['disk_gb'].' GB' : '—',
                    $server->region ?: '—',
                ],
                $live === null
                    ? ['live at provider', 'LOOKUP FAILED', '—', '—', '—', '—']
                    : [
                        'live at provider',
                        $live['size'] ?? '—',
                        $live['vcpus'] ?? '—',
                        isset($live['memory_mb']) ? $live['memory_mb'].' MB' : '—',
                        isset($live['disk_gb']) ? $live['disk_gb'].' GB' : '—',
                        $live['region'] ?? '—',
                    ],
            ],
        );

        if (filled($storedMeta['synced_at'] ?? null)) {
            $this->components->twoColumnDetail('last verified', (string) $storedMeta['synced_at']);
        }

        if ($resize !== null) {
            $this->newLine();
            $this->components->twoColumnDetail('resize state', (string) ($resize['state'] ?? '?'));
            $this->components->twoColumnDetail('resize target', (string) ($resize['target_size'] ?? '?'));
            $this->components->twoColumnDetail('grew disk', ($resize['grow_disk'] ?? false) ? 'yes (permanent)' : 'no');
            if (filled($resize['error'] ?? null)) {
                $this->components->twoColumnDetail('resize error', (string) $resize['error']);
            }
        }

        if ($error !== null) {
            $this->newLine();
            $this->components->error('Provider lookup failed: '.$error);

            return self::FAILURE;
        }

        $this->newLine();
        if ($mismatch) {
            $this->components->error(sprintf(
                'MISMATCH — dply stores "%s" but the provider reports "%s".',
                $server->size,
                $live['size'],
            ));
            $this->components->info('The provider is the source of truth. "Verify with provider" in Settings writes its answer to the row.');

            return self::FAILURE;
        }

        $this->components->info('Stored size matches the provider.');

        return self::SUCCESS;
    }
}
