<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Path-level helpers shared by the server and site file browsers:
 *
 *  - normalize() collapses relative segments and rejects anything that
 *    isn't an absolute path the SSH layer can hand to `ls`/`cat`/`stat`.
 *  - matchesSensitiveGlob() classifies a resolved path against the
 *    sensitive-glob list from config so opens of secret-bearing files
 *    can be logged.
 *  - isInsideReleases() detects when an edit target resolves under a
 *    site's atomic `releases/<id>/` tree (the next deploy will wipe it).
 */
class FileBrowserPathPolicy
{
    /**
     * Validate + canonicalize an absolute remote path. Rejects empty paths,
     * paths with NUL bytes, and any path that doesn't start with `/`.
     * Strips trailing slash (except for "/" itself) and collapses `//` runs.
     */
    public static function normalize(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('File browser path must be an absolute Unix path.');
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * Parent of the given path, or "/" at the root.
     */
    public static function parent(string $path): string
    {
        $path = self::normalize($path);
        if ($path === '/') {
            return '/';
        }
        $parent = substr($path, 0, strrpos($path, '/') ?: 0);

        return $parent === '' ? '/' : $parent;
    }

    /**
     * Join a parent dir + a single entry name, validating the entry name
     * does not contain `/` or `..`.
     */
    public static function join(string $dir, string $entry): string
    {
        if ($entry === '' || str_contains($entry, '/') || str_contains($entry, "\0") || $entry === '..' || $entry === '.') {
            throw new \InvalidArgumentException('File browser entry name is invalid.');
        }

        $dir = self::normalize($dir);

        return $dir === '/' ? '/'.$entry : $dir.'/'.$entry;
    }

    /**
     * @param  array<string, mixed> $patterns
     */
    public static function matchesSensitiveGlob(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if (fnmatch($pattern, $path, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when path lives anywhere under a `releases/` subtree rooted at
     * $siteRoot. Used to warn before saving edits the next deploy will wipe.
     */
    public static function isInsideReleases(string $path, string $siteRoot): bool
    {
        $path = self::normalize($path);
        $siteRoot = self::normalize($siteRoot);
        $prefix = ($siteRoot === '/' ? '' : $siteRoot).'/releases/';

        return str_starts_with($path, $prefix);
    }

    /**
     * Soft check: a path is "inside" a given root (subject to the root + "/" boundary).
     * Used to scope server-browser nav defaults and the site-browser badge logic.
     */
    public static function isInside(string $path, string $root): bool
    {
        $path = self::normalize($path);
        $root = self::normalize($root);

        if ($root === '/') {
            return true;
        }

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
