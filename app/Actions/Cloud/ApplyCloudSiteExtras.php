<?php

declare(strict_types=1);

namespace App\Actions\Cloud;

use App\Models\CloudDatabase;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\CloudScalingConfig;
use InvalidArgumentException;

/**
 * Apply the "all-in-one deploy" extras to a freshly-created Cloud
 * Site before the initial ProvisionCloudSiteJob dispatches. The DO
 * App Platform backend's provision() and provisionFromSource()
 * methods read worker rows + autoscaling/health-check meta when
 * building the app spec — landing everything in DB first means
 * day-one provisioning carries the full configuration without
 * follow-up sync jobs.
 *
 * Each extras block is independently optional; the helper no-ops
 * for any block that the caller didn't supply.
 *
 * Sequencing note for `database.mode = 'attach'`: env vars are
 * merged into site.env_file_content synchronously here so the
 * initial provision sees them. Backend updateEnvVars and redeploy
 * are skipped (the upcoming provision dispatch handles both).
 */
class ApplyCloudSiteExtras
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Site $site, array $payload): void
    {
        $this->applyAutoscaling($site, $payload['autoscaling'] ?? null);
        $this->applyHealthCheck($site, $payload['health_check'] ?? null);
        $this->applyWorkers($site, $payload['workers'] ?? null);
        $this->applyDatabase($site, $payload['database'] ?? null);
        $this->applyDomains($site, $payload['domains'] ?? null);
    }

    private function applyAutoscaling(Site $site, mixed $input): void
    {
        if (! is_array($input) || empty($input['enabled'])) {
            return;
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend === null || ! $backend->supportsAutoscaling()) {
            throw new InvalidArgumentException('This site\'s backend does not support autoscaling.');
        }

        $config = CloudScalingConfig::validateAutoscaling($input);
        CloudScalingConfig::persistAutoscaling($site, $config);
    }

    private function applyHealthCheck(Site $site, mixed $input): void
    {
        if (! is_array($input) || empty($input['enabled'])) {
            return;
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend === null || ! $backend->supportsAutoscaling()) {
            throw new InvalidArgumentException('This site\'s backend does not support health checks.');
        }

        $config = CloudScalingConfig::validateHealthCheck($input);
        CloudScalingConfig::persistHealthCheck($site, $config);
    }

    private function applyWorkers(Site $site, mixed $input): void
    {
        if (! is_array($input) || $input === []) {
            return;
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend === null || ! $backend->supportsWorkers()) {
            throw new InvalidArgumentException(
                'This site\'s backend does not support background workers. '
                .'Use a DigitalOcean App Platform site for queue workers and the scheduler.',
            );
        }

        $schedulerSeen = false;
        foreach ($input as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $type = strtolower(trim((string) ($raw['type'] ?? CloudWorker::TYPE_WORKER)));
            if (! in_array($type, [CloudWorker::TYPE_WORKER, CloudWorker::TYPE_SCHEDULER], true)) {
                throw new InvalidArgumentException('Unknown worker type: '.$type);
            }
            if ($type === CloudWorker::TYPE_SCHEDULER) {
                if ($schedulerSeen) {
                    throw new InvalidArgumentException('Only one scheduler is allowed per site.');
                }
                $schedulerSeen = true;
            }

            $size = strtolower(trim((string) ($raw['size'] ?? 'small')));
            if (! array_key_exists($size, CloudWorker::SIZE_TIERS)) {
                $size = 'small';
            }

            $isScheduler = $type === CloudWorker::TYPE_SCHEDULER;
            $command = trim((string) ($raw['command'] ?? ''));
            if ($isScheduler) {
                $command = CloudWorker::SCHEDULER_COMMAND;
            } elseif ($command === '') {
                $command = CloudWorker::DEFAULT_WORKER_COMMAND;
            }

            $count = (int) ($raw['instance_count'] ?? 1);
            if ($isScheduler || $count < 1) {
                $count = 1;
            }

            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '') {
                $name = $isScheduler ? 'scheduler' : 'worker';
            }

            CloudWorker::query()->create([
                'site_id' => $site->id,
                'type' => $type,
                'name' => $name,
                'command' => $command,
                'size' => $size,
                'instance_count' => $count,
                'status' => CloudWorker::STATUS_PROVISIONING,
            ]);
        }
    }

    private function applyDatabase(Site $site, mixed $input): void
    {
        if (! is_array($input)) {
            return;
        }
        $mode = (string) ($input['mode'] ?? 'none');
        if ($mode === 'none' || $mode === '') {
            return;
        }

        $database = match ($mode) {
            'attach' => $this->resolveExistingDatabase($site, $input),
            'create' => $this->createNewDatabase($site, $input),
            default => throw new InvalidArgumentException(
                'Unsupported database mode: '.$mode.' (use "attach" or "create").',
            ),
        };

        // Pivot first so the relationship exists even if the DB is still
        // provisioning when initial provision runs.
        $database->sites()->syncWithoutDetaching([$site->id]);

        // Merge connection env vars synchronously — when the DB is already
        // active these land in env_file_content before the provision job
        // reads it. When the DB is still provisioning, connectionEnvVars()
        // returns an empty array; ProvisionCloudDatabaseJob fans out an
        // AttachCloudDatabaseJob to every pivoted site on activation, so
        // the env vars + redeploy land automatically later.
        $vars = $this->parseEnvLines((string) ($site->env_file_content ?? ''));
        foreach ($database->connectionEnvVars() as $key => $value) {
            $vars[$key] = $value;
        }
        $site->update(['env_file_content' => $this->serializeEnvLines($vars)]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveExistingDatabase(Site $site, array $input): CloudDatabase
    {
        $databaseId = (string) ($input['cloud_database_id'] ?? '');
        if ($databaseId === '') {
            throw new InvalidArgumentException('Pick a database to attach.');
        }

        $database = CloudDatabase::query()->find($databaseId);
        if ($database === null || $database->organization_id !== $site->organization_id) {
            throw new InvalidArgumentException('Selected database is not available in this organization.');
        }

        return $database;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createNewDatabase(Site $site, array $input): CloudDatabase
    {
        $organization = Organization::query()->find($site->organization_id);
        if ($organization === null) {
            throw new InvalidArgumentException('Site has no organization — cannot provision a database for it.');
        }

        return (new CreateCloudDatabase)->handle($organization, [
            'name' => (string) ($input['name'] ?? ''),
            'engine' => (string) ($input['engine'] ?? ''),
            'version' => (string) ($input['version'] ?? ''),
            'size' => (string) ($input['size'] ?? 'small'),
            'region' => (string) ($input['region'] ?? ''),
        ]);
    }

    private function applyDomains(Site $site, mixed $input): void
    {
        if (! is_array($input) || $input === []) {
            return;
        }

        $hostnames = [];
        foreach ($input as $raw) {
            $hostname = strtolower(trim((string) $raw));
            if ($hostname === '') {
                continue;
            }
            if (! preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $hostname)) {
                throw new InvalidArgumentException("Invalid hostname: {$hostname}");
            }
            $hostnames[$hostname] = true; // dedup
        }
        if ($hostnames === []) {
            return;
        }

        // Custom domain attach must happen on the backend AFTER the site has a
        // container_backend_id — too early and AttachCloudDomainJob no-ops.
        // Stage the desired hostnames on meta; PollCloudStatusJob fans out
        // the actual attach jobs when the site flips to active.
        $meta = is_array($site->meta) ? $site->meta : [];
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $existing = is_array($container['pending_domains'] ?? null) ? $container['pending_domains'] : [];
        $merged = array_values(array_unique(array_merge(
            array_values(array_filter($existing, 'is_string')),
            array_keys($hostnames),
        )));
        $container['pending_domains'] = $merged;
        $meta['container'] = $container;
        $site->update(['meta' => $meta]);
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvLines(string $envContent): array
    {
        if ($envContent === '') {
            return [];
        }
        $vars = [];
        foreach (explode("\n", $envContent) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1), " \t\"'");
            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function serializeEnvLines(array $vars): string
    {
        $lines = [];
        foreach ($vars as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        return implode("\n", $lines);
    }
}
