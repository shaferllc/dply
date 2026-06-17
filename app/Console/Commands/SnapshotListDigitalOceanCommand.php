<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DigitalOceanService;
use Illuminate\Console\Command;

class SnapshotListDigitalOceanCommand extends Command
{
    protected $signature = 'dply:do:snapshot:list
        {--token= : DO API token (else env DPLY_SNAPSHOT_DO_TOKEN or DIGITALOCEAN_TOKEN)}
        {--prefix= : Filter snapshot names by prefix (default shows all droplet snapshots)}
        {--json : Emit JSON instead of a table}';

    protected $description = 'List custom DigitalOcean droplet snapshots in the account.';

    public function handle(): int
    {
        $token = $this->resolveToken();
        if ($token === null) {
            $this->error('Set --token=... or env DPLY_SNAPSHOT_DO_TOKEN / DIGITALOCEAN_TOKEN.');

            return self::FAILURE;
        }

        try {
            $do = new DigitalOceanService($token);
            $snapshots = $do->getSnapshots('droplet');
        } catch (\Throwable $e) {
            $this->error('DigitalOcean API call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $prefix = (string) ($this->option('prefix') ?? '');
        if ($prefix !== '') {
            $snapshots = array_values(array_filter(
                $snapshots,
                static fn (array $s): bool => str_starts_with((string) ($s['name'] ?? ''), $prefix)
            ));
        }

        if ($this->option('json')) {
            $this->line(json_encode($snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($snapshots === []) {
            $this->info('No droplet snapshots found.');

            return self::SUCCESS;
        }

        $rows = array_map(static function (array $s): array {
            $regions = $s['regions'] ?? [];

            return [
                'id' => (string) ($s['id'] ?? ''),
                'name' => (string) ($s['name'] ?? ''),
                'distribution' => (string) ($s['distribution'] ?? ''),
                'size_gb' => (string) ($s['size_gigabytes'] ?? $s['min_disk_size'] ?? ''),
                'regions' => is_array($regions) ? implode(',', $regions) : '',
                'created_at' => (string) ($s['created_at'] ?? ''),
            ];
        }, $snapshots);

        $this->table(['id', 'name', 'distribution', 'size_gb', 'regions', 'created_at'], $rows);

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
