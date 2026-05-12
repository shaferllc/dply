<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * Renders the monolithic `/etc/haproxy/haproxy.cfg` for a dply-edged
 * HAProxy server. HAProxy doesn't have a file-watcher provider like
 * Traefik does, so we generate the whole config in one shot covering
 * all sites + the bind port. The switch flow writes :8080 during
 * validate/provision and :80 at cutover.
 *
 * Architecture: one `frontend dply_http` block bound to $listenPort,
 * one ACL per site keyed by Host header, one `use_backend` per match,
 * and one `backend` block per site pointing at the per-site Caddy
 * upstream on its ephemeral high port. Hostname-to-ACL is done with
 * `hdr(host) -i` (case-insensitive exact match) plus a `:port`-stripped
 * variant to cover Host headers that include explicit ports.
 */
class HAProxyEdgeConfigBuilder
{
    /**
     * @param  Collection<int, Site>  $sites
     * @param  callable(Site): int  $backendPortFor  Resolves the per-site Caddy upstream port.
     */
    public function build(Collection $sites, int $listenPort, callable $backendPortFor): string
    {
        $aclLines = [];
        $useBackendLines = [];
        $backendBlocks = [];

        foreach ($sites as $site) {
            $basename = $this->basenameFor($site);
            $aclName = 'host_'.$this->sanitize($basename);
            $backendName = 'bk_'.$this->sanitize($basename);
            $port = (int) $backendPortFor($site);
            $hostnames = $this->hostnamesFor($site);
            if ($hostnames === []) {
                continue;
            }

            // One ACL line per hostname (alias). hdr(host) is case-insensitive
            // with the -i flag; we strip any :port suffix in the Host header
            // before matching so requests to example.com:80 still hit.
            foreach ($hostnames as $h) {
                $aclLines[] = sprintf('    acl %s hdr(host) -i %s', $aclName, $h);
                $aclLines[] = sprintf('    acl %s hdr(host) -i %s:%d', $aclName, $h, $listenPort);
            }
            $useBackendLines[] = sprintf('    use_backend %s if %s', $backendName, $aclName);

            $backendBlocks[] = <<<HAPROXY
backend {$backendName}
    mode http
    server caddy 127.0.0.1:{$port} check inter 5s fall 3 rise 2
HAPROXY;
        }

        $aclBlock = $aclLines !== [] ? implode("\n", $aclLines)."\n" : '';
        $useBackendBlock = $useBackendLines !== [] ? implode("\n", $useBackendLines)."\n" : '';
        $backendSection = $backendBlocks !== [] ? "\n".implode("\n\n", $backendBlocks) : '';

        return <<<HAPROXY
# Managed by Dply — do NOT hand-edit. Regenerated on every webserver switch.
global
    log /dev/log local0
    log /dev/log local1 notice
    chroot /var/lib/haproxy
    stats socket /run/haproxy/admin.sock mode 660 level admin
    stats timeout 30s
    user haproxy
    group haproxy
    daemon

defaults
    log     global
    mode    http
    option  httplog
    option  dontlognull
    timeout connect 5s
    timeout client  60s
    timeout server  60s

frontend dply_http
    bind *:{$listenPort}
    mode http
{$aclBlock}{$useBackendBlock}    # No matching ACL → return a clear 503 instead of HAProxy's default
    # blank-page error. Operator can spot misconfigured DNS / missing host
    # rules from the response body.
    http-request return status 503 content-type "text/plain" string "dply: no backend matches this host\\n"
{$backendSection}
HAPROXY;
    }

    /**
     * @return list<string>
     */
    private function hostnamesFor(Site $site): array
    {
        return collect($site->webserverHostnames())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function basenameFor(Site $site): string
    {
        return method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;
    }

    /**
     * HAProxy backend/ACL names accept [a-zA-Z0-9_-.] (and ':'). Strip
     * anything else from the site basename so we always produce a syntactically
     * valid identifier even when the site name contains slashes / spaces / dots.
     */
    private function sanitize(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $s) ?? 'site';
    }
}
