<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Deploy;

use App\Mcp\Concerns\SerializesDeployments;
use App\Mcp\Exceptions\DplyMcpException;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\SiteDeployment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetDeployment extends AbstractDplyTool
{
    use SerializesDeployments;

    protected string $name = 'get_deployment';

    protected string $description = 'Get a single deployment for a site, including its status, exit code, and full deploy log output.';

    protected string $ability = 'sites.read';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()
                ->description('The site id (or slug).')
                ->required(),
            'deployment_id' => $schema->string()
                ->description('The deployment id to fetch.')
                ->required(),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate([
            'site_id' => ['required', 'string'],
            'deployment_id' => ['required', 'string'],
        ]);

        $site = $this->resolveSite($data['site_id'], $organization);

        $deployment = SiteDeployment::query()
            ->where('site_id', $site->id)
            ->find($data['deployment_id']);

        if (! $deployment) {
            throw new DplyMcpException("Deployment \"{$data['deployment_id']}\" was not found for this site.");
        }

        return Response::json([
            'data' => $this->deploymentDetail($deployment),
        ]);
    }
}
