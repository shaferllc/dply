<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\SftpAccount;
use App\Services\SshConnection;
use Illuminate\Support\Str;

/**
 * Puts file-transfer-only accounts on a server.
 *
 * Three layers, deliberately separated:
 *
 *  1. sshd — ONE constant snippet per server, never templated from user input.
 *     All per-account variation lives in Linux (group membership + ACLs), so
 *     the config file is byte-identical on every server forever: no injection
 *     surface, and re-writing it is idempotent.
 *  2. The account — delegated wholesale to {@see ServerSystemUserService}, which
 *     already owns useradd/userdel, username validation and the deploy-user
 *     reservation. Nothing about account lifecycle is reimplemented here.
 *  3. The grant — POSIX ACLs. Group membership is not an option: the only group
 *     that can read a site tree is the web group, and joining it would grant
 *     read access to every site on the box.
 *
 * Every remote command goes through {@see runPrivileged()}, which appends an
 * exit-code sentinel and throws on non-zero. This is not optional politeness:
 * SshConnection::exec() returns output and never throws, so a bare exec() here
 * would ship an account that logs in fine and silently cannot see the site.
 */
class SftpAccountProvisioner
{
    public const SNIPPET_PATH = '/etc/ssh/sshd_config.d/99-dply-sftp.conf';

    public function __construct(
        private ServerSshConnectionRunner $sshRunner,
        private ServerSystemUserService $systemUsers,
    ) {}

    /**
     * sshd policy for the whole server. Sorts after 99-dply-hardening.conf, and
     * the trailing `Match all` is load-bearing: Include is textual, so without
     * it every directive in every file parsed afterwards would be swallowed into
     * this Match context and hardening would silently stop applying to everyone.
     */
    public function snippet(): string
    {
        return <<<'CFG'
# Managed by dply — file-transfer accounts. Edits are overwritten.
# Sorts after 99-dply-hardening.conf; the trailing `Match all` closes this
# block so later Include'd files are not absorbed into it.
Match Group dply-sftp
    PasswordAuthentication yes
    ForceCommand internal-sftp
    AllowTcpForwarding no
    X11Forwarding no
    PermitTunnel no
    PermitTTY no
Match all

CFG;
    }

    /**
     * Idempotent server prerequisites: acl tooling, the dply-sftp group, and the
     * sshd snippet. Run on every create so a reprovisioned box self-heals on the
     * next account rather than needing a reconcile sweep.
     */
    public function ensureServerPrerequisites(Server $server): void
    {
        $path = self::SNIPPET_PATH;
        $snippet = $this->snippet();
        $group = SftpAccount::GROUP;

        $script = <<<BASH
set -u

if ! command -v setfacl >/dev/null 2>&1; then
  (apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq acl) >/dev/null 2>&1 || true
fi
if ! command -v setfacl >/dev/null 2>&1; then
  echo "DPLY_ERR: setfacl is unavailable and the acl package could not be installed."
  exit 1
fi

getent group {$group} >/dev/null 2>&1 || groupadd {$group}

# Old sshd builds without an Include line would silently ignore the snippet,
# leaving accounts that authenticate but get a shell instead of a jailed sftp.
if ! grep -Eq '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' /etc/ssh/sshd_config; then
  echo "DPLY_ERR: /etc/ssh/sshd_config does not Include /etc/ssh/sshd_config.d/*.conf"
  exit 1
fi

mkdir -p /etc/ssh/sshd_config.d

# Global effective value BEFORE our write. `sshd -T` with no -C reports the
# global config with Match blocks NOT applied, so this must be identical after
# the write — if it moved, our Match block leaked into the global scope.
PRE=\$(sshd -T 2>/dev/null | awk '/^passwordauthentication /{print \$2; exit}')

if [ -f {$path} ]; then cp -a {$path} {$path}.dply-bak; fi
cat > {$path} <<'DPLY_SFTP_SNIPPET'
{$snippet}
DPLY_SFTP_SNIPPET
chmod 0644 {$path}

restore() {
  if [ -f {$path}.dply-bak ]; then mv -f {$path}.dply-bak {$path}; else rm -f {$path}; fi
}

if ! sshd -t 2>&1; then
  restore
  echo "DPLY_ERR: sshd -t rejected the sftp snippet; rolled back"
  exit 1
fi

POST=\$(sshd -T 2>/dev/null | awk '/^passwordauthentication /{print \$2; exit}')
if [ -n "\$PRE" ] && [ "\$PRE" != "\$POST" ]; then
  restore
  echo "DPLY_ERR: global PasswordAuthentication changed \$PRE -> \$POST; rolled back"
  exit 1
fi

rm -f {$path}.dply-bak
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
BASH;

        $this->runPrivileged($server, $script, 300);
    }

