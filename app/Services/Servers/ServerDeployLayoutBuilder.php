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
            // Default vhost docroot for the "no site configured yet"
            // landing page. Lives under /home/dply/ alongside every
            // per-site directory so all dply-managed paths stay in
            // one place — easier to grant SSH access, easier to back
            // up, and makes the "where does dply put things" mental
            // model uniform. Leading underscore sorts it first and
            // keeps it out of the way of real site slugs.
            'web_root' => '/home/dply/_default',

            // Server-level log dir for the default supervisor worker
            // and any server-wide cron jobs. Per-site logs live under
            // /home/dply/<site-slug>/storage/logs/.
            'logs' => '/home/dply/_default/logs',

            'bin' => '/usr/local/bin',
        ];
    }
}
