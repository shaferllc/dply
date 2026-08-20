<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Deploy\Services\ServerlessDeployProgress;
use App\Modules\Edge\Services\EdgeSiteCanceller;
use App\Modules\Serverless\Actions\DeleteServerlessFunction;
use App\Services\Sites\SiteProvisioningCanceller;

/**
 * Cancel an in-progress serverless provision or deploy.
 *
 * Mirrors {@see EdgeSiteCanceller} and
 * {@see SiteProvisioningCanceller}: stop the in-flight
 * work, then tear down a function that never went live so it cannot keep
 * looking successful. A live function only aborts the current deploy.
 */
class ServerlessSiteCanceller
{
    public function __construct(
        private readonly DeleteServerlessFunction $delete,
        private readonly ServerlessDeployProgress $progress,
    ) {}

    /**
     * @return 'removed'|'aborted'
     */
    public function cancel(Site $site): string
    {
        $site->refresh();

        $deployment = SiteDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->latest('created_at')
            ->first();

        if ($deployment !== null) {
            $this->progress->requestCancel($site, $deployment->id);
            $this->markCancelled($deployment);
        }

        // Already answering requests — leave it up and only kill the redeploy.
        if ($site->status === Site::STATUS_FUNCTIONS_ACTIVE) {
            return 'aborted';
        }

        $this->delete->handle($site);

        return 'removed';
    }

    private function markCancelled(SiteDeployment $deployment): void
    {
        $existing = trim((string) ($deployment->log_output ?? ''));
        $note = 'Cancelled by operator.';
        $log = $existing === '' || str_contains(strtolower($existing), 'cancelled by operator')
            ? ($existing !== '' ? $existing : $note)
            : $existing."\n".$note;

        $deployment->update([
            'status' => SiteDeployment::STATUS_FAILED,
            'exit_code' => 1,
            'log_output' => $log,
            'finished_at' => $deployment->finished_at ?? now(),
        ]);
    }
}
