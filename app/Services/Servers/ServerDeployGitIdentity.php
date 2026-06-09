<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Deploy-user git config (user.name / user.email) for server-side commits.
 */
final class ServerDeployGitIdentity
{
    /**
     * @return array{name: string, email: string}
     */
    public function defaults(Server $server): array
    {
        $suffix = (string) config('server_provision.deploy_git_identity_name_suffix', ' via Dply');
        $org = $server->organization;
        $baseName = $org !== null && filled($org->name)
            ? trim((string) $org->name)
            : trim((string) $server->name);
        if ($baseName === '') {
            $baseName = 'Dply';
        }

        $domain = (string) config('server_provision.deploy_git_identity_email_domain', 'dply.host');
        $localTemplate = (string) config('server_provision.deploy_git_identity_email_local', 'deploy+{server_id}');
        $local = str_replace('{server_id}', (string) $server->id, $localTemplate);

        return [
            'name' => $baseName.$suffix,
            'email' => $local.'@'.$domain,
        ];
    }

    public function deployUser(Server $server): ?string
    {
        $user = trim((string) ($server->ssh_user ?? ''));
        if ($user === '' || $user === 'root') {
            $user = (string) config('server_provision.deploy_ssh_user', 'dply');
        }

        if ($user === '' || $user === 'root' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user)) {
            return null;
        }

        return $user;
    }

    public function buildSetScript(string $deployUser, string $name, string $email): string
    {
        $userArg = escapeshellarg($deployUser);
        $nameArg = escapeshellarg($name);
        $emailArg = escapeshellarg($email);

        return <<<BASH
set -e
if ! command -v git >/dev/null 2>&1; then
  echo "Git is not installed on this server." >&2
  exit 1
fi
echo "[dply] setting deploy user git identity for {$deployUser}"
sudo -u {$userArg} -H git config --global user.name {$nameArg}
sudo -u {$userArg} -H git config --global user.email {$emailArg}
echo "Name: \$(sudo -u {$userArg} -H git config --global user.name 2>/dev/null || true)"
echo "Email: \$(sudo -u {$userArg} -H git config --global user.email 2>/dev/null || true)"
BASH;
    }

    /**
     * Bash lines that set git identity for the deploy user when unset.
     * Git must already be installed (call after base packages).
     *
     * @return list<string>
     */
    public function bootstrapLinesForServer(Server $server): array
    {
        if (! filter_var(config('server_provision.configure_deploy_git_identity', true), FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        $deployUser = $this->deployUser($server);
        if ($deployUser === null) {
            return [];
        }

        $defaults = $this->defaults($server);
        $userArg = escapeshellarg($deployUser);
        $nameArg = escapeshellarg($defaults['name']);
        $emailArg = escapeshellarg($defaults['email']);

        return [
            'if command -v git >/dev/null 2>&1; then',
            '  existing_name=$(sudo -u '.$userArg.' -H git config --global user.name 2>/dev/null || true)',
            '  existing_email=$(sudo -u '.$userArg.' -H git config --global user.email 2>/dev/null || true)',
            '  if [ -n "$existing_name" ] && [ -n "$existing_email" ]; then',
            '    echo "[dply] deploy user git identity already set; skipping"',
            '  else',
            '    [ -z "$existing_name" ] && sudo -u '.$userArg.' -H git config --global user.name '.$nameArg,
            '    [ -z "$existing_email" ] && sudo -u '.$userArg.' -H git config --global user.email '.$emailArg,
            '    echo "[dply] deploy user git identity configured for '.$deployUser.'"',
            '  fi',
            'else',
            '  echo "[dply] git not present; skipping deploy user identity bootstrap"',
            'fi',
        ];
    }
}
