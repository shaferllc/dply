<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\Site;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Build cache for Edge sites. Persists per-site directories
 * (`node_modules`, `.next/cache`, framework caches) into the same R2
 * bucket that holds the artifacts, keyed by lockfile + node version
 * + repo_root so a different lock invalidates the cache.
 *
 * Flow per deploy (called from {@see EdgeBuildRunner}):
 *
 *   1. After clone, before Docker: try {@see restore()} — pulls
 *      cache/{site_id}/{cache_key}.tar.gz from R2 and extracts into
 *      the build directory.
 *   2. After a successful Docker build: {@see snapshot()} re-tars the
 *      cache paths and uploads. Runs inline (synchronous) in v1; can
 *      move to a background queue later if it becomes a deploy-time hotspot.
 *   3. {@see prune()} keeps total per-site cache bytes under the cap
 *      via LRU eviction.
 *
 * Cache is best-effort — any failure logs to the build log and the
 * deploy continues with a cold cache. Never blocks a deploy.
 */
class EdgeBuildCache
{
    /** Hard cap on total cache bytes per site before LRU pruning. */
    private const DEFAULT_MAX_BYTES = 500 * 1024 * 1024;

    /** Paths (relative to the build directory) snapshotted into the tar. */
    private const CACHE_PATHS = [
        'node_modules',
        '.next/cache',
        '.nuxt',
        '.astro',
        '.svelte-kit',
        'node_modules/.cache',
        'dist/.cache',
    ];

