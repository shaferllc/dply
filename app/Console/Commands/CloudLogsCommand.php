<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Modules\Cloud\Backends\CloudBackend;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Console\Command;

/**
 * Fetch logs for an cloud site — the latest deployment (BUILD/DEPLOY)
 * logs by default, or runtime (RUN) logs with --run.
 *
 *   dply:cloud:logs <site> [--run] [--lines=200]
 *
 * Backend behavior varies:
 *   - DigitalOcean App Platform returns a presigned URL the
 *     operator can curl / open in a browser; --run fetches the RUN
 *     log archive and prints the tail inline.
 *   - AWS App Runner streams logs to CloudWatch — we surface the
 *     LogGroup name / console link so the operator can open the AWS
 *     console.
 *   - FakeCloudBackend returns synthetic inline content for tests
 *     and dev installs.
 */
class CloudLogsCommand extends Command
{
    protected $signature = 'dply:cloud:logs
        {site : Site ID, slug, or name}
        {--run : Fetch runtime (RUN) logs instead of the latest deployment logs}
        {--lines=200 : With --run, how many trailing log lines to print (1-2000)}';

    protected $description = 'Fetch deployment or runtime logs for an cloud site.';

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

        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            $this->error('No backend or credential resolvable for this site.');

            return self::FAILURE;
        }

        if ($this->option('run')) {
            return $this->handleRuntimeLogs($site, $backend, $credential);
        }

        try {
            $logs = $backend->latestDeploymentLogs($site, $credential);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch logs: '.$e->getMessage());

            return self::FAILURE;
        }

        if (is_string($logs['content'] ?? null) && $logs['content'] !== '') {
            $this->line($logs['content']);

            return self::SUCCESS;
        }

        if (is_string($logs['url'] ?? null) && $logs['url'] !== '') {
            $this->line('Latest deployment logs available at:');
            $this->line($logs['url']);

            return self::SUCCESS;
        }

        if (is_string($logs['message'] ?? null) && $logs['message'] !== '') {
            $this->line($logs['message']);

            return self::SUCCESS;
        }

        $this->line('<fg=gray>No logs returned by backend.</>');

        return self::SUCCESS;
    }

    /**
     * Fetch and print RUN (runtime) logs — the --run path.
     */
    private function handleRuntimeLogs(Site $site, CloudBackend $backend, ProviderCredential $credential): int
    {
        $linesRaw = $this->option('lines');
        $lines = is_string($linesRaw) && ctype_digit($linesRaw) ? (int) $linesRaw : 200;
        $lines = max(1, min(2000, $lines));

        try {
            $result = $backend->runtimeLogs($site, $credential, $lines);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch runtime logs: '.$e->getMessage());

            return self::FAILURE;
        }

        $logLines = $result['lines'];
        if ($logLines !== []) {
            foreach ($logLines as $line) {
                $this->line((string) $line);
            }
        }

        if (! $result['available']) {
            if (isset($result['note']) && $result['note'] !== '') {
                $this->line($result['note']);
            }
        } elseif ($logLines === []) {
            if (isset($result['note']) && $result['note'] !== '') {
                $this->line('<fg=gray>'.$result['note'].'</>');
            } else {
                $this->line('<fg=gray>No runtime log lines returned by backend.</>');
            }
        }

        if (isset($result['url']) && $result['url'] !== '') {
            $this->newLine();
            $this->line('Runtime logs / console:');
            $this->line($result['url']);
        }

        return self::SUCCESS;
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
