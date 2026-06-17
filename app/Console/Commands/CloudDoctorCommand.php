<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use App\Support\Cloud\ContainerActivityTimeline;
use Illuminate\Console\Command;

/**
 * Per-cloud-site diagnostic snapshot.
 *
 *   dply:cloud:doctor <site> [--json] [--probe]
 *
 * Reports everything dply already knows from local state:
 *   - Resolved backend + ProviderCredential (via CloudRouter).
 *   - container_image / container_region / container_backend_id /
 *     containerLiveUrl() and the recorded last_phase / last_error.
 *   - The activity timeline (provisioned, redeploy, polls, errors,
 *     domains, teardown).
 *   - Domains attached on the backend, pulled from meta.container.domains.
 *   - Drift / health checks (unknown backend, missing credential,
 *     status / live_url mismatch, recent backend errors, stale polls).
 *
 * --probe additionally calls the backend's inspect() and surfaces
 * the live phase + URL alongside the locally cached values. Without
 * --probe the command does no network I/O — safe to run in scripts
 * against many sites at once.
 */
class CloudDoctorCommand extends Command
{
    protected $signature = 'dply:cloud:doctor
        {site : Site ID, slug, or name}
        {--json : Output the diagnostic as JSON}
        {--probe : Call backend inspect() for live phase + URL (network I/O)}';

    protected $description = 'Diagnostic: backend, credential, status, timeline, and drift for a single cloud site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if ($site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site (no container_backend).");

