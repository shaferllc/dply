<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Server;
use App\Models\Site;

/**
 * Renders the `pool.d/<name>.conf` body for a site's dedicated PHP-FPM pool.
 *
 * Every PHP site gets its own pool so request_terminate_timeout (the hard
 * wall-clock kill), pm.* sizing, and the run user are isolated per site instead
 * of shared through the global `www.conf`. The pool listens on a version-free
 * unix socket ({@see Site::phpFpmListenSocketPath()}) so the vhost never has to
 * change when the PHP version moves — only which `pool.d` directory this file
 * lands in does.
 *
 * The dynamic/ondemand spare-server counts are DERIVED from max_children here
 * (not stored) so the form only has to expose the one number that matters.
 */
final class SitePhpFpmPoolConfigBuilder
{
    public function build(Site $site, Server $server): string
    {
        $name = $site->phpFpmPoolName();
        $socket = $site->phpFpmListenSocketPath();
        $settings = $site->phpFpmPoolSettings();

        $user = $site->effectiveSystemUser($server);
        // The socket must be reachable by the webserver. nginx runs as www-data;
        // Caddy runs as `caddy` but is a member of www-data. Owning the socket
        // www-data:www-data 0660 lets both connect while keeping it off-limits to
        // unrelated users.
        $webGroup = (string) config('site_settings.vm_site_file_web_group', 'www-data');

        $lines = [];
        $lines[] = "; Managed by Dply — dedicated pool for {$name} (do not edit by hand)";
        $lines[] = "[{$name}]";
        $lines[] = "user = {$user}";
        $lines[] = "group = {$webGroup}";
        $lines[] = "listen = {$socket}";
        $lines[] = "listen.owner = {$webGroup}";
        $lines[] = "listen.group = {$webGroup}";
        $lines[] = 'listen.mode = 0660';
        $lines[] = '';

        $pm = $settings['pm'];
        $maxChildren = $settings['max_children'];
        $lines[] = "pm = {$pm}";
        $lines[] = "pm.max_children = {$maxChildren}";

        if ($pm === 'dynamic') {
            // start = ~1/4 of the ceiling, spare band 1/4..1/2, all clamped to
            // [1, max_children] and ordered start within [min,max].
            $start = max(1, (int) round($maxChildren / 4));
            $minSpare = max(1, (int) round($maxChildren / 4));
            $maxSpare = max($minSpare, (int) round($maxChildren / 2));
            $start = min(max($start, $minSpare), $maxSpare);
            $lines[] = "pm.start_servers = {$start}";
            $lines[] = "pm.min_spare_servers = {$minSpare}";
            $lines[] = "pm.max_spare_servers = {$maxSpare}";
        } elseif ($pm === 'ondemand') {
            $lines[] = 'pm.process_idle_timeout = 10s';
        }

        $lines[] = "pm.max_requests = {$settings['max_requests']}";
        $lines[] = '';
        $lines[] = "request_terminate_timeout = {$settings['request_terminate_timeout']}s";
        // Surface fatals/timeouts in the pool log instead of swallowing them.
        $lines[] = 'catch_workers_output = yes';
        $lines[] = '';

        // Per-request reference correlation. nginx passes the request uuid as the
        // REQUEST_ID fastcgi param; logging it here (with an epoch + the request
        // line) lets a 5xx reference code resolve to the exact request, which is
        // then time-correlated to the app's error log. The FPM master (root) opens
        // these files, so the pool user needs no write access to the directory.
        $lines[] = "access.log = {$site->phpFpmAccessLogPath()}";
        $lines[] = 'access.format = "ref=%{REQUEST_ID}e t=%{%s}t at=%{%Y-%m-%dT%H:%M:%S}t %m %r%Q%q dur=%{milli}dms status=%s"';
        $lines[] = "php_admin_value[error_log] = {$site->phpFpmPoolErrorLogPath()}";
        $lines[] = 'php_admin_flag[log_errors] = on';

        return implode("\n", $lines)."\n";
    }
}
