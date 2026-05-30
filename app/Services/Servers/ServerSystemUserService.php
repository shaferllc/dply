<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerSystemUser;
use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServerSystemUserService
{
    public function __construct(
        private ServerSshConnectionRunner $sshRunner,
        private ServerSystemUserDeletionPolicy $deletionPolicy,
    ) {}

    /**
     * SSH-probes the server, persists the snapshot to `server_system_users`,
     * and returns enriched rows for rendering (site list + orphan/protected
     * flags). Stale records (users no longer present on the host) are removed
     * in the same transaction so the table reflects reality on each sync.
     *
     * @return list<array{username: string, site_count: int, is_protected: bool, is_orphan: bool, uid: int|null, home: string, shell: string, groups: list<string>, sites: list<array{id: string, name: string}>}>
     */
    public function listPasswdUsersWithSiteCounts(Server $server, ServerPasswdUserLister $lister): array
    {
        $details = $lister->listPasswdDetails($server);
        $this->persistSystemUsers($server, $details);

        return $this->buildEnrichedRows($server, $details);
    }

    /**
     * DB-backed read of the last persisted snapshot. Used by the workspace
     * page on mount so the table is populated without a fresh SSH probe.
     *
     * @return list<array{username: string, site_count: int, is_protected: bool, is_orphan: bool, uid: int|null, home: string, shell: string, groups: list<string>, sites: list<array{id: string, name: string}>}>
     */
    public function storedSystemUsersWithMetadata(Server $server): array
    {
        $details = $server->systemUsers()->get()
            ->map(static fn (ServerSystemUser $u): array => [
                'username' => $u->username,
                'uid' => $u->uid,
                'home' => (string) $u->home,
                'shell' => (string) $u->shell,
                'groups' => is_array($u->groups) ? array_values($u->groups) : [],
            ])
            ->all();

        return $this->buildEnrichedRows($server, $details);
    }

    /**
     * @param  list<array{username: string, uid?: int|null, home?: string, shell?: string, groups?: list<string>}>  $details
     */
    private function persistSystemUsers(Server $server, array $details): void
    {
        $now = now();
        $seen = [];

        DB::transaction(function () use ($server, $details, $now, &$seen): void {
            foreach ($details as $d) {
                $username = (string) ($d['username'] ?? '');
                if ($username === '') {
                    continue;
                }
                $seen[] = $username;

                ServerSystemUser::query()->updateOrCreate(
                    ['server_id' => $server->id, 'username' => $username],
                    [
                        'uid' => $d['uid'] ?? null,
                        'home' => (string) ($d['home'] ?? ''),
                        'shell' => (string) ($d['shell'] ?? ''),
                        'groups' => array_values($d['groups'] ?? []),
                        'last_seen_at' => $now,
                    ],
                );
            }

            $stale = ServerSystemUser::query()->where('server_id', $server->id);
            if ($seen !== []) {
                $stale->whereNotIn('username', $seen);
            }
            $stale->delete();
        });
    }

    /**
     * @param  list<array{username: string, uid?: int|null, home?: string, shell?: string, groups?: list<string>}>  $details
     * @return list<array{username: string, site_count: int, is_protected: bool, is_orphan: bool, uid: int|null, home: string, shell: string, groups: list<string>, sites: list<array{id: string, name: string}>}>
     */
    private function buildEnrichedRows(Server $server, array $details): array
    {
        $sitesByUser = $this->sitesByEffectiveUser($server);

        $rows = [];
        foreach ($details as $d) {
            $key = strtolower($d['username']);
            $sites = $sitesByUser[$key] ?? [];
            $siteCount = count($sites);
            $protected = $this->deletionPolicy->isProtected($server, $d['username']);

            $rows[] = [
                'username' => $d['username'],
                'site_count' => $siteCount,
                'is_protected' => $protected,
                'is_orphan' => ! $protected && $siteCount === 0,
                'uid' => $d['uid'] ?? null,
                'home' => (string) ($d['home'] ?? ''),
                'shell' => (string) ($d['shell'] ?? ''),
                'groups' => array_values($d['groups'] ?? []),
                'sites' => $sites,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['username'], $b['username']));

        return $rows;
    }

    /**
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function sitesByEffectiveUser(Server $server): array
    {
        $map = [];
        foreach (Site::query()->where('server_id', $server->id)->get(['id', 'name', 'php_fpm_user']) as $site) {
            $u = strtolower(trim($site->effectiveSystemUser($server)));
            if ($u === '') {
                continue;
            }
            $map[$u][] = [
                'id' => (string) $site->id,
                'name' => (string) ($site->name ?? $site->id),
            ];
        }

        return $map;
    }

    /**
     * Creates a Linux account on the server. Server-scoped operation: nothing
     * about a particular site is touched here. Used by the server-level
     * /system-users page; the site-level "Create user" path is gone — sites
     * pick from the existing-users dropdown via {@see assignExistingUserToSite()}.
     *
     * @param  list<string>  $extraGroups  supplementary groups (e.g. www-data)
     *
     * @throws \RuntimeException
     */
    public function createUser(Server $server, string $username, bool $grantSudo, string $shell = '/bin/bash', array $extraGroups = []): void
    {
        $this->assertServerReady($server);
        $u = $this->validateNewUsername($username);

        $this->assertAcceptableCreateUsername($server, $u);
        $this->createUserIfMissing($server, $u, $grantSudo, $shell, $extraGroups);
    }

    /**
     * Ensures a Linux user exists, then assigns ownership of the site tree.
     * Thin wrapper kept for any caller that still wants the single-shot path.
     *
     * @throws \RuntimeException
     */
    public function createUserAndAssignSite(Site $site, string $username, bool $grantSudo): void
    {
        $this->createUser($site->server, $username, $grantSudo);
        $u = $this->validateNewUsername($username);
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
     * Updates shell and supplementary group membership for an existing account.
     *
     * @throws \RuntimeException
     */
    public function updateUser(
        Server $server,
        string $username,
        ?string $shell = null,
        ?bool $grantSudo = null,
        ?bool $addWebGroup = null,
    ): void {
        $this->assertServerReady($server);
        $u = $this->validatePasswdStyleUsername($username);
        $this->assertUserExistsOnServer($server, $u);

        if ($this->deletionPolicy->isProtected($server, $u)) {
            throw new \RuntimeException(__('That account is protected and cannot be modified from dply.'));
        }

        if ($shell !== null) {
            $shellPath = $this->validateShell($shell);
            $this->runPrivileged($server, 'usermod -s '.escapeshellarg($shellPath).' '.escapeshellarg($u), 120);
        }

        if ($grantSudo !== null) {
            $cmd = $grantSudo
                ? 'gpasswd -a '.escapeshellarg($u).' sudo'
                : 'gpasswd -d '.escapeshellarg($u).' sudo';
            $this->runPrivileged($server, $cmd, 120);
        }

        if ($addWebGroup !== null) {
            $webGroup = $this->validateWebServerGroup((string) config('site_settings.vm_site_file_web_group', 'www-data'));
            $cmd = $addWebGroup
                ? 'gpasswd -a '.escapeshellarg($u).' '.escapeshellarg($webGroup)
                : 'gpasswd -d '.escapeshellarg($u).' '.escapeshellarg($webGroup);
            $this->runPrivileged($server, $cmd, 120);
        }
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

        ServerSystemUser::query()
            ->where('server_id', $server->id)
            ->where('username', $u)
            ->delete();
    }

    private function assertUserExistsOnServer(Server $server, string $username): void
    {
        $uid = $this->remoteUserUid($server, $username);
        if ($uid === null) {
            throw new \RuntimeException(__('User :user does not exist on the server.', ['user' => $username]));
        }
    }

    /**
     * @param  list<string>  $extraGroups
     */
    private function createUserIfMissing(Server $server, string $username, bool $grantSudo, string $shell, array $extraGroups): void
    {
        if ($this->remoteUserUid($server, $username) !== null) {
            throw new \RuntimeException(__('That user already exists on the server. Use “Select existing” instead.'));
        }

        $shellPath = $this->validateShell($shell);

        $groups = [];
        if ($grantSudo) {
            $groups[] = 'sudo';
        }
        foreach ($extraGroups as $g) {
            $groups[] = $this->validateWebServerGroup($g);
        }
        $groups = array_values(array_unique($groups));

        $groupArg = $groups === [] ? '' : '-G '.escapeshellarg(implode(',', $groups)).' ';
        $cmd = 'useradd -m -s '.escapeshellarg($shellPath).' '.$groupArg.escapeshellarg($username);

        $this->runPrivileged($server, $cmd, 120);
    }

    private function validateShell(string $shell): string
    {
        $s = trim($shell);
        $allowed = [
            '/bin/bash',
            '/bin/sh',
            '/usr/sbin/nologin',
        ];
        if (! in_array($s, $allowed, true)) {
            throw new \RuntimeException(__('Login shell must be one of: :list.', ['list' => implode(', ', $allowed)]));
        }

        return $s;
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

    /**
     * Resets ownership and typical permissions on the site repository tree (user + web server group, 755/644;
     * Laravel storage and bootstrap/cache tightened to 775/664 when present).
     *
     * @throws \RuntimeException
     */
    public function resetSiteFilePermissions(Site $site): void
    {
        $server = $site->server;
        $this->assertServerReady($server);

        $path = rtrim($site->effectiveRepositoryPath(), '/');
        if ($path === '') {
            throw new \RuntimeException(__('Site repository path is empty; set paths before resetting permissions.'));
        }

        $user = $site->effectiveSystemUser($server);
        $u = $this->validatePasswdStyleUsername($user);

        $group = $this->validateWebServerGroup((string) config('site_settings.vm_site_file_web_group', 'www-data'));

        $pathQ = escapeshellarg($path);
        $userQ = escapeshellarg($u);
        $groupQ = escapeshellarg($group);

        $script = <<<BASH
ROOT={$pathQ}
chown -R {$userQ}:{$groupQ} "\$ROOT"
find "\$ROOT" -type d -exec chmod 755 {} +
find "\$ROOT" -type f -exec chmod 644 {} +
if [ -d "\$ROOT/storage" ]; then
  find "\$ROOT/storage" -type d -exec chmod 775 {} +
  find "\$ROOT/storage" -type f -exec chmod 664 {} +
fi
if [ -d "\$ROOT/bootstrap/cache" ]; then
  find "\$ROOT/bootstrap/cache" -type d -exec chmod 775 {} +
  find "\$ROOT/bootstrap/cache" -type f -exec chmod 664 {} +
fi
BASH;

        $this->runPrivileged($server, $script, 900);

        $this->writeOperationMeta(
            $site,
            'ok',
            __('File permissions reset (user :user, group :group).', ['user' => $u, 'group' => $group])
        );
    }

    private function validateWebServerGroup(string $group): string
    {
        $g = trim($group);
        if ($g === '' || ! preg_match('/^[a-zA-Z0-9._-]+$/', $g) || strlen($g) > 32) {
            throw new \RuntimeException(__('Configured web server group is invalid.'));
        }

        return $g;
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
