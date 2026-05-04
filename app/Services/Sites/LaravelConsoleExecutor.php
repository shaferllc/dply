<?php

namespace App\Services\Sites;

use App\Models\RemoteCliRun;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Services\Deploy\LocalDockerKubernetesRuntimeManager;
use App\Services\Deploy\LocalDockerRuntimeManager;
use App\Services\RemoteCli\Artisan as ArtisanService;
use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\SshConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Runs Laravel Artisan and inspects commands for VM (SSH) and local Orbstack Docker/Kubernetes sites.
 */
final class LaravelConsoleExecutor
{
    public function __construct(
        private readonly SiteScopedCommandWrapper $commandWrapper,
        private readonly LocalDockerRuntimeManager $localDockerRuntimeManager,
        private readonly LocalDockerKubernetesRuntimeManager $localKubernetesRuntimeManager,
        private readonly ArtisanService $artisanRiskClassifier,
        private readonly SiteAuditWriter $auditWriter,
    ) {}

    /**
     * vm_ssh | local_docker | local_k8s | unsupported
     */
    public function executionProfile(Site $site): string
    {
        if (! $this->isLaravelSite($site)) {
            return 'unsupported';
        }

        $family = $site->runtimeTargetFamily();

        if ($family === 'local_orbstack_docker') {
            return 'local_docker';
        }

        if ($family === 'local_orbstack_kubernetes') {
            return 'local_k8s';
        }

        if ($site->usesFunctionsRuntime()) {
            return 'unsupported';
        }

        if ($site->usesDockerRuntime() || $site->usesKubernetesRuntime()) {
            return 'unsupported';
        }

        if ($site->canRunLaravelSshSetupActions()) {
            return 'vm_ssh';
        }

        return 'unsupported';
    }

    public function isLaravelSite(Site $site): bool
    {
        return strtolower((string) ($site->resolvedRuntimeAppDetection()['framework'] ?? '')) === 'laravel';
    }

