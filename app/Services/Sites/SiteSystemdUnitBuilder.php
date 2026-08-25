<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteProcess;

/**
 * Builds systemd unit file content for a {@see Site}'s long-running processes.
 *
 * Per the multi-runtime strategy memo: "systemd unit per non-PHP site
 * (`dply-site-{id}.service`); PHP-FPM stays its own daemon with per-site
 * pool". This builder produces the unit content; a follow-up provisioner
 * is responsible for writing it to the server, daemon-reloading, and
 * starting the unit.
 *
 * Two kinds of unit:
 *   - Web unit (`dply-site-{id}.service`) for the upstream NGINX
 *     proxy_passes to. Reads its command from $site->start_command;
 *     binds PORT={internal_port} via the [Service] Environment line.
 *   - Worker / scheduler / custom processes (`dply-site-{id}-{name}.service`)
 *     drive each non-web SiteProcess row.
 *
 * The web unit is skipped for PHP and static runtimes: PHP is served by
 * the existing FPM master (per-site pool), and static sites have no
 * long-running process at all.
 */
class SiteSystemdUnitBuilder
{
    /**
     * Returns null when this site shouldn't have a web unit (PHP and
     * static runtimes), the unit content otherwise.
     */
    public function buildWebUnit(Site $site, string $deployUser): ?string
    {
        $runtime = $site->runtimeKey();
        if ($runtime === 'php' || $runtime === 'static') {
            return null;
        }

        $command = trim((string) $site->start_command);
        if ($command === '') {
            return null;
        }

        $port = $site->internal_port ?? $site->app_port;
        $workingDir = $this->resolveWorkingDirectory($site);
        $description = "Dply site {$site->slug} (web)";

        return $this->renderUnit(
            description: $description,
            execStart: $command,
            user: $deployUser,
            workingDirectory: $workingDir,
            port: $port !== null && $port > 0 ? (int) $port : null,
        );
    }

    /**
     * Returns null when the SiteProcess has no command yet (workers
     * created by the auto-detection layer for runtimes we couldn't
     * synthesize a command for), the unit content otherwise.
     */
    public function buildProcessUnit(Site $site, SiteProcess $process, string $deployUser): ?string
    {
        $command = trim((string) ($process->command ?? ''));
        if ($command === '') {
            return null;
        }

        $description = "Dply site {$site->slug} ({$process->name})";

        // A per-process working_directory (fillable on SiteProcess) overrides the
        // site-level resolution. Needed when the daemon must run from a path the
        // site-level heuristic gets wrong — e.g. a worker that must execute from
        // the atomic `current` symlink while the site row itself isn't flagged
        // atomic (legacy flat checkout coexisting with releases/ on the box).
        $override = trim((string) ($process->working_directory ?? ''));

        return $this->renderUnit(
            description: $description,
            execStart: $command,
            user: $deployUser,
            workingDirectory: $override !== '' ? rtrim($override, '/') : $this->resolveWorkingDirectory($site),
            port: null,
        );
    }

    public function webUnitName(Site $site): string
    {
        return "dply-site-{$site->id}.service";
    }

    public function processUnitName(Site $site, SiteProcess $process): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $process->name) ?? $process->name;

        return "dply-site-{$site->id}-{$safeName}.service";
    }

    private function resolveWorkingDirectory(Site $site): string
    {
        $base = trim((string) ($site->repository_path ?? ''));
        if ($base === '') {
            // Falls back to the conventional /home/dply/{domain} when the
            // path hasn't been set explicitly on the row. The provisioner
            // will make sure this exists on disk before starting the unit.
            $base = $site->conventionalRepositoryPath();
        }

        // Atomic-deploy sites run their command from the active release
        // symlink rather than the repo root. The provisioner manages the
        // `current` symlink; we just point at it here.
        if ($site->isAtomicDeploys()) {
            return rtrim($base, '/').'/current';
        }

        return rtrim($base, '/');
    }

    private function renderUnit(
        string $description,
        string $execStart,
        string $user,
        string $workingDirectory,
        ?int $port,
    ): string {
        $portLine = $port !== null
            ? "Environment=PORT={$port}\n"
            : '';

        $userLine = $user !== '' ? "User={$user}\nGroup={$user}\n" : '';

        // systemd requires an absolute ExecStart and applies no shell, so a
        // start command like `npm run start` exits 203/EXEC. Node and its
        // package managers are mise shims under the deploy user's home — never
        // on the system PATH — so the unit has to say where to look and run the
        // command through a shell. `exec` keeps the shell from lingering as an
        // extra PID between systemd and the app.
        $shims = $user !== '' ? "/home/{$user}/.local/share/mise/shims" : '';
        $pathLine = $shims !== ''
            ? "Environment=PATH={$shims}:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n"
            : '';

        if (! str_starts_with(trim($execStart), '/')) {
            $execStart = '/bin/sh -lc '.escapeshellarg('exec '.trim($execStart));
        }

        return <<<UNIT
# Managed by Dply — do not edit.
[Unit]
Description={$description}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
{$userLine}WorkingDirectory={$workingDirectory}
{$portLine}{$pathLine}ExecStart={$execStart}
# `always`, not `on-failure`: these are long-running daemons (Horizon, queue
# workers, web server). A deploy runs `horizon:terminate`, which exits 0 — a
# clean exit that `on-failure` would NOT restart, leaving the daemon dead after
# every deploy. `always` brings it back; an explicit `systemctl stop` still
# stops it (stop is not a restart trigger). RestartSec throttles crash loops.
Restart=always
RestartSec=5s
KillMode=mixed
TimeoutStopSec=20
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT;
    }
}
