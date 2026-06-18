<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RedeployCloudSiteJob;
use App\Models\Site;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Console\Command;

/**
 * Push env vars to a deployed cloud site from the CLI.
 *
 *   dply:cloud:env <site> --file=.env.production
 *   dply:cloud:env <site> --set=KEY=value
 *
 * Designed for CI scripts that resolve secrets at deploy time
 * (e.g. fetching a token from Vault, injecting it into env)
 * without going through the dashboard. The supplied env content
 * is persisted onto the Site, the backend's deployment spec is
 * patched (DO App Platform / AWS App Runner), and a redeploy is
 * queued so the next roll picks up the new values.
 */
class CloudEnvCommand extends Command
{
    protected $signature = 'dply:cloud:env
        {site : Site ID, slug, or name}
        {--file= : Path to a .env-format file whose content replaces the site env}
        {--set=* : KEY=value pairs to merge into the existing env (repeatable)}
        {--build : Target the build-time env vars instead of runtime (DO BUILD_TIME / App Runner BuildEnvironmentVariables)}
        {--no-redeploy : Persist env vars only, do not queue a redeploy}';

    protected $description = 'Push env vars to a deployed cloud site (and queue a redeploy).';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if ($site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site.");

            return self::FAILURE;
        }

        $newContent = $this->resolveEnvContent($site);
        if ($newContent === null) {
            return self::FAILURE;
        }

        if ($this->option('build')) {
            $meta = $site->meta;
            $meta['container'] = array_merge($meta['container'] ?? [], [
                'build_env_file_content' => $newContent,
            ]);
            $site->update(['meta' => $meta]);
        } else {
            $site->update(['env_file_content' => $newContent]);
        }

        $backend = CloudRouter::backendFor($site->fresh());
        $credential = CloudRouter::credentialFor($site->fresh());
        if ($backend !== null && $credential !== null) {
            try {
                $backend->updateEnvVars($site->fresh(), $credential);
            } catch (\Throwable $e) {
                $this->error('Backend rejected env update: '.$e->getMessage());
                $this->info('Env content was saved locally — fix the backend issue and re-run.');

                return self::FAILURE;
            }
        }

        if (! $this->option('no-redeploy')) {
            RedeployCloudSiteJob::dispatch($site->id);
            $this->info(sprintf('Env vars saved and redeploy queued for %s.', $site->name));
        } else {
            $this->info(sprintf('Env vars saved for %s (no redeploy queued).', $site->name));
        }

        return self::SUCCESS;
    }

    private function resolveEnvContent(Site $site): ?string
    {
        $file = $this->option('file');
        $sets = $this->option('set');

        if (is_string($file) && $file !== '' && $sets !== []) {
            $this->error('Pass either --file or --set, not both.');

            return null;
        }

        if (is_string($file) && $file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                $this->error("File not readable: {$file}");

                return null;
            }

            return (string) file_get_contents($file);
        }

        if ($sets === []) {
            $this->error('Pass --file or one or more --set=KEY=value pairs.');

            return null;
        }

        // Merge the given KEY=value pairs onto the existing site env,
        // preserving order: existing keys are updated in-place; new
        // keys append at the end. Quoted values are passed through.
        // For --build, merge against the build-env content (stored
        // under meta.container.build_env_file_content) instead.
        if ($this->option('build')) {
            $existingContent = (string) ($site->meta['container']['build_env_file_content'] ?? '');
        } else {
            $existingContent = (string) $site->env_file_content;
        }
        $existingLines = preg_split('/\r?\n/', $existingContent) ?: [];
        $byKey = [];
        $orderedKeys = [];
        foreach ($existingLines as $line) {
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k] = explode('=', $line, 2);
            $byKey[trim($k)] = $line;
            $orderedKeys[] = trim($k);
        }

        foreach ($sets as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                $this->error("Bad --set value (expected KEY=value): {$pair}");

                return null;
            }
            [$k, $v] = explode('=', $pair, 2);
            $key = trim($k);
            if ($key === '') {
                $this->error("Empty key in --set: {$pair}");

                return null;
            }
            if (! isset($byKey[$key])) {
                $orderedKeys[] = $key;
            }
            $byKey[$key] = $key.'='.$v;
        }

        $body = '';
        foreach (array_unique($orderedKeys) as $key) {
            $body .= $byKey[$key]."\n";
        }

        return $body;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
