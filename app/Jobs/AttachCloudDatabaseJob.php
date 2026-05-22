<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CloudDatabase;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Attaches (or detaches) a managed database to a Cloud site.
 *
 * On attach, the database's connection env vars (DB_* / REDIS_*) are
 * merged into the site's `env_file_content`, the env is pushed to the
 * backend, and a redeploy is queued so the new connection takes effect.
 * On detach, exactly those keys are removed and the site is redeployed.
 *
 * Idempotent — re-running an attach overwrites the same keys; re-running
 * a detach is a no-op once the keys are gone.
 */
class AttachCloudDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $cloudDatabaseId,
        public string $siteId,
        public bool $detach = false,
    ) {}

    public function handle(): void
    {
        $database = CloudDatabase::query()->find($this->cloudDatabaseId);
        $site = Site::query()->find($this->siteId);
        if ($database === null || $site === null) {
            return;
        }

        $vars = $this->parseEnvLines((string) ($site->env_file_content ?? ''));

        if ($this->detach) {
            foreach ($database->connectionEnvKeys() as $key) {
                unset($vars[$key]);
            }
            $database->sites()->detach($site->id);
        } else {
            foreach ($database->connectionEnvVars() as $key => $value) {
                $vars[$key] = $value;
            }
            $database->sites()->syncWithoutDetaching([$site->id]);
        }

        $site->update(['env_file_content' => $this->serializeEnvLines($vars)]);

        // Push the new env to the backend, then roll a deploy so the
        // container picks it up. updateEnvVars + redeploy both no-op
        // gracefully when the site has not been provisioned yet.
        $fresh = $site->fresh() ?? $site;
        $backend = CloudRouter::backendFor($fresh);
        $credential = CloudRouter::credentialFor($fresh);
        if ($backend !== null && $credential !== null) {
            $backend->updateEnvVars($fresh, $credential);
        }

        RedeployCloudSiteJob::dispatch($site->id);
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvLines(string $envContent): array
    {
        if ($envContent === '') {
            return [];
        }
        $vars = [];
        foreach (explode("\n", $envContent) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1), " \t\"'");
            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function serializeEnvLines(array $vars): string
    {
        $lines = [];
        foreach ($vars as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        return implode("\n", $lines);
    }
}
