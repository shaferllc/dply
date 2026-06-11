<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sites;

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\SiteProcess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListSiteWorkers extends AbstractDplyTool
{
    protected string $name = 'list_site_workers';

    protected string $description = 'List a site\'s long-running processes — queue workers, scheduler, and custom daemons — with their command, scale, working directory, and active state.';

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
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        ['site_id' => $siteId] = $request->validate([
            'site_id' => ['required', 'string'],
        ]);

        $site = $this->resolveSite($siteId, $organization);

        $processes = SiteProcess::query()
            ->where('site_id', $site->id)
            ->whereIn('type', [SiteProcess::TYPE_WORKER, SiteProcess::TYPE_SCHEDULER, SiteProcess::TYPE_CUSTOM])
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'command', 'scale', 'working_directory', 'user', 'is_active']);

        return Response::json([
            'data' => $processes->map(fn (SiteProcess $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'name' => $p->name,
                'command' => $p->command,
                'scale' => $p->scale,
                'working_directory' => $p->working_directory,
                'user' => $p->user,
                'is_active' => $p->is_active,
            ])->all(),
        ]);
    }
}
