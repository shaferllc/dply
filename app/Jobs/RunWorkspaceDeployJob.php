<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\WorkspaceDeployRun;
use App\Services\Projects\WorkspaceHealthSummaryService;
use App\Services\Projects\WorkspaceNotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunWorkspaceDeployJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $workspaceDeployRunId) {}

    public function handle(
        WorkspaceNotificationDispatcher $notifications,
        WorkspaceHealthSummaryService $healthSummary
    ): void {
        $run = WorkspaceDeployRun::query()
            ->with(['workspace.organization', 'workspace.sites.deployments'])
            ->findOrFail($this->workspaceDeployRunId);

        $workspace = $run->workspace;
        $siteIds = array_values(array_filter($run->site_ids ?? [], fn (mixed $id): bool => is_string($id) && $id !== ''));

        $run->update([
            'status' => WorkspaceDeployRun::STATUS_RUNNING,
            'started_at' => now(),
            'output' => null,
        ]);

        $lines = [];
        $summary = [
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($siteIds as $siteId) {
            $site = Site::query()
                ->where('workspace_id', $workspace->id)
                ->find($siteId);

            if (! $site) {
                $summary['failed']++;
                $lines[] = 'Missing site: '.$siteId;

                continue;
            }

            try {
                RunSiteDeploymentJob::dispatchSync($site, SiteDeployment::TRIGGER_MANUAL);
                $latestDeployment = $site->deployments()->latest('created_at')->first();
                $status = $latestDeployment?->status ?? SiteDeployment::STATUS_SUCCESS;

                if ($status === SiteDeployment::STATUS_SUCCESS) {
                    $summary['successful']++;
                } elseif ($status === SiteDeployment::STATUS_SKIPPED) {
                    $summary['skipped']++;
                } else {
                    $summary['failed']++;
                }

                $lines[] = $site->name.': '.$status;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $lines[] = $site->name.': failed - '.$e->getMessage();
            }
        }

        $status = $summary['failed'] > 0 ? WorkspaceDeployRun::STATUS_FAILED : WorkspaceDeployRun::STATUS_SUCCESS;

        $run->update([
            'status' => $status,
            'result_summary' => $summary,
            'output' => implode("\n", $lines),
            'finished_at' => now(),
        ]);

        audit_log(
            $workspace->organization,
            $run->user,
            $status === WorkspaceDeployRun::STATUS_SUCCESS ? 'project.deploy.success' : 'project.deploy.failed',
            $workspace,
            null,
            [
                'workspace_deploy_run_id' => $run->id,
                'summary' => $summary,
            ]
        );

        $notifications->notify(
            $workspace,
            'project.deployments',
            '['.config('app.name').'] '.$workspace->name.' deploy '.($status === WorkspaceDeployRun::STATUS_SUCCESS ? 'completed' : 'needs attention'),
            implode("\n", array_filter([
                'Project: '.$workspace->name,
                'Successful: '.$summary['successful'],
                'Skipped: '.$summary['skipped'],
                'Failed: '.$summary['failed'],
            ])),
            route('projects.show', $workspace, absolute: true),
            __('Open project')
        );

        $health = $healthSummary->summarize($workspace->fresh(['servers', 'sites.deployments']));

        if (! $health['healthy']) {
            $notifications->notify(
                $workspace,
                'project.health',
                '['.config('app.name').'] '.$workspace->name.' health needs attention',
                implode("\n", array_merge([
                    'Project: '.$workspace->name,
                ], $health['issues'])),
                route('projects.show', $workspace, absolute: true),
                __('Open project')
            );
        }
    }
}
