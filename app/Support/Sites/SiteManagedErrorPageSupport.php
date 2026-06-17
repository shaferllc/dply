<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\SiteServerErrorPageBuilder;

/**
 * Managed 500-series error pages written under {@see Site::managedErrorPagesRoot()}
 * during provision and referenced from generated webserver configs.
 */
final class SiteManagedErrorPageSupport
{
    public const ERROR_URI = '/__dply__/errors/500.html';

    public const ERROR_FILENAME = '500.html';

    /**
     * Placeholder the served error page carries until the webserver swaps it for
     * the per-request reference id (nginx `sub_filter`). Kept in sync with
     * {@see SiteServerErrorPageBuilder::REFERENCE_TOKEN}.
     */
    public const REFERENCE_TOKEN = '{{DPLY_REF}}';

    /** Response header carrying the per-request reference id on every 5xx. */
    public const REFERENCE_HEADER = 'X-Dply-Ref';

    /**
     * Site meta key for the operator-only "expose raw server errors" switch.
     * Off by default. When set, the webserver no longer intercepts 5xx with the
     * branded {@see SiteServerErrorPageBuilder} page — the
     * real error passes through (framework debug page on an app 500, or the
     * webserver's own 502/503/504 when the upstream is down). For debugging a
     * failing app; visitors see the raw error while it's on.
     */
    public const META_EXPOSE_FLAG = 'expose_server_errors';

    /**
     * True when the operator has opted this site into raw 5xx pass-through.
     * Unlike {@see appDebugEnabled()} (which only frees app 500s and is driven
     * by the deployed .env), this also drops the 502/503/504 interception, so a
     * dead upstream surfaces the webserver's real Bad Gateway page instead of
     * the branded splash.
     */
    public static function serverErrorsExposed(Site $site): bool
    {
        $meta = ($site->meta );

        return (bool) ($meta[self::META_EXPOSE_FLAG] ?? false);
    }

    public static function root(Site $site): string
    {
        return $site->managedErrorPagesRoot();
    }

    public static function filePath(Site $site): string
    {
        return rtrim(self::root($site), '/').'/'.self::ERROR_FILENAME;
    }

    public static function nginxServerBlock(Site $site): string
    {
        // Operator opted into raw errors: emit no error_page wiring so 5xx (both
        // app responses and nginx's own 502/503/504) pass straight through.
        if (self::serverErrorsExposed($site)) {
            return '';
        }

        $uri = self::ERROR_URI;
        $file = self::filePath($site);
        $header = self::REFERENCE_HEADER;
        $token = self::REFERENCE_TOKEN;

        // `$request_id` is nginx's built-in per-request uuid. It is surfaced to
        // the visitor (sub_filter into the page body + X-Dply-Ref header) and is
        // the key the operator pastes back into dply to find the matching error.
        return <<<NGINX
    error_page 500 502 503 504 {$uri};
    location = {$uri} {
        internal;
        alias {$file};
        add_header {$header} \$request_id always;
        sub_filter '{$token}' \$request_id;
        sub_filter_once on;
    }

NGINX;
    }

    public static function nginxProxyInterceptErrors(): string
    {
        return "        proxy_intercept_errors on;\n";
    }

    public static function nginxFastcgiInterceptErrors(): string
    {
        return "        fastcgi_intercept_errors on;\n";
    }

    /**
     * True when the site's cached env has APP_DEBUG enabled. When it does, the
     * webserver should NOT intercept the app's 5xx responses with the branded
     * page — let Laravel's own debug error page through so the developer can see
     * the actual exception. (nginx-level 502s with no app response still hit
     * error_page regardless.)
     */
    public static function appDebugEnabled(Site $site): bool
    {
        $content = (string) ($site->env_file_content ?? '');
        if ($content === '') {
            return false;
        }

        $vars = app(DotEnvFileParser::class)->parse($content)['variables'] ?? [];
        $value = strtolower(trim((string) ($vars['APP_DEBUG'] ?? '')));

        return in_array($value, ['true', '1', 'on', 'yes'], true);
    }

    public static function apacheVirtualHostBlock(Site $site): string
    {
        if (self::serverErrorsExposed($site)) {
            return '';
        }

        $uri = self::ERROR_URI;
        $file = self::filePath($site);
        $header = self::REFERENCE_HEADER;

        // %{UNIQUE_ID}e is mod_unique_id's per-request token (header-only for now;
        // body injection via SSI is a follow-up — see docs/SERVER_ERROR_CODES.md).
        return <<<APACHE
    ErrorDocument 500 {$uri}
    ErrorDocument 502 {$uri}
    ErrorDocument 503 {$uri}
    ErrorDocument 504 {$uri}
    Alias {$uri} {$file}
    Header always set {$header} "%{UNIQUE_ID}e"

APACHE;
    }

    public static function apacheProxyErrorOverride(): string
    {
        return "    ProxyErrorOverride On\n";
    }

    public static function caddyBlock(Site $site): string
    {
        if (self::serverErrorsExposed($site)) {
            return '';
        }

        $root = self::root($site);
        $header = self::REFERENCE_HEADER;

        return <<<CADDY
    handle_errors {
        @server_error expression {http.error.status_code} >= 500
        handle @server_error {
            header {$header} {http.request.uuid}
            rewrite * /500.html
            root * {$root}
            file_server
        }
    }

CADDY;
    }

    public static function openLiteSpeedBlock(Site $site): string
    {
        if (self::serverErrorsExposed($site)) {
            return '';
        }

        $uri = self::ERROR_URI;
        $root = self::root($site);

        return <<<CONF
errorpage 500 {
  url {$uri}
}
errorpage 502 {
  url {$uri}
}
errorpage 503 {
  url {$uri}
}
errorpage 504 {
  url {$uri}
}
context /__dply__/errors/ {
  location                {$root}/
  allowBrowse             1
  indexFiles              500.html
}

CONF;
    }
}
