<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Publish a Functions Laravel app's `public/build` off the function so Vite
 * CSS/fonts are not served through the 1 MB HTTP response cap.
 *
 * Files land on the `serverless_assets` disk under
 * `serverless-assets/{label}/` ({@see ServerlessAssetHost} for why the prefix
 * is the asset hostname's DNS label). That disk is object storage in
 * production and falls back to the durable local store in development, where
 * the {@see \App\Modules\Serverless\ServerlessServiceProvider} aliases it.
 *
 * ASSET_URL resolves in three tiers, most specific first:
 *   1. the site's CDN hostname, when asset delivery is configured;
 *   2. the disk's own public URL, when it has one that isn't the control plane;
 *   3. the function hostname, so the proxy serves `/build` same-origin.
 *
 * Publishing is ADDITIVE. It used to wipe `{prefix}/build` first, which
 * quietly broke rollback: {@see \App\Modules\Serverless\Jobs\RollbackServerlessFunctionJob}
 * re-deploys an older artifact without rebuilding, and that artifact's Vite
 * manifest asks for content-hashed filenames the newer publish had deleted.
 * Because filenames are content-addressed, the union of builds is always
 * internally consistent, so old and new simply coexist and
 * {@see ServerlessAssetGarbageCollector} reclaims what no retained deploy
 * still references.
 */
class ServerlessAssetPublisher
{
    /**
     * Object store for published builds. Distinct from `site_assets` (logos,
     * favicons, org icons), which stays local and control-plane served.
     */
    public const DISK = 'serverless_assets';

    public const STORAGE_PREFIX = ServerlessAssetHost::STORAGE_PREFIX;

    /**
     * How many publish timestamps to keep per site. Only the newest
     * `retain_deploys` are ever read; the rest are kept as a short audit trail
     * and to survive an operator raising the retention setting.
     */
    private const PUBLISH_LOG_LIMIT = 20;

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

        $this->assertWithinSizeLimit($site, $build);

        // Mint the slug before deriving the prefix: the prefix and the asset
        // hostname must agree, and both are keyed on it.
        $site->ensureServerlessProxySlug();
        $prefix = $this->prefix($site);

        // Deliberately NOT deleting the existing build first — see the class
        // docblock. Content-hashed names make the union of builds consistent,
        // and re-PUTting unchanged files is what refreshes their LastModified
        // so the garbage collector can tell "still in use" from "abandoned".
        $upload = $this->uploadDirectory($build, $prefix.'/build');
        if ($upload['files'] === 0) {
            return null;
        }

        $url = $this->assetUrl($site);

        $publishedAt = now();

        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['asset_url'] = $url;
        $serverless['assets_published_at'] = $publishedAt->toIso8601String();
        $serverless['assets_file_count'] = $upload['files'];
        $serverless['assets_published_bytes'] = $upload['bytes'];

        $assets = is_array($serverless['assets'] ?? null) ? $serverless['assets'] : [];
        $assets['publishes'] = $this->recordPublish($assets['publishes'] ?? [], $publishedAt);
        $serverless['assets'] = $assets;

        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();

        return $url;
    }

    /**
     * Prepend this publish to the site's publish log, newest first.
     *
     * The garbage collector needs "the Nth-most-recent PUBLISH", which is not
     * the same as the Nth-most-recent deploy: a rollback records a successful
     * SiteDeployment but re-deploys an existing artifact without republishing
     * anything. Cutting on deploys would therefore let a run of rollbacks
     * advance the cutoff past assets that are still live and delete them.
     *
     * @param  mixed  $existing
     * @return list<string>
     */
    private function recordPublish($existing, \DateTimeInterface $publishedAt): array
    {
        $log = is_array($existing) ? array_values(array_filter($existing, 'is_string')) : [];
        array_unshift($log, $publishedAt->format(\DateTimeInterface::ATOM));

        return array_slice($log, 0, self::PUBLISH_LOG_LIMIT);
    }

    /**
     * Refuse a build whose assets are wildly outside what a front-end bundle
     * should be — almost always a large binary committed into `public/`.
     *
     * Checked against the LOCAL directory before a single byte is uploaded, so
     * the deploy fails at the one moment the user can still act on it, rather
     * than surfacing months later as a storage line on an invoice. The billed
     * allowance sits well below this: normal overage is priced, only the
     * absurd is refused.
     */
    private function assertWithinSizeLimit(Site $site, string $buildDirectory): void
    {
        $limit = (int) config('serverless.assets.max_bytes', 0);
        if ($limit <= 0) {
            return;
        }

        $bytes = $this->directoryBytes($buildDirectory);
        if ($bytes <= $limit) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Front-end assets are %s, which exceeds the %s limit for published builds. '
            .'Check for large files committed under public/ — or contact support to raise the limit.',
            $this->formatBytes($bytes),
            $this->formatBytes($limit),
        ));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 1).' GB';
        }

        return round($bytes / 1024 ** 2, 1).' MB';
    }

    /** Total size of a local directory tree, in bytes. */
    public function directoryBytes(string $localDirectory): int
    {
        if (! is_dir($localDirectory)) {
            return 0;
        }

        $bytes = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDirectory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
            }
        }

        return $bytes;
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
        $cdn = $this->cdnUrl($site);
        if ($cdn !== null) {
            return $cdn;
        }

        $fromDisk = $this->diskPublicUrl($site);
        if ($fromDisk !== null) {
            return $fromDisk;
        }

        return $this->sameOriginUrl($site);
    }

    /**
     * This site's CDN origin — `https://{label}-assets.{apex}`, or an attached
     * custom hostname when it has one.
     *
     * Carries no path: the fleet-wide Cloudflare rule derives the bucket
     * prefix from the hostname, so `/build/app-HASH.js` on the wire becomes
     * `/serverless-assets/{label}/build/app-HASH.js` at the origin.
     *
     * The hostname is per-site by design. It is what makes asset traffic
     * separable in Cloudflare's per-hostname analytics, which is the egress
     * meter — a shared hostname would leave egress unattributable without
     * parsing logs.
     *
     * Null unless delivery is configured, so development and any environment
     * without the CDN keeps the existing same-origin behaviour.
     */
    private function cdnUrl(Site $site): ?string
    {
        if (! (bool) config('serverless.assets.cdn.enabled', false)) {
            return null;
        }

        $custom = ServerlessAssetHost::customHostnames($site);
        $hostname = $custom[0] ?? ServerlessAssetHost::hostname($site);

        return $hostname === null ? null : 'https://'.$hostname;
    }

    public function diskName(): string
    {
        return self::DISK;
    }

    public function read(Site $site, string $relative): ?string
    {
        $safe = $this->safeRelative($relative);
        if ($safe === null) {
            return null;
        }

        $disk = $this->disk();

        // Current prefix first, then the pre-cutover one. A site whose assets
        // have not been backfilled yet keeps serving from where they are.
        foreach ($this->readPrefixes($site) as $prefix) {
            $key = $prefix.'/'.$safe;
            if (! $disk->exists($key)) {
                continue;
            }

            $contents = $disk->get($key);
            if (is_string($contents)) {
                return $contents;
            }
        }

        return null;
    }

    /**
     * Prefixes a read may resolve against, most current first.
     *
     * @return list<string>
     */
    private function readPrefixes(Site $site): array
    {
        return array_values(array_unique(array_filter([
            ServerlessAssetHost::prefix($site),
            ServerlessAssetHost::legacyPrefix($site),
        ])));
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

        foreach ($this->readPrefixes($site) as $prefix) {
            $prefixed = $prefix.'/';
            if (! str_starts_with($relative, $prefixed)) {
                continue;
            }

            $stripped = substr($relative, strlen($prefixed));
            if ($stripped !== '') {
                return $stripped;
            }
        }

        return null;
    }

    /**
     * Where this site's build is published. Keyed on the asset label so the
     * prefix and the CDN hostname stay identical; falls back to the pre-cutover
     * site-id prefix for a site with no slug allocated yet.
     */
    public function prefix(Site $site): string
    {
        return ServerlessAssetHost::prefix($site) ?? ServerlessAssetHost::legacyPrefix($site);
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

    /**
     * @return array{files: int, bytes: int}
     */
    private function uploadDirectory(string $localDir, string $storagePrefix): array
    {
        $count = 0;
        $bytes = 0;
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
            $bytes += (int) $file->getSize();
        }

        return ['files' => $count, 'bytes' => $bytes];
    }

    private function cacheControlFor(string $path): string
    {
        if (preg_match('/\.[a-f0-9]{8,}\./', $path) === 1) {
            return 'public, max-age=31536000, immutable';
        }

        return 'public, max-age=3600';
    }
}
