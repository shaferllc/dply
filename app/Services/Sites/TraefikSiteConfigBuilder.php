<?php

namespace App\Services\Sites;

use App\Models\Site;

class TraefikSiteConfigBuilder
{
    public function build(Site $site, int $backendPort): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains']);

        $hostnames = collect($site->webserverHostnames())
            ->filter()
            ->unique()
            ->values();
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Traefik.');
        }

        $basename = $site->webserverConfigBasename();
        $hostRule = $hostnames
            ->map(fn (string $hostname): string => sprintf('Host(`%s`)', $hostname))
            ->implode(' || ');

        return <<<YAML
http:
  routers:
    {$basename}:
      entryPoints:
        - web
      rule: "{$hostRule}"
      service: {$basename}
  services:
    {$basename}:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:{$backendPort}"
YAML;
    }
}
