<?php

declare(strict_types=1);

namespace App\Services\Sites\Concerns;

use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Support\Sites\SiteAccessGateConfigSupport;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsNginxBasicAuthFragments
{


    /**
     * @return array{preamble: string, prefix_locations: string, location_slash_auth: string}
     */
    protected function nginxBasicAuthPhpFragments(Site $site, string $root, string $phpSock, string $fcgiEngine): array
    {
        if (! $this->nginxBasicAuthEnabled($site)) {
            return ['preamble' => '', 'prefix_locations' => '', 'location_slash_auth' => ''];
        }

        $preamble = $this->nginxBasicAuthAcmeChallengeBlock($root);
        $prefix = $site->basicAuthSupportsPathPrefixes()
            ? $this->nginxBasicAuthPhpPrefixLocations($site, $phpSock, $fcgiEngine)
            : '';
        $slashAuth = '';
        if ($site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $slashAuth = $this->nginxBasicAuthDirectives($site->basicAuthHtpasswdPathForNormalizedPath('/'));
        }

        return ['preamble' => $preamble, 'prefix_locations' => $prefix, 'location_slash_auth' => $slashAuth];
    }

    /**
     * @return array{preamble: string, prefix_locations: string, location_slash_auth: string}
     */
    protected function nginxBasicAuthStaticFragments(Site $site, string $root): array
    {
        if (! $this->nginxBasicAuthEnabled($site)) {
            return ['preamble' => '', 'prefix_locations' => '', 'location_slash_auth' => ''];
        }

        $preamble = $this->nginxBasicAuthAcmeChallengeBlock($root);
        $prefix = $site->basicAuthSupportsPathPrefixes()
            ? $this->nginxBasicAuthStaticPrefixLocations($site)
            : '';
        $slashAuth = '';
        if ($site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $slashAuth = $this->nginxBasicAuthDirectives($site->basicAuthHtpasswdPathForNormalizedPath('/'));
        }

        return ['preamble' => $preamble, 'prefix_locations' => $prefix, 'location_slash_auth' => $slashAuth];
    }

    /**
     * @return array{preamble: string, prefix_locations: string, location_slash_auth: string}
     */
    protected function nginxBasicAuthNodeFragments(Site $site, string $webRoot): array
    {
        if (! $this->nginxBasicAuthEnabled($site)) {
            return ['preamble' => '', 'prefix_locations' => '', 'location_slash_auth' => ''];
        }

        $preamble = $webRoot !== '' ? $this->nginxBasicAuthAcmeChallengeBlock($webRoot) : '';
        $slashAuth = '';
        if ($site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $slashAuth = $this->nginxBasicAuthDirectives($site->basicAuthHtpasswdPathForNormalizedPath('/'));
        }

        return ['preamble' => $preamble, 'prefix_locations' => '', 'location_slash_auth' => $slashAuth];
    }

    /**
     * @return array{preamble: string, location_slash_auth: string, named_location_auth: string}
     */
    protected function nginxBasicAuthOctaneFragments(Site $site, string $root): array
    {
        if (! $this->nginxBasicAuthEnabled($site)) {
            return ['preamble' => '', 'location_slash_auth' => '', 'named_location_auth' => ''];
        }

        $preamble = $this->nginxBasicAuthAcmeChallengeBlock($root);
        $auth = '';
        if ($site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/')) {
            $auth = $this->nginxBasicAuthDirectives($site->basicAuthHtpasswdPathForNormalizedPath('/'));
        }

        return [
            'preamble' => $preamble,
            'location_slash_auth' => $auth,
            'named_location_auth' => $auth,
        ];
    }

    protected function nginxBasicAuthEnabled(Site $site): bool
    {
        if (SiteAccessGateConfigSupport::usesFormPasswordGate($site)) {
            return false;
        }

        return $site->enforceableBasicAuthUsers()->isNotEmpty();
    }

    protected function nginxBasicAuthAcmeChallengeBlock(string $root): string
    {
        return <<<NGINX
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        default_type "text/plain";
        root {$root};
        try_files \$uri =404;
    }

NGINX;
    }

    protected function nginxBasicAuthDirectives(string $htpasswdAbsolutePath): string
    {
        return "        auth_basic \"Restricted\";\n        auth_basic_user_file {$htpasswdAbsolutePath};\n";
    }

    protected function nginxBasicAuthPhpPrefixLocations(Site $site, string $phpSock, string $fcgiEngine): string
    {
        $paths = $site->enforceableBasicAuthUsers()
            ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
            ->unique()
            ->filter(fn (string $p): bool => $p !== '/')
            ->sortByDesc(fn (string $p): int => strlen($p))
            ->values();

        $out = '';
        foreach ($paths as $locPath) {
            $has = $site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === $locPath);
            if (! $has) {
                continue;
            }
            $escaped = $this->escapeNginxLocationPrefix($locPath);
            $htpasswd = $site->basicAuthHtpasswdPathForNormalizedPath($locPath);
            $auth = $this->nginxBasicAuthDirectives($htpasswd);
            $out .= <<<NGINX
    location ^~ {$escaped} {
{$auth}        try_files \$uri \$uri/ /index.php?\$query_string;
        location ~ \.php\$ {
            include snippets/fastcgi-php.conf;
            fastcgi_param REQUEST_ID \$request_id;
            fastcgi_pass unix:{$phpSock};
{$fcgiEngine}        }
    }

NGINX;
        }

        return $out;
    }

    protected function nginxBasicAuthStaticPrefixLocations(Site $site): string
    {
        $paths = $site->enforceableBasicAuthUsers()
            ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
            ->unique()
            ->filter(fn (string $p): bool => $p !== '/')
            ->sortByDesc(fn (string $p): int => strlen($p))
            ->values();

        $out = '';
        foreach ($paths as $locPath) {
            $has = $site->enforceableBasicAuthUsers()->contains(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === $locPath);
            if (! $has) {
                continue;
            }
            $escaped = $this->escapeNginxLocationPrefix($locPath);
            $htpasswd = $site->basicAuthHtpasswdPathForNormalizedPath($locPath);
            $auth = $this->nginxBasicAuthDirectives($htpasswd);
            $out .= <<<NGINX
    location ^~ {$escaped} {
{$auth}        try_files \$uri \$uri/ =404;
    }

NGINX;
        }

        return $out;
    }

    protected function escapeNginxLocationPrefix(string $path): string
    {
        $p = SiteBasicAuthUser::normalizePath($path);
        if ($p === '/') {
            return '/';
        }

        return $p;
    }

    protected function escapeNginxDoubleQuoted(string $url): string
    {
        return str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $url);
    }
}
