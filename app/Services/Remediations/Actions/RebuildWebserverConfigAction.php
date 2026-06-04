<?php

declare(strict_types=1);

namespace App\Services\Remediations\Actions;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Remediations\RemediationActionInterface;

/**
 * Re-apply a site's nginx vhost — the fix for "the deploy succeeds but the site
 * 502s because requests fall through to the default server" (a missing/stale
 * vhost). Dispatches {@see ApplySiteWebserverConfigJob}, which regenerates the
 * server block from the site model and streams its own `webserver_config`
 * console run (visible on the site's Settings page).
 */
class RebuildWebserverConfigAction implements RemediationActionInterface
{
    public function apply(?Server $server, ?Site $site, ?string $userId, ConsoleEmitter $emit): ?string
    {
        if (! $site instanceof Site) {
            return 'This fix needs a site, but the error isn’t tied to one.';
        }

        $emit->step('fix', sprintf('Re-applying the nginx vhost for %s …', $site->name));
        ApplySiteWebserverConfigJob::dispatch((string) $site->id, $userId);
        $emit->success('fix', 'Webserver rebuild queued — the vhost re-applies in a moment. Re-check the site (and watch its Settings page for the apply log).');

        return null;
    }
}
