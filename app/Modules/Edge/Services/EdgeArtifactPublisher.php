<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Walks a local artifact directory and uploads every file to the
 * configured Edge R2 disk under a storage prefix.
 */
class EdgeArtifactPublisher
{
    public function uploadDirectory(string $localArtifactDir, string $storagePrefix, ?string $diskName = null): int
    {
        if (! is_dir($localArtifactDir)) {
            throw new RuntimeException("Artifact directory not found: {$localArtifactDir}");
        }

        $disk = $this->disk($diskName);
        $prefix = trim($storagePrefix, '/');
        $count = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localArtifactDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($localArtifactDir, '', $file->getPathname()), '/\\');
            $key = $prefix.'/'.$relative;

            $disk->put($key, file_get_contents($file->getPathname()), [
                'visibility' => 'public',
                'CacheControl' => $this->cacheControlFor($relative),
                'ContentType' => $this->mimeFor($relative),
            ]);

            $count++;
        }

        return $count;
    }

    public function directoryBytes(string $localArtifactDir): int
    {
        if (! is_dir($localArtifactDir)) {
            return 0;
        }

        $bytes = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localArtifactDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
            }
        }

        return $bytes;
    }

    public function uploadFile(string $localPath, string $storageKey, ?string $diskName = null): void
    {
        if (! is_file($localPath)) {
            throw new RuntimeException("Build log file not found: {$localPath}");
        }

        $disk = $this->disk($diskName);
        $disk->put(trim($storageKey, '/'), file_get_contents($localPath), [
            'ContentType' => 'text/plain; charset=utf-8',
        ]);
    }

    public function readFile(string $storageKey, ?string $diskName = null): ?string
    {
        $disk = $this->disk($diskName);
        $key = trim($storageKey, '/');
        if (! $disk->exists($key)) {
            return null;
        }

        return $disk->get($key);
    }

    public function deletePrefix(string $storagePrefix, ?string $diskName = null): void
    {
        $disk = $this->disk($diskName);
        $disk->deleteDirectory(trim($storagePrefix, '/'));
    }

    /**
     * Copy every object under $fromPrefix into $toPrefix on the same disk.
     * Used by promote — the preview's R2 artifacts get duplicated to a
     * fresh parent-owned prefix so tearing down the preview later doesn't
     * delete the production artifacts out from under the live deployment.
     */
    public function copyPrefix(string $fromPrefix, string $toPrefix, ?string $diskName = null): int
    {
        $disk = $this->disk($diskName);
        $from = trim($fromPrefix, '/');
        $to = trim($toPrefix, '/');

        if ($from === '' || $to === '' || $from === $to) {
            return 0;
        }

        $count = 0;
        foreach ($disk->allFiles($from) as $sourceKey) {
            $relative = ltrim(substr((string) $sourceKey, strlen($from)), '/');
            if ($relative === '') {
                continue;
            }
            $destKey = $to.'/'.$relative;
            $disk->copy($sourceKey, $destKey);
            $count++;
        }

        return $count;
    }

    private function disk(?string $diskName): Filesystem
    {
        $name = $diskName ?? (string) config('edge.disk.name', 'edge_r2');

        return Storage::disk($name);
    }

    private function cacheControlFor(string $path): string
    {
        if ($path === 'index.html' || str_ends_with($path, '/index.html')) {
            return 'public, max-age=0, must-revalidate';
        }
        if (preg_match('/\.[a-f0-9]{8,}\./', $path) === 1) {
            return 'public, max-age=31536000, immutable';
        }

        return 'public, max-age=3600';
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            default => 'application/octet-stream',
        };
    }
}
