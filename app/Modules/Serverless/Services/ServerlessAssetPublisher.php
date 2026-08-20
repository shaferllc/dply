<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Publish a Functions Laravel app's `public/build` off the function so Vite
 * CSS/fonts are not served through the 1 MB HTTP response cap.
 *
 * Files land on the durable attached store (`site_assets`) under
 * `serverless-assets/{site}/`. ASSET_URL is that disk's own public URL
 * ({@see Filesystem::url()} / the disk `url` config). When the disk is
 * local and that URL is still the control-plane host, ASSET_URL stays on
 * the function hostname so the proxy can serve `/build` same-origin.
 */
class ServerlessAssetPublisher
{
    /**
     * Durable attached store (logos, favicons, org icons). Not looked up
     * by matching APP_URL / dply.io.
     */
    public const DISK = 'site_assets';

    public const STORAGE_PREFIX = 'serverless-assets';

    /**
     * Upload `public/build` and return the ASSET_URL origin, or null when
     * there is nothing to publish.
     */
    public function publishBuild(Site $site, string $workingDirectory): ?string
    {
        $build = rtrim($workingDirectory, '/').'/public/build';
        if (! is_dir($build)) {
            return null;
        }

        $prefix = $this->prefix($site);
        $this->disk()->deleteDirectory($prefix.'/build');

        $count = $this->uploadDirectory($build, $prefix.'/build');
        if ($count === 0) {
            return null;
        }

        $url = $this->assetUrl($site);

        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['asset_url'] = $url;
        $serverless['assets_published_at'] = now()->toIso8601String();
        $serverless['assets_file_count'] = $count;
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();

        return $url;
    }

    /**
     * Public ASSET_URL prefix for this site's published build.
     *
     * Prefers the attached disk's own URL. Falls back to the function
     * hostname when that URL is the control-plane origin (local disk
     * whose `url` is still APP_URL).
     */
    public function assetUrl(Site $site): string
    {
        $fromDisk = $this->diskPublicUrl($site);
        if ($fromDisk !== null) {
            return $fromDisk;
        }

        return $this->sameOriginUrl($site);
    }

    public function diskName(): string
    {
        return self::DISK;
    }

    public function read(Site $site, string $relative): ?string
    {
        $key = $this->storageKey($site, $relative);
        if ($key === null) {
            return null;
        }

        $disk = $this->disk();
        if (! $disk->exists($key)) {
            return null;
        }

        $contents = $disk->get($key);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Stream a published file. Null when the path is unsafe or missing.
     */
    public function responseFor(Site $site, string $relative): ?Response
    {
        $contents = $this->read($site, $relative);
        if ($contents === null) {
            return null;
        }

        $safe = $this->safeRelative($relative) ?? $relative;
        $cache = preg_match('/\.[a-f0-9]{8,}\./', $safe) === 1
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=3600';

        return response($contents, 200, [
            'Content-Type' => $this->mimeFor($safe),
            'Cache-Control' => $cache,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Map a request path on the function host to a storage-relative key.
     * Accepts `/build/…` and `/serverless-assets/{site}/…`.
     */
    public function relativeFromRequestPath(Site $site, string $path): ?string
    {
        $relative = $this->safeRelative($path);
        if ($relative === null) {
            return null;
        }

        if ($relative === 'build' || str_starts_with($relative, 'build/')) {
            return $relative;
        }

        $prefixed = self::STORAGE_PREFIX.'/'.$site->id.'/';
        if (str_starts_with($relative, $prefixed)) {
            $stripped = substr($relative, strlen($prefixed));

            return $stripped !== '' ? $stripped : null;
        }

        return null;
    }

    public function prefix(Site $site): string
    {
        return self::STORAGE_PREFIX.'/'.$site->id;
    }

    public function mimeFor(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'json', 'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'wasm' => 'application/wasm',
            default => 'application/octet-stream',
        };
    }

    /**
     * The disk's own public prefix for this site, or null when that URL
     * is missing or still the control-plane host.
     */
    private function diskPublicUrl(Site $site): ?string
    {
        try {
            $url = rtrim((string) $this->disk()->url($this->prefix($site)), '/');
        } catch (\Throwable) {
            return null;
        }

        if ($url === '' || preg_match('~^https?://~i', $url) !== 1) {
            return null;
        }

        if ($this->urlIsControlPlane($url)) {
            return null;
        }

        return $url;
    }

    private function sameOriginUrl(Site $site): string
    {
        $url = $site->serverlessFriendlyUrl() ?: $site->serverlessPublicUrl();
        if (! is_string($url) || $url === '') {
            $url = 'https://'.$site->ensureServerlessProxySlug().'.'
                .ServerlessTestingDomains::apexFor($site->getKey());
        }

        return rtrim($url, '/');
    }

    private function urlIsControlPlane(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true;
        }

        foreach ($this->controlPlaneHosts() as $plane) {
            if ($host === $plane) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function controlPlaneHosts(): array
    {
        $hosts = [];
        foreach ([
            (string) config('app.url', ''),
            (string) config('dply.public_app_url', ''),
        ] as $origin) {
            $origin = trim($origin);
            if ($origin === '') {
                continue;
            }
            if (preg_match('~^https?://~i', $origin) !== 1) {
                $origin = 'https://'.$origin;
            }
            $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function storageKey(Site $site, string $relative): ?string
    {
        $safe = $this->safeRelative($relative);
        if ($safe === null) {
            return null;
        }

        return $this->prefix($site).'/'.$safe;
    }

    private function safeRelative(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }

        return $relative;
    }

    private function disk(): FilesystemAdapter
    {
        $disk = Storage::disk($this->diskName());

        if (! $disk instanceof FilesystemAdapter) {
            throw new \RuntimeException('Attached asset disk is not a filesystem adapter.');
        }

        return $disk;
    }

    private function uploadDirectory(string $localDir, string $storagePrefix): int
    {
        $count = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($localDir, '', $file->getPathname()), '/\\');
            $key = trim($storagePrefix, '/').'/'.str_replace('\\', '/', $relative);

            $this->disk()->put($key, (string) file_get_contents($file->getPathname()), [
                'visibility' => 'public',
                'CacheControl' => $this->cacheControlFor($relative),
                'ContentType' => $this->mimeFor($relative),
            ]);
            $count++;
        }

        return $count;
    }

    private function cacheControlFor(string $path): string
    {
        if (preg_match('/\.[a-f0-9]{8,}\./', $path) === 1) {
            return 'public, max-age=31536000, immutable';
        }

        return 'public, max-age=3600';
    }
}
