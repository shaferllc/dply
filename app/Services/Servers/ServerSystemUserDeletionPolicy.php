<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;

/**
 * Guards Linux account removal so control-plane and deploy users cannot be deleted by mistake.
 */
final class ServerSystemUserDeletionPolicy
{
    public function deletionBlockedReason(Server $server, string $username): ?string
    {
        $normalized = $this->normalize($username);
        if ($normalized === '') {
            return __('A username is required.');
        }

        if ($this->isProtected($server, $normalized)) {
            return $this->protectedReason($server, $normalized);
        }

        $count = $this->sitesUsingUserCount($server, $normalized);
        if ($count > 0) {
            return __('This user is still assigned to :count site(s) on this server. Reassign those sites first.', [
                'count' => $count,
            ]);
        }

        $workers = $this->workerCountsByUsername($server)[$normalized] ?? 0;
        if ($workers > 0) {
            return __('This user still runs :count worker process(es) on this server. Reassign or remove them first.', [
                'count' => $workers,
            ]);
        }

        $crons = $this->cronCountsByUsername($server)[$normalized] ?? 0;
        if ($crons > 0) {
            return __('This user still owns :count cron job(s) on this server. Reassign or remove them first.', [
                'count' => $crons,
            ]);
        }

        return null;
    }

    /**
     * Account-name protection check (no site-count signal). Used to label
     * rows in the UI without re-running the full block-reason calculation.
     */
    public function isProtected(Server $server, string $username): bool
    {
        $normalized = $this->normalize($username);
        if ($normalized === '') {
            return false;
        }

        if ($normalized === 'root' || $normalized === 'dply') {
            return true;
        }

        $configDeploy = $this->normalize((string) config('server_provision.deploy_ssh_user', 'dply'));
        if ($configDeploy !== '' && $normalized === $configDeploy) {
            return true;
        }

        $sshUser = $this->normalize((string) $server->ssh_user);
        if ($sshUser !== '' && $normalized === $sshUser) {
            return true;
        }

        return false;
    }

    private function protectedReason(Server $server, string $normalized): string
    {
        if ($normalized === 'root') {
            return __('The root account cannot be removed.');
        }
        if ($normalized === 'dply') {
            return __('The dply account cannot be removed.');
        }
        $configDeploy = $this->normalize((string) config('server_provision.deploy_ssh_user', 'dply'));
        if ($configDeploy !== '' && $normalized === $configDeploy) {
            return __('The configured deploy user cannot be removed.');
        }

        return __('The server’s deploy SSH user cannot be removed.');
    }

    /**
     * @return array<string, int> username => number of sites using it as effective user
     */
    /** @return array<string, mixed> */
    public function siteCountsByUsername(Server $server): array
    {
        $counts = [];
        foreach (Site::query()->where('server_id', $server->id)->get(['id', 'php_fpm_user']) as $site) {
            $u = $this->normalize($site->effectiveSystemUser($server));
            if ($u === '') {
                continue;
            }
            $counts[$u] = ($counts[$u] ?? 0) + 1;
        }

        return $counts;
    }

    private function sitesUsingUserCount(Server $server, string $normalizedUsername): int
    {
        return $this->siteCountsByUsername($server)[$normalizedUsername] ?? 0;
    }

    /**
     * Worker processes (Supervisor programs + site processes) that run as each
     * account on this server. Site processes with no explicit user inherit the
     * site's effective user and are already covered by {@see siteCountsByUsername()},
     * so only explicitly-pinned ones are counted here to avoid double-counting.
     *
     * @return array<string, int> username => number of worker processes running as it
     */
    /** @return array<string, mixed> */
    public function workerCountsByUsername(Server $server): array
    {
        $counts = [];

        foreach (SupervisorProgram::query()->where('server_id', $server->id)->pluck('user') as $user) {
            $u = $this->normalize((string) $user);
            if ($u === '') {
                continue;
            }
            $counts[$u] = ($counts[$u] ?? 0) + 1;
        }

        $siteProcessUsers = SiteProcess::query()
            ->whereNotNull('user')
            ->whereHas('site', fn ($q) => $q->where('server_id', $server->id))
            ->pluck('user');
        foreach ($siteProcessUsers as $user) {
            $u = $this->normalize((string) $user);
            if ($u === '') {
                continue;
            }
            $counts[$u] = ($counts[$u] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, int> username => number of cron entries that run as it
     */
    /** @return array<string, mixed> */
    public function cronCountsByUsername(Server $server): array
    {
        $counts = [];
        foreach (ServerCronJob::query()->where('server_id', $server->id)->pluck('user') as $user) {
            $u = $this->normalize((string) $user);
            if ($u === '') {
                continue;
            }
            $counts[$u] = ($counts[$u] ?? 0) + 1;
        }

        return $counts;
    }

    private function normalize(string $username): string
    {
        return strtolower(trim($username));
    }
}
