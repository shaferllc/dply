<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Str;

class ServerSystemUserService
{
    public function __construct(
        private ServerSshConnectionRunner $sshRunner,
        private ServerSystemUserDeletionPolicy $deletionPolicy,
    ) {}

    /**
     * @return list<array{username: string, site_count: int}>
     */
    public function listPasswdUsersWithSiteCounts(Server $server, ServerPasswdUserLister $lister): array
    {
        $names = $lister->listUsernames($server);
        $counts = $this->deletionPolicy->siteCountsByUsername($server);

        $rows = [];
        foreach ($names as $name) {
            $key = strtolower($name);
            $rows[] = [
                'username' => $name,
                'site_count' => $counts[$key] ?? 0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['username'], $b['username']));

        return $rows;
    }

    /**
     * Ensures a Linux user exists, then assigns ownership of the site tree.
     *
     * @throws \RuntimeException
     */
    public function createUserAndAssignSite(Site $site, string $username, bool $grantSudo): void
    {
        $server = $site->server;
        $this->assertServerReady($server);
        $u = $this->validateNewUsername($username);

        $this->assertAcceptableCreateUsername($server, $u);

        $this->createUserIfMissing($server, $u, $grantSudo);
        $this->chownSiteRepositoryTree($site, $u);

        $site->update(['php_fpm_user' => $u]);
        $this->writeOperationMeta($site, 'ok', __('System user :user created and assigned.', ['user' => $u]));
    }

    /**
     * Updates site ownership and {@see Site::$php_fpm_user} to an existing account.
     *
     * @throws \RuntimeException
     */
    public function assignExistingUserToSite(Site $site, string $username): void
    {
        $server = $site->server;
        $this->assertServerReady($server);
        $u = $this->validatePasswdStyleUsername($username);

        $this->assertNotRoot($u);
        $this->assertUserExistsOnServer($server, $u);

        $this->chownSiteRepositoryTree($site, $u);
        $site->update(['php_fpm_user' => $u]);
        $this->writeOperationMeta($site, 'ok', __('Site files assigned to :user.', ['user' => $u]));
    }

    /**
     * Removes a Linux account from the host when policy allows.
     *
     * @throws \RuntimeException
     */
    public function deleteUserFromServer(Server $server, string $username): void
    {
        $this->assertServerReady($server);

        $reason = $this->deletionPolicy->deletionBlockedReason($server, $username);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        $u = $this->validatePasswdStyleUsername($username);

        $uid = $this->remoteUserUid($server, $u);
        if ($uid !== null && $uid < 1000) {
            throw new \RuntimeException(__('Refusing to remove system accounts with UID below 1000.'));
        }

        $this->runPrivileged($server, 'userdel '.escapeshellarg($u), 120);
    }

    private function assertUserExistsOnServer(Server $server, string $username): void
    {
        $uid = $this->remoteUserUid($server, $username);
        if ($uid === null) {
            throw new \RuntimeException(__('User :user does not exist on the server.', ['user' => $username]));
        }
    }

    private function createUserIfMissing(Server $server, string $username, bool $grantSudo): void
    {
        if ($this->remoteUserUid($server, $username) !== null) {
            throw new \RuntimeException(__('That user already exists on the server. Use “Select existing” instead.'));
        }

        $group = $grantSudo ? '-G sudo ' : '';
        $cmd = 'useradd -m -s /bin/bash '.$group.escapeshellarg($username);

        $this->runPrivileged($server, $cmd, 120);
    }

    public function chownSiteRepositoryTree(Site $site, string $linuxUser): void
    {
        $server = $site->server;
        $this->assertServerReady($server);

        $path = rtrim($site->effectiveRepositoryPath(), '/');
        if ($path === '') {
            throw new \RuntimeException(__('Site repository path is empty; set paths before changing ownership.'));
        }

        $u = $this->validatePasswdStyleUsername($linuxUser);
        $this->runPrivileged(
            $server,
            'chown -R '.escapeshellarg($u).':'.escapeshellarg($u).' '.escapeshellarg($path),
            600
        );
    }

    private function assertServerReady(Server $server): void
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException(__('Server must be ready with an SSH key.'));
        }
    }

    private function assertNotRoot(string $username): void
    {
        if (strtolower($username) === 'root') {
            throw new \RuntimeException(__('Choose a non-root system user.'));
        }
    }

    private function assertAcceptableCreateUsername(Server $server, string $username): void
    {
        $lower = strtolower($username);
        $this->assertNotRoot($username);

        if ($lower === 'dply') {
            throw new \RuntimeException(__('That username is reserved.'));
        }

        $cfg = strtolower(trim((string) config('server_provision.deploy_ssh_user', 'dply')));
        if ($cfg !== '' && $lower === $cfg) {
            throw new \RuntimeException(__('That username is reserved for the deploy user.'));
        }

        $deploy = strtolower(trim((string) $server->ssh_user));
        if ($deploy !== '' && $lower === $deploy) {
            throw new \RuntimeException(__('That username is reserved for the server’s deploy user.'));
        }
    }

    /**
     * Linux username validation (Debian useradd constraints, conservative).
     */
    public function validateNewUsername(string $username): string
    {
        $u = trim($username);
        if ($u === '' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $u)) {
            throw new \RuntimeException(__('Use a valid Linux username (letters, digits, underscore; max 32 characters).'));
        }

        return $u;
    }

    /**
     * Matches {@see ServerPasswdUserLister} filtering for usernames coming from /etc/passwd.
     */
    public function validatePasswdStyleUsername(string $username): string
    {
        $u = trim($username);
        if ($u === '' || ! preg_match('/^[a-zA-Z0-9._-]+$/', $u) || strlen($u) > 64) {
            throw new \RuntimeException(__('That is not a valid Linux username.'));
        }

        return $u;
    }

    private function runPrivileged(Server $server, string $bashFragment, int $timeoutSeconds): void
    {
        $this->sshRunner->run($server, function (SshConnection $ssh) use ($bashFragment, $timeoutSeconds): void {
            $wrapped = sprintf('(%s) 2>&1; printf "\nDPLY_EXIT:%%s" "$?"', $bashFragment);
            $out = $ssh->exec($wrapped, $timeoutSeconds);
            if (! preg_match('/DPLY_EXIT:0\s*$/', $out)) {
                throw new \RuntimeException(Str::limit(trim($out), 2000));
            }
        });
    }

    private function remoteUserUid(Server $server, string $username): ?int
    {
        try {
            return $this->sshRunner->run($server, function (SshConnection $ssh) use ($username): ?int {
                $out = trim($ssh->exec('id -u '.escapeshellarg($username).' 2>/dev/null', 30));
                $code = $ssh->lastExecExitCode();
                if ($code !== 0 || $out === '' || ! ctype_digit($out)) {
                    return null;
                }

                return (int) $out;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    private function writeOperationMeta(Site $site, string $status, string $message): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['system_user_operation'] = [
            'status' => $status,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $site->update(['meta' => $meta]);
    }
}
