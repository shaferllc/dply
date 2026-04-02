<?php

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Wraps shell commands so site-scoped work runs as the site's effective Linux user when
 * the SSH session uses the server's deploy user (e.g. dply). Falls back to no wrapping when
 * the login user already matches, or uses sudo for root-only commands when needed.
 */
final class SiteScopedCommandWrapper
{
    /**
     * @param  string  $command  Full shell command (e.g. `cd /path && ./script`).
     */
    public function wrapRemoteExec(Site $site, string $command): string
    {
        $server = $site->server;
        if ($server === null) {
            throw new \RuntimeException(__('Server is not available.'));
        }

        $login = trim((string) $server->ssh_user) ?: 'root';
        $target = trim($site->effectiveSystemUser($server));

        if ($target === '') {
            $target = $login;
        }

        if ($target === $login) {
            return $command;
        }

        if ($target === 'root' && $login !== 'root') {
            return 'sudo -n bash -lc '.escapeshellarg($command);
        }

        return 'sudo -n -u '.escapeshellarg($target).' -H bash -lc '.escapeshellarg($command);
    }

    /**
     * Human-readable hint for UI (who the command runs as).
     */
    public function executionSummary(Site $site): string
    {
        $server = $site->server;
        if ($server === null) {
            return '';
        }

        $login = trim((string) $server->ssh_user) ?: 'root';
        $target = trim($site->effectiveSystemUser($server));

        if ($target === '' || $target === $login) {
            return __('Runs as :user', ['user' => $login]);
        }

        return __('Runs as :target (SSH :login)', ['target' => $target, 'login' => $login]);
    }
}
