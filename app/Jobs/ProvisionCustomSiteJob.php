<?php

namespace App\Jobs;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\SyncCustomSiteDeployStep;
use App\Services\SshConnectionFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionCustomSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $siteId) {}

    public function handle(SshConnectionFactory $sshFactory, SyncCustomSiteDeployStep $stepSync): void
    {
        $site = Site::query()->with(['server', 'deployScript'])->find($this->siteId);
        if (! $site) {
            return;
        }

        if ($site->status === Site::STATUS_CUSTOM_ACTIVE) {
            return;
        }

        $server = $site->server;
        if (! $server) {
            $site->forceFill([
                'status' => Site::STATUS_SCAFFOLD_FAILED,
            ])->save();

            return;
        }

        $site->forceFill(['status' => Site::STATUS_SCAFFOLDING])->save();

        try {
            $shell = $sshFactory->forServer($server);
            $systemUser = $site->effectiveSystemUser($server);
            $deployPath = $this->resolveDeployPath($site, $systemUser);

            $this->ensureSystemUser($shell, $systemUser);
            $this->ensureDirectory($shell, $deployPath, $systemUser);

            if ($site->isCustomGitMode()) {
                $this->cloneRepository($shell, $site, $deployPath, $systemUser);
            }

            $site->forceFill([
                'repository_path' => $deployPath,
                'status' => Site::STATUS_CUSTOM_ACTIVE,
            ])->save();

            $stepSync->sync($site->fresh(['deployScript']));

            // Git-mode sites have code on the box now — run the first deployment
            // so the deploy script (composer/build/queue restart) actually runs.
            // No-repo mode has nothing to deploy until CI pushes code in.
            if ($site->isCustomGitMode()) {
                RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
            }
        } catch (Throwable $e) {
            Log::error('Custom site provisioning failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            $site->forceFill([
                'status' => Site::STATUS_SCAFFOLD_FAILED,
            ])->save();

            throw $e;
        }
    }

    private function resolveDeployPath(Site $site, string $systemUser): string
    {
        $configured = trim((string) $site->repository_path);

        if ($configured !== '') {
            return $configured;
        }

        return "/home/{$systemUser}/{$site->slug}";
    }

    private function ensureSystemUser(RemoteShell $shell, string $user): void
    {
        $userEsc = escapeshellarg($user);
        $shell->exec(
            "if ! id {$userEsc} >/dev/null 2>&1; then sudo useradd -m -s /bin/bash {$userEsc}; fi"
        );
    }

    private function ensureDirectory(RemoteShell $shell, string $path, string $user): void
    {
        $pathEsc = escapeshellarg($path);
        $userEsc = escapeshellarg($user);
        $shell->exec("sudo mkdir -p {$pathEsc} && sudo chown -R {$userEsc}:{$userEsc} {$pathEsc}");
    }

    private function cloneRepository(RemoteShell $shell, Site $site, string $deployPath, string $user): void
    {
        $url = trim((string) $site->git_repository_url);
        $branch = trim((string) $site->git_branch) !== '' ? trim((string) $site->git_branch) : 'main';

        if ($url === '') {
            return;
        }

        $pathEsc = escapeshellarg($deployPath);
        $urlEsc = escapeshellarg($url);
        $branchEsc = escapeshellarg($branch);
        $userEsc = escapeshellarg($user);
        $refKind = $site->gitRefKind();

        if ($refKind === 'commit') {
            // Full clone so the SHA is in history, then check it out detached.
            // No `git pull` — SHAs/tags are immutable refs.
            $shell->exec(
                "sudo -u {$userEsc} bash -lc 'cd {$pathEsc} && if [ -d .git ]; then git fetch --all --prune && git checkout {$branchEsc}; else git clone {$urlEsc} . && git checkout {$branchEsc}; fi'",
                240
            );

            return;
        }

        if ($refKind === 'tag') {
            $shell->exec(
                "sudo -u {$userEsc} bash -lc 'cd {$pathEsc} && if [ -d .git ]; then git fetch --all --prune && git checkout {$branchEsc}; else git clone {$urlEsc} . && git checkout {$branchEsc}; fi'",
                240
            );

            return;
        }

        $shell->exec(
            "sudo -u {$userEsc} bash -lc 'cd {$pathEsc} && if [ -d .git ]; then git fetch --all --prune && git checkout {$branchEsc} && git pull --ff-only; else git clone --branch {$branchEsc} {$urlEsc} .; fi'",
            240
        );
    }
}
