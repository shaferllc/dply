<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Deploy;

use App\Mcp\Concerns\SerializesDeployments;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\SiteDeployment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListDeployments extends AbstractDplyTool
{
    use SerializesDeployments;

    protected string $name = 'list_deployments';

    protected string $description = 'List recent deployments for a site (newest first) with status, git sha, exit code, and timestamps. Use this to track a deploy queued by deploy_site.';

    protected string $ability = 'sites.read';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()
                ->description('The site id (or slug).')
                ->required(),
            'limit' => $schema->integer()
                ->description('How many deployments to return (1-50, default 20).'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $data = $request->validate([
            'site_id' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $site = $this->resolveSite($data['site_id'], $organization);
        $limit = (int) ($data['limit'] ?? 20);

        $rows = SiteDeployment::query()
            ->where('site_id', $site->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return Response::json([
            'data' => $rows->map(fn (SiteDeployment $d) => $this->deploymentSummary($d))->all(),
        ]);
    }
}