    public function cacheKey(string $checkout, ?string $repoRoot, string $nodeVersion = '20'): string
    {
        $base = rtrim($checkout, '/');
        $rootSegment = $repoRoot !== null && $repoRoot !== '' ? trim($repoRoot, '/') : '';
        if ($rootSegment !== '') {
            $base = $base.'/'.$rootSegment;
        }

        $parts = [$nodeVersion, $rootSegment];
        foreach (['pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb', 'package-lock.json'] as $lockfile) {
            $path = $base.'/'.$lockfile;
            if (is_file($path)) {
                $parts[] = $lockfile.':'.hash_file('sha256', $path);
            }
        }
        if (count($parts) <= 2) {
            // No lockfile present — fall back to package.json so we still
            // get a cache, but it invalidates on any version bump.
            $pkg = $base.'/package.json';
            if (is_file($pkg)) {
                $parts[] = 'package.json:'.hash_file('sha256', $pkg);
            }
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }

    /**
     * @return array{ok: bool, restored_bytes: int, message: string}
     */
    /** @return array<string, mixed> */
    public function restore(string $checkout, ?string $repoRoot, string $cacheKey, Site $site, ?string $diskName = null): array
    {
        if (FakeEdgeProvision::enabled()) {
            return ['ok' => false, 'restored_bytes' => 0, 'message' => 'fake-edge: cache restore skipped'];
        }

        $disk = $this->disk($diskName);
        $key = $this->storageKey($site, $cacheKey);
        if (! $disk->exists($key)) {
            return ['ok' => false, 'restored_bytes' => 0, 'message' => 'cache miss for key '.$cacheKey];
        }

        $base = $this->extractRoot($checkout, $repoRoot);
        $tmpTar = tempnam(sys_get_temp_dir(), 'dply-edge-cache-').'.tar.gz';
        try {
            file_put_contents($tmpTar, $disk->get($key));
            $restoredBytes = filesize($tmpTar) ?: 0;
            $result = Process::timeout(120)->run([
                'tar', '-xzf', $tmpTar, '-C', $base,
            ]);
            if (! $result->successful()) {
                return ['ok' => false, 'restored_bytes' => 0, 'message' => 'tar extract failed: '.$result->errorOutput()];
            }

            return ['ok' => true, 'restored_bytes' => $restoredBytes, 'message' => 'restored '.$cacheKey];
        } finally {
            @unlink($tmpTar);
        }
    }

    /**
     * @return array{ok: bool, snapshot_bytes: int, message: string}
     */
    /** @return array<string, mixed> */
    public function snapshot(string $checkout, ?string $repoRoot, string $cacheKey, Site $site, ?string $diskName = null): array
    {
        if (FakeEdgeProvision::enabled()) {
            return ['ok' => false, 'snapshot_bytes' => 0, 'message' => 'fake-edge: cache snapshot skipped'];
        }

        $base = $this->extractRoot($checkout, $repoRoot);
        $existing = $this->existingPaths($base);
        if ($existing === []) {
            return ['ok' => false, 'snapshot_bytes' => 0, 'message' => 'no cache paths exist yet'];
        }

        $tmpTar = tempnam(sys_get_temp_dir(), 'dply-edge-cache-snap-').'.tar.gz';
        try {
            // GNU/BSD tar both accept `-C` + relative paths. Listing
            // each path explicitly avoids tar-ing the entire checkout.
            $args = ['tar', '-czf', $tmpTar, '-C', $base, ...$existing];
            $result = Process::timeout(180)->run($args);
            if (! $result->successful()) {
                return ['ok' => false, 'snapshot_bytes' => 0, 'message' => 'tar create failed: '.$result->errorOutput()];
            }

            $bytes = filesize($tmpTar) ?: 0;
            $disk = $this->disk($diskName);
            $disk->put($this->storageKey($site, $cacheKey), file_get_contents($tmpTar), [
                'visibility' => 'private',
                'ContentType' => 'application/gzip',
            ]);

            return ['ok' => true, 'snapshot_bytes' => $bytes, 'message' => 'snapshotted '.count($existing).' path(s), '.$bytes.' bytes'];
        } finally {
            @unlink($tmpTar);
        }
    }

    /**
     * LRU prune: list cache/{site_id}/, sort by mtime, delete oldest
     * until total bytes fit under $maxBytes. Returns the number of
     * keys deleted. Best-effort — failures are silent.
     */
    public function prune(Site $site, ?string $diskName = null, int $maxBytes = self::DEFAULT_MAX_BYTES): int
    {
        if (FakeEdgeProvision::enabled()) {
            return 0;
        }

        $disk = $this->disk($diskName);
        $prefix = 'cache/'.$site->id;
        $files = $disk->allFiles($prefix);
        if ($files === []) {
            return 0;
        }

        $entries = [];
        $total = 0;
        foreach ($files as $path) {
            $size = (int) $disk->size($path);
            $mtime = (int) $disk->lastModified($path);
            $entries[] = ['path' => $path, 'size' => $size, 'mtime' => $mtime];
            $total += $size;
        }
        if ($total <= $maxBytes) {
            return 0;
        }

        usort($entries, fn ($a, $b) => $a['mtime'] <=> $b['mtime']);
        $deleted = 0;
        while ($total > $maxBytes && $entries !== []) {
            $oldest = array_shift($entries);
            try {
                $disk->delete($oldest['path']);
                $total -= $oldest['size'];
                $deleted++;
            } catch (\Throwable) {
                // Continue — partial prune is better than none.
            }
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function existingPaths(string $base): array
    {
        $existing = [];
        foreach (self::CACHE_PATHS as $path) {
            $full = rtrim($base, '/').'/'.$path;
            if (is_dir($full)) {
                $existing[] = $path;
            }
        }

        return array_values(array_unique($existing));
    }

    private function extractRoot(string $checkout, ?string $repoRoot): string
    {
        $base = rtrim($checkout, '/');
        if ($repoRoot !== null && $repoRoot !== '') {
            $candidate = $base.'/'.trim($repoRoot, '/');
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $base;
    }

    private function storageKey(Site $site, string $cacheKey): string
    {
        return 'cache/'.$site->id.'/'.$cacheKey.'.tar.gz';
    }

    private function disk(?string $diskName): Filesystem
    {
        return Storage::disk($diskName ?? (string) config('edge.disk.name', 'edge_r2'));
    }
}
