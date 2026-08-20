<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Publish a Functions Laravel app's `public/build` off the function so Vite
 * CSS/fonts are not served through the 1 MB HTTP response cap.
 *
 * Files land on the control-plane disk that already serves dply.io
 * (`site_assets` — logos, favicons, org icons) under `serverless-assets/{site}/`.
 * The public URL stays `/serverless-assets/{site}/build/…`. The deploy then
 * sets ASSET_URL to that origin so `asset()` / `@vite` point here instead of
 * the function hostname.
 */
class ServerlessAssetPublisher
{
    /**
     * Durable control-plane disk that already serves dply.io. Looked up by
     * matching the public origin host against configured disk URLs.
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

    public function assetUrl(Site $site): string
    {
        return $this->origin().'/serverless-assets/'.$site->id;
    }

    public function origin(): string
    {
        $public = trim((string) config('dply.public_app_url', ''));
        if ($public === '') {
            $public = rtrim((string) config('app.url'), '/');
        }

        if (preg_match('~^https?://~i', $public) !== 1) {
            $public = 'https://'.$public;
        }

        return rtrim($public, '/');
    }

    /**
     * The filesystem disk that already serves the control-plane origin
     * (dply.io in production). Prefers `site_assets` when its URL host
     * matches; never invents a `serverless_assets` disk.
     */
    public function diskName(): string
    {
        $host = strtolower((string) parse_url($this->origin(), PHP_URL_HOST));
        $disks = config('filesystems.disks', []);
        if (! is_array($disks)) {
            return self::DISK;
        }

        $ranked = [];
        foreach ($disks as $name => $disk) {
            if (! is_string($name) || ! is_array($disk) || $name === 'serverless_assets') {
                continue;
            }
            $driver = $disk['driver'] ?? null;
            if (! is_string($driver) || $driver === '') {
                continue;
            }

            $url = $disk['url'] ?? '';
            $diskHost = is_string($url) && $url !== ''
                ? strtolower((string) parse_url($url, PHP_URL_HOST))
                : '';
            $hostMatches = $host !== '' && $diskHost !== '' && $diskHost === $host;
            $preferred = $name === self::DISK;

            if (! $hostMatches && ! $preferred) {
                continue;
            }

            $rank = match ($name) {
                'site_assets' => 0,
                'public' => 1,
                default => 2,
            };
            if (! $hostMatches) {
                $rank += 10;
            }

            $ranked[] = [$rank, $name];
        }

        if ($ranked === []) {
            return self::DISK;
        }

        usort($ranked, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $ranked[0][1];
    }

    public function read(Site $site, string $relative): ?string
    {
        $key = $this->prefix($site).'/'.ltrim(str_replace('\\', '/', $relative), '/');
        $disk = $this->disk();
        if (! $disk->exists($key)) {
            return null;
        }

        $contents = $disk->get($key);

        return is_string($contents) ? $contents : null;
    }

    public function prefix(Site $site): string
    {
        return self::STORAGE_PREFIX.'/'.$site->id;
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
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
}
