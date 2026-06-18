<?php

declare(strict_types=1);

namespace App\Livewire\Cloud\Concerns;

use App\Models\CloudBucket;
use App\Models\CloudDatabase;
use App\Models\CloudDeployTask;
use App\Models\CloudWorker;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCloudResources
{


    public function addDomain(): void
    {
        $hostname = strtolower(trim($this->new_domain));
        if ($hostname === '') {
            $this->toastError(__('Hostname is required.'));

            return;
        }
        if (! preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $hostname)) {
            $this->toastError(__('That doesn\'t look like a valid hostname.'));

            return;
        }
        if (in_array($hostname, $this->domains, true)) {
            $this->new_domain = '';

            return;
        }
        $this->domains[] = $hostname;
        $this->new_domain = '';
    }

    public function removeDomain(int $index): void
    {
        if (! isset($this->domains[$index])) {
            return;
        }
        array_splice($this->domains, $index, 1);
        $this->domains = array_values($this->domains);
    }

    public function addWorker(string $type = CloudWorker::TYPE_WORKER): void
    {
        if ($type === CloudWorker::TYPE_SCHEDULER && $this->hasScheduler()) {
            $this->toastError(__('Only one scheduler is allowed per site.'));

            return;
        }

        // Source mode = dply builds with a buildpack and Laravel is the
        // default story, so pre-fill the artisan command. Image mode is
        // BYO container — we don't know what's installed, so leave the
        // command blank and force a deliberate value before submit. The
        // form's `required` rule on workers.*.command then surfaces the
        // gap cleanly instead of letting users ship a Laravel command to
        // a Postgres/nginx/whatever container that doesn't have `php`.
        $isSourceMode = $this->mode === 'source';
        $command = $type === CloudWorker::TYPE_SCHEDULER
            ? ($isSourceMode ? CloudWorker::SCHEDULER_COMMAND : '')
            : ($isSourceMode ? CloudWorker::DEFAULT_WORKER_COMMAND : '');

        $this->workers[] = [
            'type' => $type,
            'name' => $type === CloudWorker::TYPE_SCHEDULER ? 'scheduler' : 'worker-'.(count($this->workers) + 1),
            'command' => $command,
            'size' => 'small',
            'instance_count' => 1,
        ];
    }

    public function removeWorker(int $index): void
    {
        if (! isset($this->workers[$index])) {
            return;
        }
        array_splice($this->workers, $index, 1);
        $this->workers = array_values($this->workers);
    }

    public function hasScheduler(): bool
    {
        foreach ($this->workers as $worker) {
            if (($worker['type'] ?? null) === CloudWorker::TYPE_SCHEDULER) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop a new database entry on the canvas in create-mode for the
     * picked engine. Name + env_prefix are auto-picked to avoid collisions
     * with other rows + with existing org-level CloudDatabases.
     */
    public function addDatabase(string $engine = CloudDatabase::ENGINE_POSTGRES): void
    {
        if (! in_array($engine, [CloudDatabase::ENGINE_POSTGRES, CloudDatabase::ENGINE_MYSQL, CloudDatabase::ENGINE_REDIS], true)) {
            $engine = CloudDatabase::ENGINE_POSTGRES;
        }

        $id = 'db-'.bin2hex(random_bytes(4));

        $this->databases[] = [
            '_id' => $id,
            'mode' => 'create',
            'name' => $this->nextDatabaseName($engine),
            'engine' => $engine,
            'version' => $this->defaultEngineVersion($engine),
            'size' => 'small',
            'env_prefix' => $this->nextEnvPrefix($engine),
        ];

        // The canvas's Alpine state seeds its `cards` map from PHP on
        // mount; new rows added after mount need a runtime hint so the
        // diagram can place + connect them.
        $this->dispatch('database-added', id: $id);
    }

    public function removeDatabase(int $index): void
    {
        if (! isset($this->databases[$index])) {
            return;
        }
        $removedId = (string) ($this->databases[$index]['_id'] ?? '');
        array_splice($this->databases, $index, 1);
        $this->databases = array_values($this->databases);

        if ($removedId !== '') {
            $this->dispatch('database-removed', id: $removedId);
        }
    }

    /**
     * Pick the lowest "{engine}-N" not already used by another row in the
     * form OR by an existing CloudDatabase in this org.
     */
    private function nextDatabaseName(string $engine): string
    {
        $taken = [];
        foreach ($this->databases as $row) {
            $taken[strtolower((string) ($row['name'] ?? ''))] = true;
        }
        $org = auth()->user()?->currentOrganization();
        if ($org !== null) {
            foreach (CloudDatabase::query()
                ->where('organization_id', $org->id)
                ->where('name', 'ilike', $engine.'-%')
                ->pluck('name') as $existing) {
                $taken[strtolower((string) $existing)] = true;
            }
        }
        $n = 1;
        while (isset($taken[$engine.'-'.$n])) {
            $n++;
        }

        return $engine.'-'.$n;
    }

    /**
     * Pick the lowest non-colliding env_prefix among the rows currently in
     * the form. Defaults: Postgres/MySQL → DB, DB_2, DB_3; Redis → REDIS,
     * REDIS_2, REDIS_3. User can override inline; the validator catches
     * duplicates introduced by manual edits.
     */
    private function nextEnvPrefix(string $engine): string
    {
        $base = $engine === CloudDatabase::ENGINE_REDIS ? 'REDIS' : 'DB';
        $taken = [];
        foreach ($this->databases as $row) {
            $taken[strtoupper((string) ($row['env_prefix'] ?? ''))] = true;
        }
        if (! isset($taken[$base])) {
            return $base;
        }
        $n = 2;
        while (isset($taken[$base.'_'.$n])) {
            $n++;
        }

        return $base.'_'.$n;
    }

    private function defaultEngineVersion(string $engine): string
    {
        return match ($engine) {
            CloudDatabase::ENGINE_POSTGRES => '17',
            CloudDatabase::ENGINE_MYSQL => '8',
            CloudDatabase::ENGINE_REDIS => '7',
            default => '',
        };
    }

    /**
     * Drop a new bucket entry on the canvas. Name is auto-picked to avoid
     * conflicts with other rows + with any existing CloudBucket in this
     * org (org-uniqueness is a DB constraint, so prevention beats relying
     * on the deploy-time error). Prefix defaults to S3, then S3_2, etc.
     */
    public function addBucket(): void
    {
        $id = 'bkt-'.bin2hex(random_bytes(4));
        $this->buckets[] = [
            '_id' => $id,
            'name' => $this->nextBucketName(),
            'backend' => CloudBucket::BACKEND_DIGITALOCEAN_SPACES,
            'region' => $this->region,
            'env_prefix' => $this->nextBucketPrefix(),
        ];
        $this->dispatch('bucket-added', id: $id);
    }

    public function removeBucket(int $index): void
    {
        if (! isset($this->buckets[$index])) {
            return;
        }
        $removedId = (string) ($this->buckets[$index]['_id'] ?? '');
        array_splice($this->buckets, $index, 1);
        $this->buckets = array_values($this->buckets);
        if ($removedId !== '') {
            $this->dispatch('bucket-removed', id: $removedId);
        }
    }

    private function nextBucketName(): string
    {
        $taken = [];
        foreach ($this->buckets as $row) {
            $taken[strtolower((string) ($row['name'] ?? ''))] = true;
        }
        $org = auth()->user()?->currentOrganization();
        if ($org !== null) {
            foreach (CloudBucket::query()
                ->where('organization_id', $org->id)
                ->where('name', 'ilike', 'bucket-%')
                ->pluck('name') as $existing) {
                $taken[strtolower((string) $existing)] = true;
            }
        }
        $n = 1;
        while (isset($taken['bucket-'.$n])) {
            $n++;
        }

        return 'bucket-'.$n;
    }

    private function nextBucketPrefix(): string
    {
        $base = 'S3';
        $taken = [];
        foreach ($this->buckets as $row) {
            $taken[strtoupper((string) ($row['env_prefix'] ?? ''))] = true;
        }
        if (! isset($taken[$base])) {
            return $base;
        }
        $n = 2;
        while (isset($taken[$base.'_'.$n])) {
            $n++;
        }

        return $base.'_'.$n;
    }

    public function addDeployTask(string $trigger = CloudDeployTask::TRIGGER_PRE_DEPLOY): void
    {
        if (! in_array($trigger, array_keys(CloudDeployTask::DO_KIND_MAP), true)) {
            $trigger = CloudDeployTask::TRIGGER_PRE_DEPLOY;
        }

        // Empty command default for image mode (we don't know what's
        // in the user's image). In source mode we still leave it blank
        // — the first-class "Run migrations" field already covers the
        // common case and a blank command nudges deliberate input.
        $this->deploy_tasks[] = [
            'trigger' => $trigger,
            'name' => 'task-'.(count($this->deploy_tasks) + 1),
            'command' => '',
            'size' => 'small',
        ];
    }

    public function removeDeployTask(int $index): void
    {
        if (! isset($this->deploy_tasks[$index])) {
            return;
        }
        array_splice($this->deploy_tasks, $index, 1);
        $this->deploy_tasks = array_values($this->deploy_tasks);
    }
}
