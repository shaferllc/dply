<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Actions\CreateCloudSite;
use App\Modules\Cloud\Jobs\RedeployCloudSiteJob;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Headless deploy of a container app onto the dply cloud platform.
 * Mirrors the /cloud/create UI as a CLI surface so operators can
 * automate from CI without clicking through the UI.
 *
 *   dply:cloud:deploy <name> --image=<ref> [--port=8080] [--region=nyc]
 *                          [--backend=auto|digitalocean_app_platform|aws_app_runner]
 *                          [--user=<email>] [--org=<slug-or-id>]
 *
 * If <name> matches an existing cloud site for the chosen org,
 * the command treats this as a redeploy — the new --image (if
 * different) is rolled via RedeployCloudSiteJob.
 */
class CloudDeployCommand extends Command
{
    protected $signature = 'dply:cloud:deploy
        {name : Container app name}
        {--image= : Container image reference (e.g. ghcr.io/acme/api:v1)}
        {--port=8080 : Port the container listens on}
        {--region= : Backend region slug (e.g. nyc, us-east-1)}
        {--backend=auto : auto, digitalocean_app_platform, or aws_app_runner}
        {--user= : User email (defaults to first user in the org)}
        {--org= : Organization name or ID (defaults to first org)}';

    protected $description = 'Deploy or redeploy a container app on the dply cloud platform.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $image = (string) ($this->option('image') ?? '');
        if ($image === '') {
            $this->error('--image is required.');

            return self::FAILURE;
        }

        $org = $this->resolveOrg();
        if ($org === null) {
            $this->error('Could not resolve organization. Pass --org=<slug-or-id> or create an org first.');

            return self::FAILURE;
        }

        $user = $this->resolveUser($org);
        if ($user === null) {
            $this->error('Could not resolve user. Pass --user=<email> or invite a member first.');

            return self::FAILURE;
        }

        $existing = Site::query()
            ->where('organization_id', $org->id)
            ->where('name', $name)
            ->whereNotNull('container_backend')
            ->first();

        if ($existing !== null) {
            $newImage = $image !== $existing->container_image ? $image : null;
            RedeployCloudSiteJob::dispatch($existing->id, $newImage);
            $this->info(sprintf(
                '%s redeploy queued for %s (%s).',
                $newImage ? 'Image-bump' : 'Same-image',
                $existing->name,
                $existing->container_backend,
            ));

            return self::SUCCESS;
        }

        try {
            $site = (new CreateCloudSite)->handle($user, $org, [
                'name' => $name,
                'image' => $image,
                'port' => (int) $this->option('port'),
                'region' => (string) ($this->option('region') ?? ''),
                'backend' => (string) $this->option('backend'),
                'env_file_content' => '',
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Container site created: %s (backend=%s, region=%s). Provisioning queued.',
            $site->name,
            $site->container_backend,
            $site->container_region,
        ));

        return self::SUCCESS;
    }

    private function resolveOrg(): ?Organization
    {
        $orgArg = $this->option('org');
        if (is_string($orgArg) && $orgArg !== '') {
            return Organization::query()
                ->where('id', $orgArg)
                ->orWhere('name', $orgArg)
                ->first();
        }

        return Organization::query()->orderBy('created_at')->first();
    }

    private function resolveUser(Organization $org): ?User
    {
        $userArg = $this->option('user');
        if (is_string($userArg) && $userArg !== '') {
            return User::query()->where('email', $userArg)->first();
        }

        return $org->users()->orderBy('created_at')->first();
    }
}
