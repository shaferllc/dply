<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Services\DigitalOceanService;
use Illuminate\Console\Command;

class SnapshotDeleteDigitalOceanCommand extends Command
{
    protected $signature = 'dply:do:snapshot:delete
        {snapshot_id : DO snapshot id (numeric or string)}
        {--token= : DO API token (else env DPLY_SNAPSHOT_DO_TOKEN or DIGITALOCEAN_TOKEN)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a custom DigitalOcean snapshot.';

    public function handle(): int
    {
        $token = $this->resolveToken();
        if ($token === null) {
            $this->error('Set --token=... or env DPLY_SNAPSHOT_DO_TOKEN / DIGITALOCEAN_TOKEN.');

            return self::FAILURE;
        }

        $snapshotId = (string) $this->argument('snapshot_id');
        if (! $this->option('force') && ! $this->confirm("Delete DigitalOcean snapshot {$snapshotId}? This cannot be undone.", false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        try {
            $do = new DigitalOceanService($token);
            $do->deleteSnapshot($snapshotId);
        } catch (\Throwable $e) {
            $this->error('DigitalOcean API call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Deleted snapshot {$snapshotId}.");

        return self::SUCCESS;
    }

    private function resolveToken(): ?string
    {
        foreach ([$this->option('token'), config('dply.snapshot_do_token'), config('dply.digitalocean_token')] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
