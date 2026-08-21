<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Modules\Cloud\Jobs\TeardownCloudSiteJob;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Serverless\Actions\DeleteServerlessFunction;

/**
 * Routes a site delete through its platform's teardown so the provider-side
 * compute goes with the row.
 *
 * Cloud / Edge / Serverless each already own a correct teardown, but every one
 * of them was reachable only from its own danger-zone button. The two *generic*
 * delete paths — the Sites workspace "Remove now" modal and the scheduled
 * deletion command — called `$site->delete()` directly, which drops the row and
 * leaves the DO App / Worker / Lambda namespace running and billing. This is
 * the single chokepoint both now go through.
 *
 * The teardowns need the live Site row (backend resolution, meta, relations),
 * so this deliberately does NOT hang off `Site::deleting` — by then the row is
 * already on its way out and a queued job would find nothing. Callers ask here
 * *instead of* deleting, and only delete themselves when the answer is false.
 */
class SiteTeardownRouter
{
    public function __construct(
        private readonly DeleteServerlessFunction $deleteFunction,
    ) {}

    /**
     * Hand the site to its platform teardown, which owns the row delete.
     *
     * @return bool true when a platform took it — the caller must NOT call
     *              `$site->delete()`. False for VM/static/custom sites, whose
     *              cleanup the `Site::deleting` hook already covers.
     */
    public function teardown(Site $site): bool
    {
        // Edge first: `edge_backend` is the narrowest marker, and an edge site
        // never carries a container backend.
        if ($site->usesEdgeRuntime()) {
            TeardownEdgeSiteJob::dispatch((string) $site->getKey());

            return true;
        }

        if ($site->usesContainerRuntime()) {
            TeardownCloudSiteJob::dispatch((string) $site->getKey(), deleteSiteRow: true);

            return true;
        }

        if ($site->server?->hostCapabilities()->supportsFunctionDeploy() === true) {
            // Synchronous — same as the Serverless workspace's own delete. It
            // is a provider HTTP call, not SSH, so it is safe in the request
            // path, and it reports namespace failures to the operator inline.
            $this->deleteFunction->handle($site);

            return true;
        }

        return false;
    }
}
