<?php

declare(strict_types=1);

namespace App\Modules\Queue\Console;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Create a DigitalOcean droplet to become a queue fleet host.
 *
 * The same row and job as the Create server page, so the host is an ordinary
 * dply server — SSH, firewall, metrics — that then opts in with
 * `dply:queue:fleet-host`. `--near` copies region and VPC from the server the
 * app's database lives on: fleets reach backends over the private network only,
 * and a droplet in another region or VPC has none.
 */
class FleetHostCreateCommand extends Command
{
    protected $signature = 'dply:queue:fleet-host-create
        {name : Name for the new server}
        {--credential= : DigitalOcean provider credential id}
        {--near= : Server id or name to share a region and VPC with}
        {--region= : DigitalOcean region, when --near is not given}
        {--size=s-2vcpu-4gb : Droplet size slug}';

    protected $description = 'Create a DigitalOcean droplet to become a queue fleet host.';

    public function handle(): int
    {
        $credential = ProviderCredential::query()
            ->where('provider', 'digitalocean')
            ->find((string) $this->option('credential'));

        if (! $credential instanceof ProviderCredential) {
            $this->components->error('--credential must be a DigitalOcean provider credential id.');

            return self::FAILURE;
        }

        $near = null;
        if (filled($this->option('near'))) {
            $needle = (string) $this->option('near');
            $near = Server::query()->whereKey($needle)->first() ?? Server::query()->where('name', $needle)->first();

            if (! $near instanceof Server) {
                $this->components->error('No server matched --near "'.$needle.'".');

                return self::FAILURE;
            }
        }

        $region = (string) ($near?->region ?: $this->option('region'));
        if ($region === '') {
            $this->components->error('Give --near or --region.');

            return self::FAILURE;
        }

        $owner = $credential->organization?->users()->wherePivot('role', 'owner')->first();
        if (! $owner instanceof User) {
            $this->components->error('The credential’s organization has no owner to create the server as.');

            return self::FAILURE;
        }

        $server = $owner->servers()->create([
            'organization_id' => $credential->organization_id,
            'name' => (string) $this->argument('name'),
            'provider' => ServerProvider::DigitalOcean,
            'provider_credential_id' => $credential->id,
            'region' => $region,
            'size' => (string) $this->option('size'),
            'status' => Server::STATUS_PENDING,
            'meta' => ['digitalocean' => [
                'monitoring' => true,
                'vpc_uuid' => data_get($near?->meta, 'digitalocean.vpc_uuid'),
                'tags' => ['dply-queue-fleet-host'],
            ]],
        ]);

        ProvisionDigitalOceanDropletJob::dispatch($server);
        audit_log($credential->organization, $owner, 'server.created', $server);

        $this->components->info($server->name.' is provisioning in '.$region.'.');
        $this->line('  When it is ready: php artisan dply:queue:fleet-host '.$server->name.' --capacity=3072');

        return self::SUCCESS;
    }
}
