<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Services\Servers\ServerAptLockBash;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Support\Servers\ServerPhpMutationLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Per-extension orchestration, the sibling of {@see RunsPhpPackageActions}.
 *
 * Actions split by how long they can run, not by kind:
 *
 *   enable / disable   phpenmod/phpdismod plus an FPM restart — a couple of
 *                      seconds, so they run inline like the rest of the tab
 *                      and return a terminal result.
 *   install / uninstall  touch apt, and install may compile from PECL, which
 *                      blows well past PHP's 30s max_execution_time. These
 *                      dispatch {@see ServerManageRemoteSshJob} and return a
 *                      task id for the caller to poll.
 *
 * Both paths re-probe the inventory when they finish so the panel reflects the
 * host rather than what we assumed the command did.
 */
trait RunsPhpExtensionActions
{
    /**
     * Whether this action runs in the background. apt work does, because a
     * PECL build compiles from source and can outlast the request; the caller
     * uses this to decide between wrapping the call in a synchronous
     * ConsoleAction and handing the row to the job.
     */
    public function extensionActionIsQueued(string $action): bool
    {
        return in_array(trim($action), ['install', 'uninstall'], true);
    }

    /**
     * @param  callable(string $step): void|null  $onProgress
     * @param  string|null  $consoleActionId  Row for a queued job to drive through its own lifecycle.
     * @return array{status: 'completed'|'queued', message: string, output?: ?string, task_id?: string}
     */
    public function applyExtensionAction(
        Server $server,
        string $action,
        string $version,
        string $extension,
        ?callable $onProgress = null,
        ?string $consoleActionId = null,
    ): array {
        $version = $this->normalizeVersionId($version) ?? '';
        $extension = $this->normalizeExtensionId($extension) ?? '';
        $action = trim($action);

        if ($version === '' || $extension === '') {
            throw new \RuntimeException('A PHP version and a valid extension name are required.');
        }

        $server = $server->fresh() ?? $server;

        if (! $server->isReady() || empty($server->ssh_private_key) || blank($server->ip_address)) {
            throw new \RuntimeException('Provisioning and SSH must be ready before managing PHP extensions.');
        }

        // A version-level install/uninstall in flight would fight us for apt
        // and could remove the very version we are targeting.
        if (ServerPhpMutationLock::isHeld($server)) {
            throw new \RuntimeException('Another PHP action is already running for this server. Wait for it to finish.');
        }

        $entry = $this->extensionCatalogEntry($version, $extension);

        $this->guardExtensionAction(
            $server,
            $action,
            $version,
            $extension,
            $this->installedVersionIds($server),
            $entry,
        );

        return $this->extensionActionIsQueued($action)
            ? $this->queueExtensionAptAction($server, $action, $version, $extension, $entry, $onProgress, $consoleActionId)
            : $this->runExtensionToggle($server, $action, $version, $extension, $entry, $onProgress);
    }

    /**
     * Inline path — phpenmod/phpdismod over every module the package provides.
     *
     * @param  array<string, mixed>|null  $entry
     * @param  callable(string $step): void|null  $onProgress
     * @return array{status: 'completed', message: string, output: ?string}
     */
    protected function runExtensionToggle(
        Server $server,
        string $action,
        string $version,
        string $extension,
        ?array $entry,
        ?callable $onProgress,
    ): array {
        $enable = $action === 'enable';
        $modules = $entry !== null ? $this->extensionModules($entry) : [$extension];
        $script = $this->extensionToggleScript($version, $modules, $enable);

        $lock = ServerPhpMutationLock::acquire($server, 120);
        $acquired = $lock->get();

        if (! $acquired) {
            throw new \RuntimeException('Another PHP action is already running for this server. Wait for it to finish.');
        }

        try {
            $onProgress?->__invoke(($enable ? 'phpenmod' : 'phpdismod')." -v {$version} ".implode(' ', $modules));

            $output = app(ServerSshConnectionRunner::class)->run(
                $server,
                function ($ssh) use ($server, $script): string {
                    $out = $ssh->exec($this->extensionActionScript($server, $script), 120);
                    $exitCode = $ssh->lastExecExitCode();

                    if ($exitCode !== null && $exitCode !== 0) {
                        $trimmed = trim($out);

                        throw new \RuntimeException(
                            $trimmed !== ''
                                ? $trimmed
                                : __('Remote PHP extension command failed (exit :code).', ['code' => $exitCode]),
                        );
                    }

                    return $out;
                },
                $this->useRootSsh(),
                $this->fallbackToDeployUserSsh(),
            );

            $onProgress?->__invoke(__('Refreshing PHP inventory'));
            $this->syncInventorySnapshot($server, $this->fetchRemoteInventory($server->fresh() ?? $server));

            $label = is_string($entry['label'] ?? null) ? $entry['label'] : $extension;

            return [
                'status' => 'completed',
                'message' => $enable
                    ? __(':label enabled for PHP :version.', ['label' => $label, 'version' => $version])
                    : __(':label disabled for PHP :version.', ['label' => $label, 'version' => $version]),
                'output' => trim($output) !== '' ? trim($output) : null,
            ];
        } finally {
            ServerPhpMutationLock::releaseIfOwned($lock, $acquired);
        }
    }

