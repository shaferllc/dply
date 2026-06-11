<?php

declare(strict_types=1);

namespace Dply\NginxConfig;

/**
 * High-level helper for "read-back before overwrite": parse two NGINX configs
 * into structured directive trees and report what the current (on-disk) config
 * contains that an incoming (about-to-be-written) config does NOT — i.e. the
 * manual/foreign edits that an overwrite would silently destroy.
 *
 * The underlying {@see Crossplane} parser needs a real file and validates
 * directive context against a full nginx.conf grammar. Site vhost snippets live
 * *inside* nginx's `http {}` context, so a bare `server {}` is legal for us:
 * context/argument checking is therefore disabled and parsing runs single-file.
 *
 * NOTE: the lexer tolerates some malformed input (e.g. an unclosed brace is
 * implicitly closed at EOF), so {@see parse} is NOT a substitute for `nginx -t`.
 * Its job is structural comparison, not syntax certification.
 */
final class ConfigDiff
{
    /** Parser options suited to standalone vhost/server snippets. */
    private const PARSE_OPTIONS = [
        Parser::OPTION_SINGLE_FILE => true,
        Parser::OPTION_CHECK_CTX => false,
        Parser::OPTION_CHECK_ARGS => false,
        Parser::OPTION_COMMENTS => true,
        Parser::OPTION_CATCH_ERRORS => true,
    ];

    /**
     * Parse a config string into crossplane's payload:
     * `['status' => 'ok'|'failed', 'errors' => [...], 'config' => [...]]`.
     */
    public static function parse(string $config): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dply-ngx').'.conf';
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create a temporary file for nginx config parsing.');
        }

        try {
            file_put_contents($tmp, $config);

            return (new Crossplane)->parser()->parse($tmp, self::PARSE_OPTIONS);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Flatten a config string into a stable set of normalized directive
     * signatures, one per directive, each prefixed by its block path so that
     * directives in different contexts never collide. Comments are ignored.
     *
     * Example: `server > location / > fastcgi_pass unix:/run/php/app.sock`
     *
     * @return list<string>
     */
    public static function signatures(string $config): array
    {
        $payload = self::parse($config);
        $root = $payload['config'][0]['parsed'] ?? [];

        $signatures = self::walk(is_array($root) ? $root : []);

        // Stable, de-duplicated ordering makes set-difference deterministic.
        $signatures = array_values(array_unique($signatures));
        sort($signatures);

        return $signatures;
    }

    /**
     * Directive signatures present in $current but absent from $incoming — the
     * directives that overwriting the current config with the incoming one would
     * remove. An empty list means the incoming config is a structural superset
     * (nothing foreign would be lost).
     *
     * @return list<string>
     */
    public static function lostOnOverwrite(string $current, string $incoming): array
    {
        $currentSigs = self::signatures($current);
        $incomingSigs = self::signatures($incoming);

        return array_values(array_diff($currentSigs, $incomingSigs));
    }

    /**
     * Recursively flatten a parsed directive list into prefixed signatures.
     *
     * @param  list<array<string, mixed>>  $directives
     * @return list<string>
     */
    private static function walk(array $directives, string $prefix = ''): array
    {
        $out = [];

        foreach ($directives as $directive) {
            $name = (string) ($directive['directive'] ?? '');
            if ($name === '' || $name === '#') {
                continue; // skip comments / malformed nodes
            }

            $args = $directive['args'] ?? [];
            $signature = trim($name.' '.implode(' ', array_map('strval', is_array($args) ? $args : [])));
            $path = $prefix === '' ? $signature : $prefix.' > '.$signature;
            $out[] = $path;

            $block = $directive['block'] ?? null;
            if (is_array($block) && $block !== []) {
                $out = array_merge($out, self::walk($block, $path));
            }
        }

        return $out;
    }
}
