<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sites;

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\ServerCronJob;
use App\Models\SiteDeploymentSchedule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListSiteSchedules extends AbstractDplyTool
{
    protected string $name = 'list_site_schedules';

    protected string $description = 'List a site\'s scheduled deployments and cron jobs, including cron expressions, branches, and last run times.';

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
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        ['site_id' => $siteId] = $request->validate([
            'site_id' => ['required', 'string'],
        ]);

        $site = $this->resolveSite($siteId, $organization);

        $deploySchedules = SiteDeploymentSchedule::query()
            ->where('site_id', $site->id)
            ->get(['id', 'cron_expression', 'timezone', 'git_branch', 'is_active', 'last_run_at']);

        $cronJobs = ServerCronJob::query()
            ->where('site_id', $site->id)
            ->get(['id', 'cron_expression', 'command', 'user', 'enabled', 'description', 'last_run_at']);

        return Response::json([
            'deploy_schedules' => $deploySchedules->map(fn (SiteDeploymentSchedule $s) => [
                'id' => $s->id,
                'type' => 'deploy',
                'cron_expression' => $s->cron_expression,
                'timezone' => $s->timezone,
                'git_branch' => $s->git_branch,
                'is_active' => $s->is_active,
                'last_run_at' => $s->last_run_at?->toIso8601String(),
            ])->all(),
            'cron_jobs' => $cronJobs->map(fn (ServerCronJob $j) => [
                'id' => $j->id,
                'type' => 'cron',
                'cron_expression' => $j->cron_expression,
                'command' => $j->command,
                'user' => $j->user,
                'enabled' => $j->enabled,
                'description' => $j->description,
                'last_run_at' => $j->last_run_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
