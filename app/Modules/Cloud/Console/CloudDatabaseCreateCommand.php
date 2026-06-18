<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Actions\CreateCloudDatabase;
use App\Models\CloudDatabase;
use App\Models\Organization;
use Illuminate\Console\Command;
use Throwable;

/**
 * Create a managed database on the dply cloud platform.
 *
 *   dply:cloud:db:create --engine=postgres --engine-version=16 --size=small \
 *       --region=nyc1 --name=acme-db [--org=<id|name>]
 *
 * Creates a CloudDatabase row (status provisioning) and queues
 * ProvisionCloudDatabaseJob, which provisions the DigitalOcean Managed
 * Database cluster and polls it until online.
 */
class CloudDatabaseCreateCommand extends Command
{
    protected $signature = 'dply:cloud:db:create
        {--name= : Database name}
        {--engine=postgres : Engine — postgres, mysql, or redis}
        {--engine-version= : Engine version (e.g. 16 for Postgres)}
        {--size=small : Size tier — small, medium, or large}
        {--region=nyc1 : Provider region slug}
        {--org= : Organization ID or name (defaults to the only org)}';

    protected $description = 'Create a managed database on the dply cloud platform.';

    public function handle(): int
    {
        $organization = $this->resolveOrganization();
        if ($organization === null) {
            return self::FAILURE;
        }

        try {
            $database = (new CreateCloudDatabase)->handle($organization, [
                'name' => (string) $this->option('name'),
                'engine' => (string) $this->option('engine'),
                'version' => (string) $this->option('engine-version'),
                'size' => (string) $this->option('size'),
                'region' => (string) $this->option('region'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Managed database "%s" (%s) queued for provisioning [%s].',
            $database->name,
            $database->engine,
            $database->id,
        ));

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $needle = trim((string) $this->option('org'));
        if ($needle !== '') {
            $org = Organization::query()
                ->where('id', $needle)
                ->orWhere('name', $needle)
                ->first();
            if ($org === null) {
                $this->error("Organization not found: {$needle}");
            }

            return $org;
        }

        $orgs = Organization::query()->limit(2)->get();
        if ($orgs->count() === 1) {
            return $orgs->first();
        }

        $this->error($orgs->isEmpty()
            ? 'No organizations exist.'
            : 'Multiple organizations exist — pass --org=<id|name>.');

        return null;
    }
}
