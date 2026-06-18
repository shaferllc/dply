<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Deploy;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\SiteDeployment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class DeploySite extends AbstractDplyTool
{
    protected string $name = 'deploy_site';

    protected string $description = <<<'TXT'
        Queue a deployment of a site from its configured git repository. The deploy
        runs asynchronously — this returns immediately. Poll `list_deployments`
        (or `get_deployment` with the id it returns) until status is `success` or
        `failed`. Pass an `idempotency_key` to make retries safe: a repeat call with
        the same key while a deploy is in flight is rejected instead of double-deploying.
        TXT;

    protected string $ability = 'sites.deploy';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()
                ->description('The site id (or slug) to deploy.')
                ->required(),
            'idempotency_key' => $schema->string()
                ->description('Optional caller-supplied key to dedupe retries (max 128 chars).'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate([
            'site_id' => ['required', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $site = $this->resolveSite($data['site_id'], $organization);

        if (blank($site->git_repository_url)) {
            return Response::error('Configure a Git repository URL for this site before deploying.');
        }

        // Same idempotency contract as the REST API (SiteController::deploy):
        // a repeat call with the same key while a deploy is in flight is rejected.
        $idemHash = null;
        if (filled($data['idempotency_key'] ?? null)) {
            $idemHash = sha1($site->id.'|'.Str::limit($data['idempotency_key'], 128));
            if (! Cache::add('api-deploy-inflight:'.$idemHash, 1, 120)) {
                return Response::error('A deploy is already in progress for this idempotency key.');
            }
        }

        try {
            RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_API, $idemHash, $this->token()->user_id);
        } catch (\Throwable $e) {
            if ($idemHash) {
                Cache::forget('api-deploy-inflight:'.$idemHash);
            }
            throw $e;
        }

        return Response::json([
            'status' => 'queued',
            'message' => 'Deployment queued. Poll list_deployments for this site to track it.',
            'site_id' => $site->id,
            'poll_with' => 'list_deployments',
        ]);
    }
}
