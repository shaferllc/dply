<?php

declare(strict_types=1);

namespace App\Actions\Cloud;

use App\Models\CloudBucket;
use App\Models\CloudDatabase;
use App\Models\CloudDeployTask;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Cloud\Backends\CloudRouter;
use App\Modules\Cloud\Backends\CloudScalingConfig;
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
        $this->applyDeployTasks($site, $payload['deploy_tasks'] ?? null);
        $this->applyAlerts($site, $payload['alerts'] ?? null);
        // 'databases' (array) is the multi-database path used by the create
        // form. 'database' (single dict) is kept for any other caller that
        // still passes one — internal compatibility, removable later.
        if (isset($payload['databases'])) {
            $this->applyDatabases($site, $payload['databases']);
        } else {
            $this->applyDatabase($site, $payload['database'] ?? null);
        }
        $this->applyBuckets($site, $payload['buckets'] ?? null);
        $this->applyDomains($site, $payload['domains'] ?? null);
    }

    /**
     * Persist per-site alert overrides on meta.container.alerts. Stored
     * in the same shape CloudAlerts::forSite reads, with array_replace_
     * recursive layering it over the defaults. Skips backends that
     * don't support alerts.
     */
    private function applyAlerts(Site $site, mixed $input): void
    {
        if (! is_array($input) || $input === []) {
            return;
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend === null || ! $backend->supportsAlerts()) {
            return;
        }

        $meta = $site->meta;
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $container['alerts'] = $input;
        $meta['container'] = $container;
        $site->update(['meta' => $meta]);
    }

    /**
     * Persist deploy-task rows from the create payload. Each entry is
     * a normalized assoc with `trigger`, `name`, `command`, and an
     * optional `size` (defaults to small). The first-class migrations
     * field and the extras repeater both feed into this same list.
     */
    private function applyDeployTasks(Site $site, mixed $input): void
    {
        if (! is_array($input) || $input === []) {
            return;
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend === null || ! $backend->supportsDeployTasks()) {
            throw new InvalidArgumentException(
                'This site\'s backend does not support deploy tasks. '
                .'Use a DigitalOcean App Platform site for migrations and other deploy-lifecycle commands.',
            );
        }

        $validTriggers = array_keys(CloudDeployTask::DO_KIND_MAP);
        $namesSeen = [];

        foreach ($input as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $trigger = strtolower(trim((string) ($raw['trigger'] ?? CloudDeployTask::TRIGGER_PRE_DEPLOY)));
            if (! in_array($trigger, $validTriggers, true)) {
                throw new InvalidArgumentException('Unknown deploy task trigger: '.$trigger);
            }

            $command = trim((string) ($raw['command'] ?? ''));
            if ($command === '') {
                // Skip silently — the UI's first-class migrations field
                // can land here as an empty row when the user leaves it
                // unticked. No reason to error out on it.
                continue;
            }

            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '') {
                $name = $trigger === CloudDeployTask::TRIGGER_PRE_DEPLOY
                    ? CloudDeployTask::NAME_MIGRATE
                    : 'task-'.(count($namesSeen) + 1);
            }
            if (isset($namesSeen[$name])) {
                throw new InvalidArgumentException('Duplicate deploy task name: '.$name);
            }
            $namesSeen[$name] = true;

            $size = strtolower(trim((string) ($raw['size'] ?? 'small')));
            if (! array_key_exists($size, CloudDeployTask::SIZE_TIERS)) {
                $size = 'small';
            }

            CloudDeployTask::query()->create([
                'site_id' => $site->id,
                'trigger' => $trigger,
                'name' => $name,
                'command' => $command,
                'size' => $size,
                'status' => CloudDeployTask::STATUS_CONFIGURED,
            ]);
        }
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

            $count = CloudWorker::normalizeInstanceCount(
                $size,
                (int) ($raw['instance_count'] ?? 1),
                $isScheduler,
            );

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

        $prefix = (string) ($input['env_prefix'] ?? $database->defaultEnvPrefix());

        // Pivot first so the relationship exists even if the DB is still
        // provisioning when initial provision runs. The pivot row carries
        // the per-attachment env_prefix so AttachCloudDatabaseJob can find
        // exactly which keys to inject/strip on later events.
        $database->sites()->syncWithoutDetaching([$site->id => ['env_prefix' => $prefix]]);

        // Merge connection env vars synchronously — when the DB is already
        // active these land in env_file_content before the provision job
        // reads it. When the DB is still provisioning, connectionEnvVars()
        // returns an empty array; ProvisionCloudDatabaseJob fans out an
        // AttachCloudDatabaseJob to every pivoted site on activation, so
        // the env vars + redeploy land automatically later.
        $vars = $this->parseEnvLines((string) ($site->env_file_content ?? ''));
        foreach ($database->connectionEnvVars($prefix) as $key => $value) {
            $vars[$key] = $value;
        }
        $site->update(['env_file_content' => $this->serializeEnvLines($vars)]);
    }

    /**
     * Many-database equivalent of applyDatabase — drives one applyDatabase
     * call per entry. Returns early if the input isn't a list of dicts.
     *
     * @param  array<int, mixed>|mixed  $input
     */
    private function applyDatabases(Site $site, mixed $input): void
    {
        if (! is_array($input)) {
            return;
        }
        foreach ($input as $entry) {
            $this->applyDatabase($site, $entry);
        }
    }

    /**
     * Create a CloudBucket row + pivot for each entry the form submitted.
     * Real provider provisioning (DO Spaces / S3 / R2) lands in a follow-
     * up PR; for now we materialize the record with status='pending' so
     * the canvas + manage surface have something to display, and the
     * pivot's env_prefix is set so a future AttachCloudBucketJob can
     * inject the right env-var keys.
     *
     * @param  array<int, mixed>|mixed  $input
     */
    private function applyBuckets(Site $site, mixed $input): void
    {
        if (! is_array($input)) {
            return;
        }
        foreach ($input as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $prefix = strtoupper((string) ($entry['env_prefix'] ?? 'S3'));
            $region = trim((string) ($entry['region'] ?? ''));

            // Org-uniqueness on name is enforced by a DB constraint;
            // we still firstOrCreate so re-submitting the same payload
            // (re-deploy after edit) reuses the existing record.
            $bucket = CloudBucket::query()->firstOrCreate(
                [
                    'organization_id' => $site->organization_id,
                    'name' => $name,
                ],
                [
                    'backend' => (string) ($entry['backend'] ?? CloudBucket::BACKEND_DIGITALOCEAN_SPACES),
                    'region' => $region !== '' ? $region : null,
                    'status' => CloudBucket::STATUS_PENDING,
                ],
            );

            $bucket->sites()->syncWithoutDetaching([$site->id => ['env_prefix' => $prefix]]);
        }
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
        $meta = $site->meta;
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
