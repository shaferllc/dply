<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Support\Sites\SiteAccessGateConfigSupport;

class TraefikSiteConfigBuilder
{
    public function build(Site $site, int $backendPort): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'basicAuthUsers', 'accessGate']);

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

        $formGateGroups = SiteAccessGateConfigSupport::traefikFormGateGroups($site, $basename);
        $authGroups = $formGateGroups === []
            ? $this->traefikBasicAuthGroups($site, $basename)
            : [];

        $rootMiddlewareName = null;
        $prefixGroups = [];
        foreach ($authGroups as $group) {
            if ($group['path'] === '/') {
                $rootMiddlewareName = $group['middleware'];
            } else {
                $prefixGroups[] = $group;
            }
        }

        if ($formGateGroups !== []) {
            $rootMiddlewareName = $formGateGroups[0]['middleware'];
        }

        $middlewaresYaml = $this->renderMiddlewaresYaml($authGroups, $formGateGroups, $backendPort);
        $extraRoutersYaml = $this->renderPrefixRoutersYaml($prefixGroups, $basename, $hostRule);
        $defaultRouterMiddlewaresYaml = $rootMiddlewareName !== null
            ? "      middlewares:\n        - {$rootMiddlewareName}\n"
            : '';

        return <<<YAML
http:
{$middlewaresYaml}  routers:
{$extraRoutersYaml}    {$basename}:
      entryPoints:
        - web
      rule: "{$hostRule}"
      service: {$basename}
{$defaultRouterMiddlewaresYaml}  services:
    {$basename}:
      loadBalancer:
        servers:
          - url: "http://127.0.0.1:{$backendPort}"
YAML;
    }

    /**
     * Build [path, middleware-name, htpasswd-path] entries for each enforceable
     * basic-auth path group. Returns an empty array when there are none, in which
     * case the caller emits a stripped-down YAML with no `middlewares:` block.
     *
     * @return array<int, array{path: string, middleware: string, users_file: string}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, string>>
     */
    protected function traefikBasicAuthGroups(Site $site, string $basename): array
    {
        if (SiteAccessGateConfigSupport::usesFormPasswordGate($site)) {
            return [];
        }

        $users = $site->enforceableBasicAuthUsers();
        if ($users->isEmpty()) {
            return [];
        }

        $rootGroup = $users->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')
            ? [['path' => '/', 'middleware' => $basename.'-auth-root', 'users_file' => $site->basicAuthHtpasswdPathForNormalizedPath('/')]]
            : [];

        $prefixGroups = [];
        if ($site->basicAuthSupportsPathPrefixes()) {
            $paths = $users
                ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
                ->unique()
                ->filter(fn (string $p): bool => $p !== '/')
                ->sortByDesc(fn (string $p): int => strlen($p))
                ->values();

            foreach ($paths as $locPath) {
                $hash = substr(hash('sha256', SiteBasicAuthUser::normalizePath($locPath)), 0, 16);
                $prefixGroups[] = [
                    'path' => $locPath,
                    'middleware' => $basename.'-auth-'.$hash,
                    'users_file' => $site->basicAuthHtpasswdPathForNormalizedPath($locPath),
                ];
            }
        }

        return array_merge($rootGroup, $prefixGroups);
    }

    /**
     * @param  array<string, mixed> $groups
     * @param  array<string, mixed> $formGateGroups
     */
    private function renderMiddlewaresYaml(array $groups, array $formGateGroups, int $backendPort): string
    {
        if ($groups === [] && $formGateGroups === []) {
            return '';
        }

        $lines = "  middlewares:\n";
        foreach ($groups as $g) {
            $lines .= "    {$g['middleware']}:\n"
                ."      basicAuth:\n"
                ."        usersFile: \"{$g['users_file']}\"\n"
                ."        realm: \"Restricted\"\n";
        }

        foreach ($formGateGroups as $g) {
            $lines .= "    {$g['middleware']}:\n"
                ."      forwardAuth:\n"
                ."        address: \"http://127.0.0.1:{$backendPort}/__dply/access/verify\"\n"
                ."        trustForwardHeader: true\n";
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed> $prefixGroups
     */
    private function renderPrefixRoutersYaml(array $prefixGroups, string $basename, string $hostRule): string
    {
        if ($prefixGroups === []) {
            return '';
        }

        $out = '';
        $priority = 100 + count($prefixGroups);
        foreach ($prefixGroups as $g) {
            $rule = sprintf('(%s) && PathPrefix(`%s`)', $hostRule, rtrim($g['path'], '/'));
            $out .= "    {$g['middleware']}:\n"
                ."      entryPoints:\n"
                ."        - web\n"
                ."      rule: \"{$rule}\"\n"
                ."      service: {$basename}\n"
                ."      priority: {$priority}\n"
                ."      middlewares:\n"
                ."        - {$g['middleware']}\n";
            $priority--;
        }

        return $out;
    }
}