    /**
     * @return list<string>
     */
    public function customCommands(Site $site): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $raw = data_get($meta, 'laravel_console.custom_commands');

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $line) {
            if (is_string($line) && trim($line) !== '') {
                $out[] = trim($line);
            }
        }

        return array_values(array_unique($out));
    }

    public function assertSafeArtisanArgv(string $argvTail): void
    {
        $t = trim($argvTail);
        if ($t === '' || strlen($t) > 2000) {
            throw new \InvalidArgumentException(__('Command is empty or too long.'));
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $t)) {
            throw new \InvalidArgumentException(__('Command contains invalid characters.'));
        }

        if (preg_match('/[;&|`$()]/', $t)) {
            throw new \InvalidArgumentException(__('Shell metacharacters are not allowed in Artisan commands.'));
        }
    }

    public function isPresetCommand(string $trimmedArgvTail): bool
    {
        foreach (config('laravel_site_console.preset_categories', []) as $commands) {
            if (! is_array($commands)) {
                continue;
            }
            if (in_array($trimmedArgvTail, $commands, true)) {
                return true;
            }
        }

        return false;
    }

    public function assertRunnableCommand(Site $site, string $argvTail): void
    {
        $trim = trim($argvTail);
        $this->assertSafeArtisanArgv($trim);

        if ($this->isPresetCommand($trim)) {
            return;
        }

        foreach ($this->customCommands($site) as $custom) {
            if ($custom === $trim) {
                return;
            }
        }

        throw new \InvalidArgumentException(__('This command is not in the preset list or your saved custom commands.'));
    }

    /**
     * @param  callable(string): void  $onChunk
     */
    public function runArtisan(Site $site, string $argvTail, int $timeoutSeconds, callable $onChunk): int
    {
        $this->assertRunnableCommand($site, $argvTail);
        $trim = trim($argvTail);

        $profile = $this->executionProfile($site);

        // Capture output as the per-profile execution streams it back,
        // then fold that into a RemoteCliRun row + SiteAuditEvent so
        // the existing rich Custom commands tab gains the same audit
        // trail + run history that the WordPress Console / dply:artisan
        // umbrella commands have via the Artisan service. Execution
        // mechanics stay intact — Docker/K8s paths still take their
        // own routes; we just observe and record.
        $command = $this->commandVerb($trim);
        $args = $this->commandArgs($trim);
        $risk = $this->artisanRiskClassifier->classifyRisk($command);

        $captured = '';
        $wrappedOnChunk = function (string $chunk) use (&$captured, $onChunk): void {
            $captured .= $chunk;
            $onChunk($chunk);
        };

        $startedAt = now();
        $exitCode = match ($profile) {
            'vm_ssh' => $this->runArtisanVmSsh($site, $trim, $timeoutSeconds, $wrappedOnChunk),
            'local_docker' => $this->runArtisanLocalDocker($site, $trim, $timeoutSeconds, $wrappedOnChunk),
            'local_k8s' => $this->runArtisanLocalKubernetes($site, $trim, $timeoutSeconds, $wrappedOnChunk),
            default => throw new \RuntimeException(
                __('Laravel Artisan commands are not available for this runtime from the panel. Use SSH or your container tooling.')
            ),
        };

        $this->recordRun($site, $command, $args, $risk, $exitCode, $captured, $startedAt);

        return $exitCode;
    }

    /**
     * Extract the verb half of the artisan call. "migrate:rollback --step=1" → "migrate:rollback".
     * Mirrors how dply:artisan's umbrella does it so risk classification
     * is uniform across transports.
     */
    private function commandVerb(string $argvTail): string
    {
        $parts = preg_split('/\s+/', trim($argvTail), 2);

        return is_array($parts) && isset($parts[0]) ? (string) $parts[0] : '';
    }

    /**
     * @return list<string>
     */
    private function commandArgs(string $argvTail): array
    {
        $parts = preg_split('/\s+/', trim($argvTail));
        if (! is_array($parts) || count($parts) <= 1) {
            return [];
        }

        return array_values(array_slice($parts, 1));
    }

    private function recordRun(Site $site, string $command, array $args, RiskLevel $risk, int $exitCode, string $output, \Illuminate\Support\Carbon $startedAt): void
    {
        $finishedAt = now();
        $status = $exitCode === 0 ? RemoteCliRun::STATUS_COMPLETED : RemoteCliRun::STATUS_FAILED;

        $run = RemoteCliRun::query()->create([
            'site_id' => $site->getKey(),
            'kind' => Kind::Artisan,
            'command' => $command,
            'args' => $args,
            'risk' => $risk,
            // The legacy Custom commands tab streams over its own
            // SSH/Docker/K8s plumbing, NOT the RemoteCli sync path.
            // From the run-history viewer's perspective this is still
            // a synchronous interactive run, so 'sync' is the right
            // mode — both the WP Console + this surface render the
            // same way.
            'mode' => RemoteCliRun::MODE_SYNC,
            'status' => $status,
            'exit_code' => $exitCode,
            'stdout' => $output !== '' ? $output : null,
            'stderr' => null,
            'queued_by_user_id' => auth()->id(),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        // SiteAuditWriter filters Read commands itself, so we always
        // call it and let it decide whether to persist.
        $this->auditWriter->record(
            site: $site,
            user: auth()->user(),
            action: 'artisan_run',
            risk: $risk,
            transport: SiteAuditEvent::TRANSPORT_WEB,
            summary: trim('php artisan '.$command.' '.implode(' ', $args)),
            payload: [
                'command' => $command,
                'args' => $args,
                'exit_code' => $exitCode,
                'remote_cli_run_id' => $run->id,
            ],
            resultStatus: $exitCode === 0
                ? SiteAuditEvent::RESULT_SUCCESS
                : SiteAuditEvent::RESULT_FAILURE,
        );
    }

    /**
     * @return array{ok: bool, commands?: list<array{name: string, description?: string}>, raw?: string, error?: string|null}
     */
    public function listArtisanCommands(Site $site, bool $forceRefresh = false): array
    {
        if (! $this->isLaravelSite($site)) {
            return ['ok' => false, 'error' => __('This site is not detected as a Laravel application.')];
        }

        $profile = $this->executionProfile($site);
        if ($profile === 'unsupported') {
            return ['ok' => false, 'error' => __('Artisan discovery is not available for this runtime.')];
        }

        $cacheKey = $this->listCacheKey($site, $profile);
        $ttl = (int) config('laravel_site_console.list_cache_ttl_seconds', 3600);

        if (! $forceRefresh && $ttl > 0) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['ok'])) {
                return $cached;
            }
        }

        try {
            $json = $this->captureArtisanOutput($site, 'list --format=json', min(120, max(30, $ttl > 0 ? 120 : 300)));
            $parsed = json_decode($json, true);
            if (is_array($parsed) && isset($parsed['commands']) && is_array($parsed['commands'])) {
                $commands = [];
                foreach ($parsed['commands'] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $name = (string) ($row['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $commands[] = [
                        'name' => $name,
                        'description' => isset($row['description']) ? (string) $row['description'] : null,
                    ];
                }

                $out = ['ok' => true, 'commands' => $commands, 'error' => null];
                if ($ttl > 0) {
                    Cache::put($cacheKey, $out, $ttl);
                }

                return $out;
            }
        } catch (\Throwable) {
            // fall through to raw list
        }

        try {
            $raw = $this->captureArtisanOutput($site, 'list --raw', min(120, max(30, $ttl > 0 ? 120 : 300)));
            $lines = preg_split('/\R/', trim($raw)) ?: [];
            $commands = [];
            foreach ($lines as $line) {
                if (! is_string($line) || trim($line) === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', trim($line), 2);
                $name = (string) ($parts[0] ?? '');
                if ($name === '') {
                    continue;
                }
                $commands[] = [
                    'name' => $name,
                    'description' => isset($parts[1]) ? trim((string) $parts[1]) : null,
                ];
            }
            $out = ['ok' => true, 'commands' => $commands, 'raw' => $raw, 'error' => null];
            if ($ttl > 0) {
                Cache::put($cacheKey, $out, $ttl);
            }

            return $out;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function forgetListCache(Site $site): void
    {
        foreach (['vm_ssh', 'local_docker', 'local_k8s'] as $profile) {
            Cache::forget($this->listCacheKey($site, $profile));
        }
    }

    private function listCacheKey(Site $site, string $profile): string
    {
        $sha = (string) ($site->deployments()->orderByDesc('created_at')->orderByDesc('id')->value('git_sha') ?? 'none');

        return 'laravel_console:list:'.(string) $site->getKey().':'.$sha.':'.$profile;
    }

    private function captureArtisanOutput(Site $site, string $argvTail, int $timeoutSeconds): string
    {
        $profile = $this->executionProfile($site);

        return match ($profile) {
            'vm_ssh' => $this->captureArtisanVmSsh($site, $argvTail, $timeoutSeconds),
            'local_docker' => $this->captureArtisanLocalDockerShell($site, $argvTail, $timeoutSeconds),
            'local_k8s' => $this->captureArtisanLocalK8sArgv($site, $argvTail, $timeoutSeconds),
            default => throw new \RuntimeException(__('Artisan is not available for this runtime.')),
        };
    }

    private function captureArtisanVmSsh(Site $site, string $argvTail, int $timeoutSeconds): string
    {
        $dir = escapeshellarg($site->effectiveEnvDirectory());
        $rawCmd = 'cd '.$dir.' && php artisan '.$argvTail;
        $cmd = $this->commandWrapper->wrapRemoteExec($site, $rawCmd);
        $server = $site->server;
        if ($server === null) {
            throw new \RuntimeException(__('Server is not available.'));
        }
        $ssh = new SshConnection($server);

        return $ssh->exec($cmd, $timeoutSeconds);
    }

    private function captureArtisanLocalDockerShell(Site $site, string $argvTail, int $timeoutSeconds): string
    {
        $inner = 'cd /var/www/html && php artisan '.$argvTail;
        $buffer = '';
        $this->localDockerRuntimeManager->execInPrimaryContainer($site, $inner, $timeoutSeconds, function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });

        return $buffer;
    }

    /**
     * @return list<string>
     */
    private function argvTailToPhpArgv(string $argvTail): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($argvTail)) ?: [], fn ($t) => is_string($t) && $t !== ''));
    }

    private function captureArtisanLocalK8sArgv(Site $site, string $argvTail, int $timeoutSeconds): string
    {
        $argv = ['php', 'artisan', ...$this->argvTailToPhpArgv($argvTail)];
        $buffer = '';
        $this->localKubernetesRuntimeManager->execInDeploymentApp($site, $argv, $timeoutSeconds, function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });

        return $buffer;
    }

    /**
     * @param  callable(string): void  $onChunk
     */
    private function runArtisanVmSsh(Site $site, string $trim, int $timeoutSeconds, callable $onChunk): int
    {
        $dir = escapeshellarg($site->effectiveEnvDirectory());
        $rawCmd = 'cd '.$dir.' && php artisan '.$trim;
        $cmd = $this->commandWrapper->wrapRemoteExec($site, $rawCmd);
        $server = $site->server;
        if ($server === null) {
            throw new \RuntimeException(__('Server is not available.'));
        }
        $ssh = new SshConnection($server);
        $ssh->execWithCallback($cmd, $onChunk, $timeoutSeconds);
        $exit = $ssh->lastExecExitCode();

        return (int) ($exit ?? 0);
    }

    /**
     * @param  callable(string): void  $onChunk
     */
    private function runArtisanLocalDocker(Site $site, string $trim, int $timeoutSeconds, callable $onChunk): int
    {
        $inner = 'cd /var/www/html && php artisan '.$trim;

        return $this->localDockerRuntimeManager->execInPrimaryContainer($site, $inner, $timeoutSeconds, $onChunk);
    }

    /**
     * @param  callable(string): void  $onChunk
     */
    private function runArtisanLocalKubernetes(Site $site, string $trim, int $timeoutSeconds, callable $onChunk): int
    {
        $argv = ['php', 'artisan', ...$this->argvTailToPhpArgv($trim)];

        return $this->localKubernetesRuntimeManager->execInDeploymentApp($site, $argv, $timeoutSeconds, $onChunk);
    }

    /**
     * Tail Laravel application log (VM: file on host; local Docker: same path as runtime diagnostics).
     *
     * @param  callable(string): void  $onChunk
     */
    public function tailLaravelLog(Site $site, int $lines, callable $onChunk): int
    {
        $lines = max(10, min(5000, $lines));
        $profile = $this->executionProfile($site);

        if ($profile === 'vm_ssh') {
            $path = escapeshellarg($site->effectiveEnvDirectory().'/storage/logs/laravel.log');
            $rawCmd = 'tail -n '.$lines.' '.$path.' 2>/dev/null || echo '.escapeshellarg(__('Log file not found.'));
            $cmd = $this->commandWrapper->wrapRemoteExec($site, $rawCmd);
            $server = $site->server;
            if ($server === null) {
                throw new \RuntimeException(__('Server is not available.'));
            }
            $ssh = new SshConnection($server);
            $ssh->execWithCallback($cmd, $onChunk, 120);

            return $ssh->lastExecExitCode() ?? 0;
        }

        if ($profile === 'local_docker') {
            $inner = 'test -f /var/www/html/storage/logs/laravel.log && tail -n '.$lines.' /var/www/html/storage/logs/laravel.log || echo '.escapeshellarg(__('Log file not found.'));

            return $this->localDockerRuntimeManager->execInPrimaryContainer($site, $inner, 120, $onChunk);
        }

        if ($profile === 'local_k8s') {
            $argv = [
                'sh', '-lc',
                'test -f /var/www/html/storage/logs/laravel.log && tail -n '.$lines.' /var/www/html/storage/logs/laravel.log || echo '.escapeshellarg(__('Log file not found.')),
            ];

            return $this->localKubernetesRuntimeManager->execInDeploymentApp($site, $argv, 120, $onChunk);
        }

        throw new \RuntimeException(__('Laravel log tail is not available for this runtime.'));
    }
}
