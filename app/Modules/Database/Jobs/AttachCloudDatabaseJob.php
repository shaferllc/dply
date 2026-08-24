<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Models\CloudDatabase;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Attaches (or detaches) a managed database to a site.
 *
 * On attach, the database's connection env vars (DB_* / REDIS_*) are merged
 * into the site's `env_file_content`; on detach, exactly those keys are
 * removed. The site picks the change up on its next deploy.
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
        $site = Site::find($this->siteId);
        if ($database === null || $site === null) {
            return;
        }

        $vars = $this->parseEnvLines((string) ($site->env_file_content ?? ''));

        // Each pivot row carries its own env_prefix — that's what makes
        // multi-database attachments possible without env-var collisions.
        // On attach we read the pivot that ApplyCloudSiteExtras already
        // wrote; on detach we read it before removing the row, so we
        // know which exact keys to strip.
        $pivotPrefix = function () use ($database, $site): ?string {
            $row = $database->sites()
                ->wherePivot('site_id', $site->id)
                ->first();

            return $row?->pivot?->env_prefix;
        };

        if ($this->detach) {
            $prefix = $pivotPrefix();
            foreach ($database->connectionEnvKeys($prefix) as $key) {
                unset($vars[$key]);
            }
            $database->sites()->detach($site->id);
        } else {
            $prefix = $pivotPrefix();
            foreach ($database->connectionEnvVars($prefix) as $key => $value) {
                $vars[$key] = $value;
            }
            // syncWithoutDetaching with no pivot data preserves whatever
            // env_prefix is already on the row; the caller (ApplyCloudSiteExtras
            // or attach-extras flow) is responsible for writing the prefix.
            $database->sites()->syncWithoutDetaching([$site->id]);
        }

        $site->update(['env_file_content' => $this->serializeEnvLines($vars)]);

        // The env file is the contract: whatever runs the site picks the new
        // keys up on its next deploy. Pushing env to a managed backend used to
        // happen here for Cloud sites; that product moved out of this app.
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
