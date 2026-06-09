<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Per-site PHP ini emitter.
 *
 * The site stores operator-set PHP limits in `meta['php_runtime']`. This
 * service is the single place that turns that shape into a `PHP_VALUE`
 * FastCGI environment variable, which the PHP CGI/FastCGI SAPI reads and
 * applies as per-request ini overrides — no per-site FPM pool required.
 *
 * Both `*SiteConfigBuilder` classes call into here so the translation lives
 * in one place. The nginx and Caddy forms differ only in syntax; the set of
 * directives (and their newline-joined `PHP_VALUE` payload) is identical.
 *
 * NOTE: `max_execution_time` here is a *soft* (CPU-time) limit under FPM. The
 * hard wall-clock kill is the pool-level `request_terminate_timeout`, which a
 * per-site pool (not this FastCGI path) would own.
 */
final class SitePhpRuntimeDirectivesBuilder
{
    /**
     * meta['php_runtime'] key => php.ini directive name, in emit order.
     *
     * @var array<string, string>
     */
    private const DIRECTIVES = [
        'memory_limit' => 'memory_limit',
        'upload_max_filesize' => 'upload_max_filesize',
        'post_max_size' => 'post_max_size',
        'max_execution_time' => 'max_execution_time',
        'max_input_time' => 'max_input_time',
        'max_input_vars' => 'max_input_vars',
        'max_file_uploads' => 'max_file_uploads',
        'timezone' => 'date.timezone',
    ];

    /**
     * The `fastcgi_param PHP_VALUE` line for an nginx `location ~ \.php$`
     * block, indented to match the sibling cache directives. Empty string
     * when the site has no PHP limits set.
     */
    public function nginxDirectives(Site $site): string
    {
        $payload = $this->phpValuePayload($site);
        if ($payload === '') {
            return '';
        }

        return "        fastcgi_param PHP_VALUE \"{$payload}\";\n";
    }

    /**
     * The `env PHP_VALUE` line for a Caddy `php_fastcgi` block. Uses a
     * backtick token so the newline-joined payload survives verbatim. Empty
     * string when the site has no PHP limits set.
     */
    public function caddyEnvDirective(Site $site): string
    {
        $payload = $this->phpValuePayload($site);
        if ($payload === '') {
            return '';
        }

        return "        env PHP_VALUE `{$payload}`\n";
    }

    /**
     * Parse a PHP shorthand byte value (e.g. `512M`, `1G`, `64K`) into bytes.
     * `0` is returned for empty/unparseable input; PHP treats `0` as unlimited
     * for size directives, so callers comparing limits should special-case it.
     */
    public static function shorthandBytes(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $m)) {
            return 0;
        }

        $bytes = (int) $m[1];

        return match (strtoupper($m[2])) {
            'K' => $bytes * 1024,
            'M' => $bytes * 1024 * 1024,
            'G' => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }

    /**
     * Newline-joined `name=value` directives for the `PHP_VALUE` env var, or
     * an empty string when nothing is configured. Continuation lines sit at
     * column 0 deliberately: the CGI SAPI splits on `\n` and the leading
     * whitespace of an indented heredoc would otherwise corrupt the value.
     */
    private function phpValuePayload(Site $site): string
    {
        $runtime = is_array($site->meta['php_runtime'] ?? null) ? $site->meta['php_runtime'] : [];

        $lines = [];
        foreach (self::DIRECTIVES as $key => $directive) {
            $value = $runtime[$key] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = trim((string) $value);
            // Reject anything that could break out of the quoted token.
            if ($value === '' || preg_match('/["`\n\r]/', $value)) {
                continue;
            }
            $lines[] = "{$directive}={$value}";
        }

        return implode("\n", $lines);
    }
}