    /**
     * Grants one account access to its scope. Public and idempotent because it
     * is also the repair path: {@see ServerSystemUserService::resetSiteFilePermissions()}
     * runs `find -exec chmod`, which squashes the ACL mask, so it re-runs this
     * afterwards for every account on the site.
     */
    public function grantScript(SftpAccount $account): string
    {
        $target = $this->targetPath($account);
        $u = escapeshellarg($account->username);
        $targetQ = escapeshellarg($target);
        $parentQ = escapeshellarg(rtrim(dirname($target), '/'));
        $homeQ = escapeshellarg(rtrim($account->home_path, '/'));
        $linkQ = escapeshellarg(rtrim($account->home_path, '/').'/'.$this->linkName($account));

        // The account is not in the web group, so it has no traverse bit on the
        // sites parent. One --x entry there is the minimum that lets it reach
        // its own directory without being able to list siblings.
        // The default (`d:`) entry is what keeps access alive across atomic
        // deploys: every `releases/<hash>` is a brand-new directory, and the
        // kernel copies the default ACL onto it at creation. Applied to
        // directories only — setfacl rejects a default ACL on a regular file.
        return <<<BASH
set -u
setfacl -m u:{$u}:--x {$parentQ}
setfacl -R -m u:{$u}:rwX {$targetQ}
find {$targetQ} -type d -exec setfacl -m d:u:{$u}:rwX {} +
mkdir -p {$targetQ}/shared
setfacl -m u:{$u}:rwX -m d:u:{$u}:rwX {$targetQ}/shared
mkdir -p {$homeQ}
chown {$u}:{$u} {$homeQ}
chmod 0750 {$homeQ}
ln -sfn {$targetQ} {$linkQ}
chown -h {$u}:{$u} {$linkQ} 2>/dev/null || true
BASH;
    }

    /**
     * Creates the Linux account and grants it. Password is applied last so a
     * failed grant never leaves a loginable account with no access.
     */
    public function provision(SftpAccount $account, string $password): void
    {
        $server = $account->server;
        if ($server === null) {
            throw new \RuntimeException(__('Account is not attached to a server.'));
        }

        $this->ensureServerPrerequisites($server);

        // Delegates username validation, the deploy-user reservation and the
        // "already exists on the host" check. nologin + dply-sftp is what makes
        // the sshd Match block apply.
        $this->systemUsers->createUser(
            $server,
            $account->username,
            grantSudo: false,
            shell: '/usr/sbin/nologin',
            extraGroups: [SftpAccount::GROUP],
        );

        $this->runPrivileged($server, $this->grantScript($account), 600);
        $this->setPassword($account, $password);
    }

    /**
     * The password reaches the box through a heredoc rather than an argument or
     * an `echo |` pipe so it never appears in the process table. It is also
     * never logged: runPrivileged throws with command OUTPUT, not the script.
     */
    public function setPassword(SftpAccount $account, string $password): void
    {
        $server = $account->server;
        if ($server === null) {
            throw new \RuntimeException(__('Account is not attached to a server.'));
        }

        // Belt and braces around the heredoc: a password containing a newline
        // would let the rest of the line be read as another chpasswd entry.
        if (! preg_match('/^[A-Za-z0-9]{12,128}$/', $password)) {
            throw new \RuntimeException(__('Generated password failed its own format check.'));
        }

        $u = $account->username;

        $this->runPrivileged($server, <<<BASH
set -u
chpasswd <<'DPLY_PW'
{$u}:{$password}
DPLY_PW
usermod -U {$u}
BASH, 120);
    }

    /**
     * ACLs are stripped BEFORE userdel, while the username still resolves.
     * Afterwards the entries would linger as bare numeric UIDs, and the next
     * account that reuses that UID would silently inherit access to the site.
     */
    public function destroy(SftpAccount $account): void
    {
        $server = $account->server;
        if ($server === null) {
            return;
        }

        $target = $this->targetPath($account);
        $u = escapeshellarg($account->username);
        $targetQ = escapeshellarg($target);
        $parentQ = escapeshellarg(rtrim(dirname($target), '/'));
        $homeQ = escapeshellarg(rtrim($account->home_path, '/'));

        $this->runPrivileged($server, <<<BASH
set -u
setfacl -R -x u:{$u} {$targetQ} 2>/dev/null || true
find {$targetQ} -type d -exec setfacl -x d:u:{$u} {} + 2>/dev/null || true
setfacl -x u:{$u} {$parentQ} 2>/dev/null || true
rm -rf {$homeQ}
BASH, 600);

        // Plain userdel, never -r — the home holds symlinks into live site
        // trees. Also re-runs the deletion policy guards.
        $this->systemUsers->deleteUserFromServer($server, $account->username);
    }

    /** Site tree for a site-scoped account, the whole sites parent for a server-scoped one. */
    public function targetPath(SftpAccount $account): string
    {
        if ($account->site_id !== null && $account->site !== null) {
            $path = rtrim((string) $account->site->effectiveRepositoryPath(), '/');
            if ($path === '') {
                throw new \RuntimeException(__('Site has no repository path; set paths before adding FTP accounts.'));
            }

            return $path;
        }

        $deploy = trim((string) $account->server->ssh_user) ?: 'dply';

        return '/home/'.$deploy;
    }

    private function linkName(SftpAccount $account): string
    {
        return $account->isServerScoped() ? 'sites' : basename($this->targetPath($account));
    }

    /**
     * Mirrors ServerSystemUserService::runPrivileged(): the connection runner
     * prefers root, and the sentinel turns a non-zero exit into an exception —
     * SshConnection::exec() itself never throws.
     */
    private function runPrivileged(Server $server, string $bashFragment, int $timeoutSeconds): void
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException(__('Server must be ready with an SSH key.'));
        }

        $this->sshRunner->run($server, function (SshConnection $ssh) use ($bashFragment, $timeoutSeconds): void {
            $wrapped = sprintf('(%s) 2>&1; printf "\nDPLY_EXIT:%%s" "$?"', $bashFragment);
            $out = $ssh->exec($wrapped, $timeoutSeconds);
            if (! preg_match('/DPLY_EXIT:0\s*$/', $out)) {
                throw new \RuntimeException(Str::limit(trim($out), 2000));
            }
        });
    }
}
