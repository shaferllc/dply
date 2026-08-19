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
 *  - isInsideReleases() / willBeOverwrittenOnDeploy() detect edit targets
 *    the next deploy will replace (atomic `releases/`/`current/`, or the
 *    simple checkout — not `shared/`).
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
     * @param  array<string, mixed>  $patterns
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
     * True when path is the atomic `current` symlink (or anything under it)
     * before the browser has resolved the link into `releases/<id>/`.
     */
    public static function isInsideCurrent(string $path, string $siteRoot): bool
    {
        $path = self::normalize($path);
        $siteRoot = self::normalize($siteRoot);
        $current = ($siteRoot === '/' ? '' : $siteRoot).'/current';

        return $path === $current || str_starts_with($path, $current.'/');
    }

    /**
     * True when path lives in the atomic `shared/` tree (survives deploys).
     */
    public static function isInsideShared(string $path, string $siteRoot): bool
    {
        $path = self::normalize($path);
        $siteRoot = self::normalize($siteRoot);
        $shared = ($siteRoot === '/' ? '' : $siteRoot).'/shared';

        return $path === $shared || str_starts_with($path, $shared.'/');
    }

    /**
     * True when the next site deploy will replace this path on disk.
     *
     * Atomic: `releases/` and `current/` are swapped; `shared/` is durable.
     * Simple: the live checkout is the deploy tree, so anything under the
     * site root except `shared/` is overwritten.
     */
    public static function willBeOverwrittenOnDeploy(string $path, string $siteRoot, bool $atomic): bool
    {
        $path = self::normalize($path);
        $siteRoot = self::normalize($siteRoot);

        if (! self::isInside($path, $siteRoot)) {
            return false;
        }

        if (self::isInsideShared($path, $siteRoot)) {
            return false;
        }

        if (! $atomic) {
            return true;
        }

        return self::isInsideReleases($path, $siteRoot)
            || self::isInsideCurrent($path, $siteRoot);
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

    /**
     * Resolve a symlink's stored target to an absolute path relative to the
     * link's location. Collapses `.` / `..` segments. Does not touch the
     * remote filesystem — callers must still enforce site/server roots.
     */
    public static function resolveLinkTarget(string $linkPath, string $target): string
    {
        $linkPath = self::normalize($linkPath);
        $target = trim($target);

        if ($target === '' || str_contains($target, "\0")) {
            throw new \InvalidArgumentException('File browser symlink target is invalid.');
        }

        if ($target[0] === '/') {
            return self::canonicalize($target);
        }

        $base = self::parent($linkPath);

        return self::canonicalize(($base === '/' ? '' : $base).'/'.$target);
    }

    /**
     * Collapse `.` / `..` in an absolute path without resolving remote symlinks.
     */
    public static function canonicalize(string $path): string
    {
        $path = self::normalize($path);
        $stack = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($stack);

                continue;
            }

            $stack[] = $segment;
        }

        return $stack === [] ? '/' : '/'.implode('/', $stack);
    }
}
