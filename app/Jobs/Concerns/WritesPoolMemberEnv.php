<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvPusher;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Support\Facades\Log;

/**
 * Shared env-projection for worker-pool push jobs: merge a set of keys into a
 * member site's stored .env, and only when something actually changed, push the
 * file over SSH and restart the worker units so the new env is read.
 *
 * Idempotent by design — the enforced plumbing push runs on every reconcile, so
 * it must be a no-op (no restart) when the values already match the box.
 */
trait WritesPoolMemberEnv
{
    /**
     * @param  array<string, string>  $envVars
     * @return bool true if the member's env changed (and was pushed + restarted)
     */
    protected function applyEnvToMember(
        Site $site,
        array $envVars,
        DotEnvFileParser $parser,
        DotEnvFileWriter $writer,
        SiteEnvPusher $pusher,
        SiteSystemdProvisioner $provisioner,
        bool $restart = true,
    ): bool {
        $parsed = $parser->parse((string) ($site->env_file_content ?? ''));
        $variables = $parsed['variables'];

        $changed = false;
        foreach ($envVars as $key => $value) {
            if (($variables[$key] ?? null) !== (string) $value) {
                $variables[$key] = (string) $value;
                $changed = true;
            }
        }

        if (! $changed) {
            return false;
        }

        $site->forceFill(['env_file_content' => $writer->render($variables, $parsed['comments'])])->save();

        try {
            $pusher->push($site);
            if ($restart) {
                $provisioner->controlWorkerUnits($site, 'restart');
            }
        } catch (\Throwable $e) {
            Log::warning('WritesPoolMemberEnv: push/restart failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    protected function appSite(Server $member): ?Site
    {
        $sites = $member->sites()->get();

        return $sites->first(fn (Site $s): bool => $s->isLaravelFrameworkDetected()) ?? $sites->first();
    }
}