            return self::FAILURE;
        }

        $report = $this->compileReport($site);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($site, $report);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function compileReport(Site $site): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];

        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);

        $drift = [];
        if ($backend === null) {
            $drift[] = sprintf(
                'Unknown backend "%s" — not in CloudRouter map. Site cannot be reconciled.',
                (string) $site->container_backend,
            );
        }
        if ($credential === null) {
            $drift[] = sprintf(
                'No ProviderCredential connected for "%s" in this organization. Provisioning and polling will fail.',
                (string) $site->container_backend,
            );
        }

        $liveUrl = $site->containerLiveUrl();
        if ($site->status === Site::STATUS_CONTAINER_ACTIVE && $liveUrl === '') {
            $drift[] = 'Status is container_active but no live URL is recorded — backend may be ingressless or meta is stale.';
        }
        if ($site->status === Site::STATUS_CONTAINER_ACTIVE && empty($container['backend_id'])) {
            $drift[] = 'Status is container_active but no backend_id is recorded — re-provision may be required.';
        }

        if (! empty($container['last_error_at'])) {
            $drift[] = sprintf(
                'Backend reported an error at %s: %s',
                (string) $container['last_error_at'],
                is_string($container['last_error'] ?? null) ? (string) $container['last_error'] : 'no message recorded',
            );
        }
        if (! empty($container['last_poll_error'])) {
            $drift[] = sprintf(
                'Last status poll failed: %s',
                (string) $container['last_poll_error'],
            );
        }

        $probe = null;
        if ($this->option('probe') && $backend !== null && $credential !== null) {
            try {
                $probe = $backend->inspect($site, $credential);
            } catch (\Throwable $e) {
                $probe = ['error' => $e->getMessage()];
                $drift[] = 'Backend inspect() failed: '.$e->getMessage();
            }
        }

        $domains = [];
        if (is_array($container['domains'] ?? null)) {
            foreach ($container['domains'] as $hostname => $info) {
                $domains[] = [
                    'hostname' => (string) $hostname,
                    'attached_at' => is_array($info) && is_string($info['attached_at'] ?? null) ? $info['attached_at'] : null,
                    'status' => is_array($info) && is_string($info['status'] ?? null) ? $info['status'] : null,
                ];
            }
        }

        $timeline = array_map(static function (array $event): array {
            return [
                'at' => $event['at']?->toIso8601String(),
                'kind' => $event['kind'],
                'label' => $event['label'],
                'detail' => $event['detail'],
            ];
        }, ContainerActivityTimeline::for($site));

        return [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'organization_id' => $site->organization_id,
            'status' => $site->status,
            'backend' => [
                'key' => $site->container_backend,
                'class' => $backend !== null ? $backend::class : null,
                'region' => $site->container_region,
                'backend_id' => $container['backend_id'] ?? null,
            ],
            'credential' => $credential === null ? null : [
                'id' => $credential->id,
                'name' => $credential->name,
                'provider' => $credential->provider,
            ],
            'mode' => is_array($container['source'] ?? null) ? 'source' : 'image',
            'env' => [
                'runtime_set' => $site->env_file_content !== '',
                'build_set' => is_string($container['build_env_file_content'] ?? null) && $container['build_env_file_content'] !== '',
            ],
            'image' => [
                'current' => $site->container_image,
                'port' => $site->container_port,
            ],
            'scale' => [
                'instances' => is_int($container['instance_count'] ?? null) ? (int) $container['instance_count'] : 1,
                'size_tier' => is_string($container['size_tier'] ?? null) ? (string) $container['size_tier'] : 'small',
            ],
            'github_webhook_url' => is_array($container['source'] ?? null) ? $site->cloudGithubHookUrl() : null,
            'source' => is_array($container['source'] ?? null) ? [
                'repo' => $container['source']['repo'] ?? null,
                'branch' => $container['source']['branch'] ?? null,
                'dockerfile_path' => $container['source']['dockerfile_path'] ?? null,
                'deploy_on_push' => $container['source']['deploy_on_push'] ?? null,
            ] : null,
            'live' => [
                'url' => $liveUrl,
                'last_phase' => is_string($container['last_phase'] ?? null) ? $container['last_phase'] : null,
                'last_poll_at' => is_string($container['last_poll_at'] ?? null) ? $container['last_poll_at'] : null,
                'last_error' => is_string($container['last_error'] ?? null) ? $container['last_error'] : null,
                'last_error_at' => is_string($container['last_error_at'] ?? null) ? $container['last_error_at'] : null,
            ],
            'probe' => $probe,
            'domains' => $domains,
            'timeline' => $timeline,
            'drift' => $drift,
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function renderHuman(Site $site, array $r): void
    {
        $this->newLine();
        $this->line("<fg=cyan>Cloud doctor for</> <fg=white;options=bold>{$site->name}</> <fg=gray>({$site->id})</>");
        $this->line(sprintf('  status: %s', $r['status']));
        $this->newLine();

        $b = $r['backend'];
        $this->line('<fg=cyan>Backend</>');
        $this->line(sprintf('  %-14s %s', 'key', $b['key'] ?? '<fg=yellow>unset</>'));
        $this->line(sprintf('  %-14s %s', 'class', $b['class'] ?? '<fg=red>unresolved</>'));
        $this->line(sprintf('  %-14s %s', 'region', $b['region'] ?? '—'));
        $this->line(sprintf('  %-14s %s', 'backend_id', $b['backend_id'] ?? '—'));

        $this->newLine();
        $this->line('<fg=cyan>Credential</>');
        if ($r['credential'] === null) {
            $this->line('  <fg=red>None connected.</>');
        } else {
            $this->line(sprintf('  %-14s %s', 'name', $r['credential']['name']));
            $this->line(sprintf('  %-14s %s', 'provider', $r['credential']['provider']));
        }

        $this->newLine();
        if (($r['mode'] ?? 'image') === 'source' && is_array($r['source'] ?? null)) {
            $this->line('<fg=cyan>Source (build on backend)</>');
            $this->line(sprintf('  %-14s %s', 'repo', $r['source']['repo'] ?? '—'));
            $this->line(sprintf('  %-14s %s', 'branch', $r['source']['branch'] ?? '—'));
            $this->line(sprintf('  %-14s %s', 'dockerfile', $r['source']['dockerfile_path'] ?? '<fg=gray>auto-detect</>'));
            $this->line(sprintf('  %-14s %s', 'auto-deploy', ($r['source']['deploy_on_push'] ?? true) ? 'on push' : 'manual only'));
            $this->line(sprintf('  %-14s %s', 'port', $r['image']['port'] ?? '—'));
        } else {
            $this->line('<fg=cyan>Image</>');
            $this->line(sprintf('  %-14s %s', 'image', $r['image']['current'] ?? '—'));
            $this->line(sprintf('  %-14s %s', 'port', $r['image']['port'] ?? '—'));
        }

        $this->newLine();
        $this->line('<fg=cyan>Scale</>');
        $this->line(sprintf('  %-14s %s', 'instances', (string) ($r['scale']['instances'] ?? 1)));
        $this->line(sprintf('  %-14s %s', 'size_tier', (string) ($r['scale']['size_tier'] ?? 'small')));

        if (! empty($r['github_webhook_url'])) {
            $this->newLine();
            $this->line('<fg=cyan>GitHub webhook</>');
            $this->line(sprintf('  %s', $r['github_webhook_url']));
            $this->line('  <fg=gray>Paste this URL + the site\'s webhook secret into the repo\'s GitHub webhook settings.</>');
        }

        $this->newLine();
        $this->line('<fg=cyan>Live</>');
        $this->line(sprintf('  %-14s %s', 'url', $r['live']['url'] ?? '—'));
        $this->line(sprintf('  %-14s %s', 'last_phase', $r['live']['last_phase'] ?? '—'));
        $this->line(sprintf('  %-14s %s', 'last_poll', $r['live']['last_poll_at'] ?? '—'));
        if ($r['live']['last_error'] !== null) {
            $this->line(sprintf('  %-14s <fg=red>%s</>', 'last_error', $r['live']['last_error']));
            $this->line(sprintf('  %-14s %s', 'errored_at', $r['live']['last_error_at'] ?? '—'));
        }

        if ($r['probe'] !== null) {
            $this->newLine();
            $this->line('<fg=cyan>Probe (live inspect)</>');
            if (isset($r['probe']['error'])) {
                $this->line(sprintf('  <fg=red>error</> %s', $r['probe']['error']));
            } else {
                $this->line(sprintf('  %-14s %s', 'phase', $r['probe']['phase'] ?? '—'));
                $this->line(sprintf('  %-14s %s', 'live_url', $r['probe']['live_url'] ?? '—'));
            }
        }

        $this->newLine();
        $this->line('<fg=cyan>Domains</>');
        if ($r['domains'] === []) {
            $this->line('  <fg=gray>None attached.</>');
        } else {
            foreach ($r['domains'] as $d) {
                $this->line(sprintf(
                    '  %s  %s  %s',
                    $d['hostname'],
                    $d['status'] ?? '—',
                    $d['attached_at'] ?? '—',
                ));
            }
        }

        $this->newLine();
        $this->line('<fg=cyan>Recent activity</>');
        if ($r['timeline'] === []) {
            $this->line('  <fg=gray>No events recorded.</>');
        } else {
            foreach (array_slice($r['timeline'], 0, 8) as $event) {
                $this->line(sprintf(
                    '  %s  %s%s',
                    $event['at'] ?? '—',
                    $event['label'],
                    $event['detail'] !== null ? ' — '.$event['detail'] : '',
                ));
            }
        }

        $this->newLine();
        if ($r['drift'] === []) {
            $this->info('No drift detected.');
        } else {
            $this->warn('Drift:');
            foreach ($r['drift'] as $d) {
                $this->line('  '.$d);
            }
        }
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
