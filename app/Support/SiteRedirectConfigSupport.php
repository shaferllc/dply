<?php

namespace App\Support;

final class SiteRedirectConfigSupport
{
    /**
     * Common HTTP redirect status codes surfaced in the product UI.
     *
     * @return list<int>
     */
    public static function allowedHttpRedirectStatusCodes(): array
    {
        return [301, 302, 303, 307, 308];
    }

    /**
     * @param  mixed  $raw  JSON-decoded array or null
     * @return list<array{name: string, value: string}>
     */
    public static function normalizeResponseHeaders(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            $value = isset($row['value']) ? trim((string) $row['value']) : '';
            if ($name === '' || $value === '') {
                continue;
            }
            if (! self::isValidHeaderName($name)) {
                continue;
            }
            if (! self::isValidHeaderValue($value)) {
                continue;
            }
            if (self::isForbiddenResponseHeaderName($name)) {
                continue;
            }
            $out[] = ['name' => $name, 'value' => $value];
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    public static function isValidHeaderName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9!#$&\-\^_`|~]+$/', $name);
    }

    public static function isValidHeaderValue(string $value): bool
    {
        if (strlen($value) > 8192) {
            return false;
        }

        return ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
    }

    public static function isForbiddenResponseHeaderName(string $name): bool
    {
        $l = strtolower($name);

        return in_array($l, [
            'connection',
            'content-length',
            'date',
            'transfer-encoding',
            'upgrade',
        ], true);
    }

    public static function escapeNginxHeaderDirectiveValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public static function escapeApacheHeaderConfigValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public static function escapeCaddyHeaderValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Normalized absolute path safe for web server snippets (no regex metacharacters).
     */
    public static function sanitizeFromPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        if (! preg_match('#^/[a-zA-Z0-9/_\-]+$#', $path)) {
            return '';
        }

        return $path;
    }

    /**
     * Internal rewrite target: absolute path on the same site (browser URL unchanged).
     */
    public static function sanitizeInternalTarget(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');
        if (! preg_match('#^/$|^/[a-zA-Z0-9/_\-]+$#', $path)) {
            return '';
        }

        return $path;
    }

    public static function escapeNginxRewriteReplacement(string $replacement): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\$'], $replacement);
    }

    public static function escapeApacheRewriteSubstitution(string $substitution): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\$'], $substitution);
    }
}
