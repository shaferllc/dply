<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;

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

        $sshUser = $this->normalize((string) $server->ssh_user);
        if ($sshUser !== '' && $normalized === $sshUser) {
            return __('The server’s deploy SSH user cannot be removed.');
        }

        $count = $this->sitesUsingUserCount($server, $normalized);
        if ($count > 0) {
            return __('This user is still assigned to :count site(s) on this server. Reassign those sites first.', [
                'count' => $count,
            ]);
        }

        return null;
    }

    /**
     * @return array<string, int> username => number of sites using it as effective user
     */
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

    private function normalize(string $username): string
    {
        return strtolower(trim($username));
    }
}
