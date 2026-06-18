<?php

namespace App\Jobs;

use App\Contracts\RemoteShell;
use App\Models\Script;
use App\Models\Server;
use App\Services\SshConnectionFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort cleanup of a Custom (headless) site's on-server footprint
 * after the Site row has been deleted. Errors are logged, not raised —
 * a deleted site row should never appear undeleted because of cleanup.
 *
 * Cleanup:
 *   - rm -rf the site directory
 *   - Remove the dedicated system user (only when exclusive to this site)
 *   - Delete the auto-created stub Script (only when source = site:custom_auto and unused)
 *
 * Supervisor programs and cron jobs cascade via DB foreign keys on the
 * existing site_id-referencing tables and are scrubbed on disk by
 * dply's existing supervisor / cron sync workers on the next tick.
 */
class CleanupCustomSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $serverId,
        public string $deployPath,
        public string $systemUser,
        public bool $userIsDedicated,
        public ?string $scriptId,
    ) {}

    public function handle(SshConnectionFactory $sshFactory): void
    {
        $server = Server::find($this->serverId);
        if (! $server) {
            return;
        }

        try {
            $shell = $sshFactory->forServer($server);
            $this->removeDirectory($shell, $this->deployPath);
            if ($this->userIsDedicated && $this->userOnlyForDeletedSite()) {
                $this->removeSystemUser($shell, $this->systemUser);
            }
        } catch (Throwable $e) {
            Log::warning('Custom site server cleanup failed', [
                'server_id' => $this->serverId,
                'deploy_path' => $this->deployPath,
                'error' => $e->getMessage(),
            ]);
        }

        $this->maybeDeleteStubScript();
    }

    private function removeDirectory(RemoteShell $shell, string $path): void
    {
        if (trim($path) === '' || ! str_starts_with($path, '/')) {
            return;
        }

        $shell->exec('sudo rm -rf '.escapeshellarg($path));
    }

    private function removeSystemUser(RemoteShell $shell, string $user): void
    {
        if (trim($user) === '' || $user === 'root') {
            return;
        }

        $userEsc = escapeshellarg($user);
        $shell->exec(
            "if id {$userEsc} >/dev/null 2>&1; then sudo userdel -r {$userEsc} || true; fi"
        );
    }

    private function userOnlyForDeletedSite(): bool
    {
        return DB::table('sites')
            ->where('server_id', $this->serverId)
            ->where('php_fpm_user', $this->systemUser)
            ->doesntExist();
    }

    private function maybeDeleteStubScript(): void
    {
        if ($this->scriptId === null) {
            return;
        }

        $script = Script::query()->find($this->scriptId);
        if (! $script || $script->source !== 'site:custom_auto') {
            return;
        }

        $stillUsed = DB::table('sites')
            ->where('deploy_script_id', $this->scriptId)
            ->exists();

        if ($stillUsed) {
            return;
        }

        $script->delete();
    }
}
