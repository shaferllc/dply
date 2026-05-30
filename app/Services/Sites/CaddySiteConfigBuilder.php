<?php

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Services\Servers\ServerPhpManager;
use App\Support\SiteRedirectConfigSupport;
use Illuminate\Support\Collection;

class CaddySiteConfigBuilder
{
    public function build(Site $site, ?int $listenPort = null): string
    {
        if ($site->type === SiteType::Custom) {
            return '';
        }

        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'basicAuthUsers']);

        $hostnames = collect($site->webserverHostnames())
            ->filter()
            ->unique()
            ->values();
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Caddy.');
        }

        // Bind each hostname to the listen port (e.g. example.test:8080). A bare
        // `:8080` site block is catch-all on that port — importing several of
        // those during a webserver switch makes `caddy validate` fail with
        // "ambiguous site definition". Nginx/Apache keep server names and only
        // change the listen port; Caddy needs host:port pairs for the same model.
        $hosts = $listenPort === null
            ? $hostnames->implode(', ')
            : $hostnames->map(fn (string $host): string => $host.':'.$listenPort)->implode(', ');
        $basename = $site->webserverConfigBasename();

        if ($site->isSuspended()) {
            return $this->suspendedSiteBlock($hosts, $basename, $site);
        }

        $root = $site->effectiveDocumentRoot();
        $site->loadMissing('server');
        $phpVersion = $site->server !== null
            ? app(ServerPhpManager::class)->resolveCaddyPhpVersion($site->server, $site->phpVersion())
            : ($site->phpVersion() ?? '8.3');
        $phpSock = str_replace(
            '{version}',
            $phpVersion,
            config('sites.php_fpm_socket')
        );
        $redirectLines = $this->redirectLines($site);
        $basicAuth = $this->caddyBasicAuthBlocks($site);
        $dotfileDeny = $this->caddyDotfileDenyBlock();

        if ($site->type === SiteType::Php && $site->octane_port) {
            $port = (int) $site->octane_port;
            $reverb = $this->reverbHandleDirective($site);

            return <<<CADDY
{$hosts} {
{$redirectLines}{$reverb}{$basicAuth}{$dotfileDeny}    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    reverse_proxy 127.0.0.1:{$port}
}
CADDY;
        }

        $reverbPhp = $site->type === SiteType::Php ? $this->reverbHandleDirective($site) : '';

        return match ($site->type) {
            SiteType::Php => <<<CADDY
{$hosts} {
{$redirectLines}{$reverbPhp}{$basicAuth}{$dotfileDeny}    root * {$root}
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    php_fastcgi unix//{$phpSock}
    file_server
}
CADDY,
            SiteType::Static => <<<CADDY
{$hosts} {
{$redirectLines}{$basicAuth}{$dotfileDeny}    root * {$root}
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    file_server
}
CADDY,
            SiteType::Node => <<<CADDY
{$hosts} {
{$redirectLines}{$basicAuth}{$dotfileDeny}    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    reverse_proxy 127.0.0.1:{$site->app_port}
}
CADDY,
            SiteType::Custom => '',
        };
    }

    /**
     * Block any path with a leading dot segment ( /.env, /.git, etc. ), but
     * still allow `/.well-known/` for ACME challenges and the like.
     * Mirrors the Nginx and Apache default deny rules.
     *
     * Caddy's regex engine is Go's RE2, which has no lookaround — so the
     * `well-known` exclusion is expressed as a separate `not path` matcher
     * AND'd with the dotfile regex.
     */
    protected function caddyDotfileDenyBlock(): string
    {
        return <<<'CADDY'
    @dply_dotfiles {
        path_regexp dotfiles (?i)(^|/)\.
        not path */.well-known*
    }
    respond @dply_dotfiles 403

CADDY;
    }

    /**
     * Emit one or more `basic_auth` directives — site-wide and (when supported) per
     * path prefix. Caddy v2 expects bcrypt hashes inline; rows whose `password_hash`
     * is not bcrypt are skipped with a config comment so the operator can rotate
     * them and re-apply.
     */
    protected function caddyBasicAuthBlocks(Site $site): string
    {
        $users = $site->enforceableBasicAuthUsers();
        if ($users->isEmpty()) {
            return '';
        }

        $output = '';

        $rootUsers = $users->filter(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === '/');
        if ($rootUsers->isNotEmpty()) {
            $output .= $this->caddyBasicAuthBlock($rootUsers, null);
        }

        if ($site->basicAuthSupportsPathPrefixes()) {
            $paths = $users
                ->map(fn (SiteBasicAuthUser $u): string => $u->normalizedPath())
                ->unique()
                ->filter(fn (string $p): bool => $p !== '/')
                ->sortByDesc(fn (string $p): int => strlen($p))
                ->values();

            foreach ($paths as $locPath) {
                $groupUsers = $users->filter(fn (SiteBasicAuthUser $u): bool => $u->normalizedPath() === $locPath);
                if ($groupUsers->isEmpty()) {
                    continue;
                }
                $matcher = rtrim($locPath, '/').'/*';
                $output .= $this->caddyBasicAuthBlock($groupUsers, $matcher);
            }
        }

        return $output;
    }

    /**
     * Render a single `basic_auth [matcher] { ... }` block. Bcrypt-only entries
     * are emitted inline; other algorithms (apr1, sha) are listed in a leading
     * comment because Caddy v2 cannot enforce them.
     */
    protected function caddyBasicAuthBlock(Collection $users, ?string $matcher): string
    {
        $bcryptUsers = $users->filter(fn (SiteBasicAuthUser $u): bool => $this->caddyHashIsBcrypt((string) $u->password_hash));
        $skipped = $users->reject(fn (SiteBasicAuthUser $u): bool => $this->caddyHashIsBcrypt((string) $u->password_hash));

        $comment = '';
        if ($skipped->isNotEmpty()) {
            $names = $skipped->map(fn (SiteBasicAuthUser $u): string => (string) $u->username)->implode(', ');
            $comment = "    # dply: skipped non-bcrypt hash(es) for {$names} — rotate the password to enforce on Caddy.\n";
        }

        if ($bcryptUsers->isEmpty()) {
            return $comment;
        }

        $matcherText = $matcher !== null ? ' '.$matcher : '';
        $userLines = $bcryptUsers
            ->map(fn (SiteBasicAuthUser $u): string => '        '.$u->username.' '.$u->password_hash)
            ->implode("\n");

        return $comment."    basic_auth{$matcherText} {\n{$userLines}\n    }\n";
    }

    private function caddyHashIsBcrypt(string $hash): bool
    {
        return str_starts_with($hash, '$2y$')
            || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$2b$');
    }

    private function suspendedSiteBlock(string $hosts, string $basename, Site $site): string
    {
        $root = $site->suspendedStaticRoot();

        return <<<CADDY
{$hosts} {
    root * {$root}
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    file_server
}
CADDY;
    }

    private function reverbHandleDirective(Site $site): string
    {
        if (! $site->shouldProxyReverbInWebserver()) {
            return '';
        }

        $path = $site->reverbWebSocketPath();
        $port = $site->reverbLocalPort();

        return "    handle {$path}* {\n        reverse_proxy 127.0.0.1:{$port}\n    }\n";
    }

    private function redirectLines(Site $site): string
    {
        $lines = [];
        $matcherIndex = 0;
        foreach ($site->redirects->sortBy('sort_order') as $redirect) {
            $from = SiteRedirectConfigSupport::sanitizeFromPath((string) $redirect->from_path);
            if ($from === '') {
                continue;
            }
            $kind = $redirect->kind instanceof SiteRedirectKind ? $redirect->kind : SiteRedirectKind::Http;
            if ($kind === SiteRedirectKind::InternalRewrite) {
                $to = SiteRedirectConfigSupport::sanitizeInternalTarget((string) $redirect->to_url);
                if ($to === '') {
                    continue;
                }
                $lines[] = "    rewrite {$from} {$to}";

                continue;
            }
            $code = (int) $redirect->status_code;
            if (! in_array($code, SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes(), true)) {
                continue;
            }
            $to = trim((string) $redirect->to_url);
            if ($to === '') {
                continue;
            }
            $headers = SiteRedirectConfigSupport::normalizeResponseHeaders($redirect->response_headers ?? null);
            if ($headers !== []) {
                $name = 'dply_redir_'.$matcherIndex;
                $matcherIndex++;
                $block = "    @{$name} path {$from}\n    handle @{$name} {\n";
                foreach ($headers as $h) {
                    $hv = SiteRedirectConfigSupport::escapeCaddyHeaderValue($h['value']);
                    $block .= "        header {$h['name']} \"{$hv}\"\n";
                }
                $block .= "        redir {$to} {$code}\n    }";
                $lines[] = $block;
            } else {
                $lines[] = "    redir {$from} {$to} {$code}";
            }
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines)."\n";
    }
}
