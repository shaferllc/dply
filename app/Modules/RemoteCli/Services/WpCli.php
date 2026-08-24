<?php

declare(strict_types=1);

namespace App\Modules\RemoteCli\Services;

use App\Models\Site;
use App\Models\User;

/**
 * wp-cli adapter on top of {@see RemoteCli}.
 *
 * INSTANT_COMMANDS comes from Q15 — the allowlist of inspect-only
 * subcommands that are safe to run inline (sub-second on a healthy
 * server). Anything not on this list runs async.
 *
 * The risk classification table is keyed by "command + subcommand"
 * pairs (e.g. 'plugin install'); unknown commands fall through to
 * RiskLevel::Destructive per the Q17 failsafe.
 */
class WpCli extends RemoteCli
{
    /**
     * Commands that complete in <1s on a typical site. These run sync.
     * Order doesn't matter; matched as exact or prefix-with-arg.
     *
     * @return list<string>
     */
    /** @return array<int, string> */
    protected function instantCommands(): array
    {
        return [
            'option get',
            'option list',
            'plugin list',
            'theme list',
            'cron event list',
            'core version',
            'core check-update',
            'db check',
            'db size',
            'user list',
            'config get',
            'menu list',
            'role list',
            'language core list',
            'site list',
            'maintenance-mode status',
        ];
    }

    /**
     * Risk classification for known commands. Anything not listed
     * defaults to Destructive (Q17 failsafe).
     *
     * Categories:
     *  - Read: pure inspection.
     *  - MutatingRecoverable: changes that can be undone with another
     *    command (install/uninstall, activate/deactivate).
     *  - Destructive: drops, deletes, search-replace --all-tables,
     *    salts regen, anything that loses data without restore.
     */
    public function classifyRisk(string $command): RiskLevel
    {
        $command = trim($command);

        // READ — pure inspection, no DB or filesystem mutation.
        $reads = [
            'option get', 'option list',
            'plugin list', 'plugin status', 'plugin get',
            'theme list', 'theme status', 'theme get',
            'cron event list', 'cron schedule list', 'cron test',
            'core version', 'core check-update', 'core verify-checksums',
            'db check', 'db size', 'db tables', 'db columns',
            'user list', 'user get',
            'config get', 'config list',
            'menu list', 'role list', 'cap list',
            'language core list', 'language plugin list', 'language theme list',
            'site list',
            'maintenance-mode status',
            'transient list',
        ];
        foreach ($reads as $read) {
            if ($command === $read || str_starts_with($command, $read.' ')) {
                return RiskLevel::Read;
            }
        }

        // MUTATING-RECOVERABLE — can be undone with the inverse command.
        $recoverable = [
            'plugin install', 'plugin update', 'plugin activate', 'plugin deactivate',
            'theme install', 'theme update', 'theme activate', 'theme enable', 'theme disable',
            'core download', 'core install', 'core update', 'core update-db',
            'cron event run', 'cron event schedule', 'cron event delete',
            'option add', 'option update', 'option patch',
            'transient set', 'transient delete',
            'db query',
            'export',
            'user create', 'user update', 'user set-role', 'user add-role', 'user remove-role',
            'user meta add', 'user meta update',
            'menu create', 'menu item add-post', 'menu item add-custom', 'menu item add-term',
            'cache flush',
            'rewrite flush',
            'language core install', 'language plugin install', 'language theme install',
            'maintenance-mode activate', 'maintenance-mode deactivate',
        ];
        foreach ($recoverable as $rec) {
            if ($command === $rec || str_starts_with($command, $rec.' ')) {
                return RiskLevel::MutatingRecoverable;
            }
        }

        // Anything else (including all `db drop`, `db reset`, `db import`,
        // `search-replace`, `plugin delete`, `theme delete`, `user delete`,
        // `option delete`, `config set` writes, `eval`, `eval-file`, ...)
        // falls through to the Q17 failsafe.
        return RiskLevel::Destructive;
    }

    public function kind(): Kind
    {
        return Kind::Wp;
    }

    /**
     * Viewers may run Read commands, but wp-config / option values stay
     * keys-only or masked. Editors and system runs see the raw stdout.
     *
     * @param  list<string>  $args
     */
    public function redactStdoutForViewer(Site $site, ?User $user, string $command, array $args, string $stdout): string
    {
        if ($user === null || $user->can('update', $site) || $stdout === '') {
            return $stdout;
        }

        return $this->maskSecretReadStdout($command, $stdout);
    }

    private function maskSecretReadStdout(string $command, string $stdout): string
    {
        $command = trim($command);

        foreach (['config get', 'option get'] as $verb) {
            if ($command === $verb || str_starts_with($command, $verb.' ')) {
                return '********';
            }
        }

        foreach (['config list', 'option list'] as $verb) {
            if ($command === $verb || str_starts_with($command, $verb.' ')) {
                return $this->keysOnlyListStdout($stdout);
            }
        }

        return $stdout;
    }

    private function keysOnlyListStdout(string $stdout): string
    {
        $decoded = json_decode(trim($stdout), true);
        if (! is_array($decoded)) {
            return __('Values hidden for your role.');
        }

        $keys = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $row['name'] ?? $row['option_name'] ?? $row['key'] ?? null;
            if (is_string($name) && $name !== '') {
                $keys[] = ['name' => $name];
            }
        }

        $encoded = json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : __('Values hidden for your role.');
    }

    /**
     * Build the wp-cli invocation. Always run as the site's deploy
     * user with `--path=<document_root>` so wp picks the right install
     * even when multiple WP sites coexist on the host.
     *
     * @param  list<string>  $args
     */
    protected function buildShellCommand(Site $site, string $command, array $args): string
    {
        $path = $site->document_root ?: $site->repository_path ?: '/home/dply/'.$site->slug;
        $escaped = array_map(escapeshellarg(...), $args);

        return sprintf(
            'wp %s --path=%s %s',
            $command,
            escapeshellarg($path),
            implode(' ', $escaped),
        );
    }
}
