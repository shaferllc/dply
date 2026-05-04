<?php

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Server-level filesystem layout for the provision pipeline.
 *
 * Returns ONLY the paths that genuinely belong to a bare server —
 * a default web root for the "no site configured yet" landing page,
 * a server-level log dir, and the bin dir.
 *
 * The Capistrano-style `current / shared / releases / tmp` layout
 * is per-SITE, owned by the site scaffolders + legacy site
 * provisioner under `/home/dply/<site-slug>/...`. Stuffing it into
 * server provisioning under an `apps/<server-slug>/` prefix was a
 * leftover from when each server hosted exactly one app; the
 * multi-site model makes it nonsense.
 */
class ServerDeployLayoutBuilder
{
    /**
     * @return array{web_root:string,logs:string,bin:string}
     */
    public function build(Server $server): array
    {
        return [
            // Ubuntu's default nginx docroot. We render a dply-branded
            // landing page here so a freshly provisioned server returns
            // something honest on port 80 ("dply server ready — create
            // a site to deploy") rather than nginx's default welcome.
            'web_root' => '/var/www/html',

            // Server-level log dir for the default supervisor worker
            // and any server-wide cron jobs. Per-site logs live under
            // /home/dply/<site-slug>/storage/logs/.
            'logs' => '/var/log/dply',

            'bin' => '/usr/local/bin',
        ];
    }
}