    /**
     * Queued path — apt work runs in the background so the browser is not
     * holding an SSH session open through a source build.
     *
     * @param  array<string, mixed>|null  $entry
     * @param  callable(string $step): void|null  $onProgress
     * @return array{status: 'queued', message: string, task_id: string}
     */
    protected function queueExtensionAptAction(
        Server $server,
        string $action,
        string $version,
        string $extension,
        ?array $entry,
        ?callable $onProgress,
        ?string $consoleActionId = null,
    ): array {
        $allowPecl = (bool) ($entry['pecl'] ?? false);
        $label = is_string($entry['label'] ?? null) ? $entry['label'] : $extension;

        $script = $action === 'install'
            ? $this->extensionInstallScript($version, $extension, $allowPecl)
            : $this->extensionUninstallScript($version, $extension);

        $timeout = $this->extensionActionTimeout($action, $allowPecl);
        $taskName = "php-ext:{$action}:{$version}:{$extension}";
        $taskId = (string) Str::uuid();
        $ttl = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);

        Cache::put(ServerManageRemoteSshJob::cacheKey($taskId), [
            'status' => 'queued',
            'output' => '',
            'error' => null,
            'flash_success' => null,
            'queued_at' => time(),
        ], now()->addSeconds(max(120, $ttl + $timeout)));

        // Lets a re-clicked action supersede the earlier one rather than
        // running two apt transactions against the same package.
        if (config('server_manage.supersede_duplicate_remote_tasks', true)) {
            Cache::put(
                ServerManageRemoteSshJob::activeRequestCacheKey($server->id, $taskName),
                $taskId,
                now()->addSeconds(max(120, $ttl + $timeout)),
            );
        }

        $actionLabel = $action === 'install'
            ? __('Install :label for PHP :version', ['label' => $label, 'version' => $version])
            : __('Remove :label from PHP :version', ['label' => $label, 'version' => $version]);

        // Survives a page reload — the cache-only state vanishes if the
        // operator navigates away mid-build.
        $logRow = ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'task_name' => $taskName,
            'label' => $actionLabel,
            'status' => ServerManageAction::STATUS_QUEUED,
        ]);

        $onProgress?->__invoke($action === 'install'
            ? "apt-get install php{$version}-{$extension}".($allowPecl ? ' (PECL fallback available)' : '')
            : "apt-get purge php{$version}-{$extension}");

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $taskId,
            $taskName,
            ServerAptLockBash::wrapManageScript($script),
            $timeout,
            $actionLabel.' '.__('finished.'),
            $logRow->id,
            // The job owns the console row from here — it flips it to running
            // and then completed/failed when apt actually finishes, instead of
            // the request marking it done the moment it dispatches.
            broadcastEventClass: null,
            consoleActionId: $consoleActionId,
        );

        return [
            'status' => 'queued',
            'message' => $allowPecl && $action === 'install'
                ? __('Queued. :label may take a few minutes if it has to build from source.', ['label' => $label])
                : __('Queued. This page updates when the server responds.'),
            'task_id' => $taskId,
        ];
    }

    /**
     * Poll a queued extension task. Re-probes the inventory once the task
     * reaches a terminal state so the panel shows real host state.
     *
     * @return array{status: string, output: string, error: ?string, message: ?string}|null
     */
    public function pollExtensionTask(Server $server, string $taskId): ?array
    {
        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($taskId));

        if (! is_array($payload)) {
            return null;
        }

        $status = (string) ($payload['status'] ?? '');
        $output = (string) ($payload['output'] ?? '');
        $error = is_string($payload['error'] ?? null) && $payload['error'] !== '' ? $payload['error'] : null;

        if (! in_array($status, ['finished', 'failed'], true)) {
            return [
                'status' => $status,
                'output' => $output,
                'error' => $error,
                'message' => null,
            ];
        }

        if ($status === 'finished') {
            // Best-effort: a probe failure must not turn a successful install
            // into a reported failure. The stale banner already covers it.
            try {
                $this->syncInventorySnapshot($server, $this->fetchRemoteInventory($server->fresh() ?? $server));
            } catch (\Throwable) {
            }
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($taskId));

        return [
            'status' => $status,
            'output' => $output,
            'error' => $error,
            'message' => is_string($payload['flash_success'] ?? null) ? $payload['flash_success'] : null,
        ];
    }
}
