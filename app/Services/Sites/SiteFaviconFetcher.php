<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pulls a site's favicon and stores it as the site logo on the `public` disk.
 *
 * Raster formats only (png/jpg/webp/gif/ico) — SVG is deliberately rejected so
 * a remote site can't deliver a script-bearing SVG that we'd then serve from
 * our own origin (stored-XSS). The host is checked against private/reserved IP
 * ranges first (best-effort SSRF guard) since a site's hostname is operator-
 * controlled and could otherwise point at internal infrastructure.
 */
class SiteFaviconFetcher
{
    private const MAX_BYTES = 1_048_576; // 1 MB

    private const DISK = 'public';

    private const DIR = 'site-logos';

    private const TIMEOUT = 8;

    /** mime => extension for the formats we accept. */
    private const ACCEPTED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    /**
     * Fetch + store the favicon, returning the new relative logo path on the
     * public disk. Throws RuntimeException with a user-facing message on failure.
     */
    public function fetch(Site $site): string
    {
        $base = $this->resolveBaseUrl($site);
        if ($base === null) {
            throw new \RuntimeException('This site has no public URL to pull a favicon from yet.');
        }

        $this->assertPublicHost($base);

        foreach ($this->candidateIconUrls($base) as $url) {
            $icon = $this->tryDownload($url);
            if ($icon !== null) {
                return $this->store($site, $icon['bytes'], $icon['ext']);
            }
        }

        throw new \RuntimeException('Could not find a favicon at '.(parse_url($base, PHP_URL_HOST) ?: $base).'.');
    }

    private function resolveBaseUrl(Site $site): ?string
    {
        $host = $site->primaryDomain()?->hostname
            ?: (is_string($v = $site->visitUrl()) ? parse_url($v, PHP_URL_HOST) : null);

        $host = is_string($host) ? trim($host) : '';
        if ($host === '') {
            return null;
        }

        // Prefer the scheme the site actually advertises; default to https.
        $scheme = str_starts_with((string) $site->visitUrl(), 'http://') ? 'http' : 'https';

        return $scheme.'://'.$host;
    }

    /**
     * Reject hosts that resolve to a private/loopback/reserved address — a
     * best-effort SSRF guard. DNS failures are treated as non-public.
     */
    private function assertPublicHost(string $base): void
    {
        $host = parse_url($base, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new \RuntimeException('Could not determine the site host.');
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        if (! $isPublic) {
            throw new \RuntimeException('Favicon pull only works for sites reachable on a public address (this one resolves to a private/local host).');
        }
    }

    /**
     * Build an ordered candidate list: declared <link rel="...icon"> hrefs
     * (apple-touch first, highest sizes next), then the conventional defaults.
     *
     * @return list<string>
     */
    private function candidateIconUrls(string $base): array
    {
        $urls = $this->parseDeclaredIcons($base);
        $urls[] = rtrim($base, '/').'/apple-touch-icon.png';
        $urls[] = rtrim($base, '/').'/favicon.ico';

        // De-dupe while preserving priority order.
        return array_values(array_unique($urls));
    }

    /** @return list<string> absolute icon URLs declared in the page head */
    private function parseDeclaredIcons(string $base): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->withHeaders(['User-Agent' => 'dply-favicon-fetcher'])->get($base);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $html = $response->body();
        if ($html === '') {
            return [];
        }

        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $candidates = [];
        foreach ($dom->getElementsByTagName('link') as $link) {
            /** @var \DOMElement $link */
            $rel = strtolower(trim($link->getAttribute('rel')));
            if (! str_contains($rel, 'icon')) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $abs = $this->absoluteUrl($base, $href);
            if ($abs === null) {
                continue;
            }
            $sizes = (int) (strstr($link->getAttribute('sizes'), 'x', true) ?: 0);
            $priority = str_contains($rel, 'apple-touch') ? 100000 : $sizes;
            $candidates[] = ['url' => $abs, 'priority' => $priority];
        }

        usort($candidates, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_map(fn ($c) => $c['url'], $candidates);
    }

    private function absoluteUrl(string $base, string $href): ?string
    {
        if (str_starts_with($href, 'data:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https').':'.$href;
        }
        $origin = (parse_url($base, PHP_URL_SCHEME) ?: 'https').'://'.parse_url($base, PHP_URL_HOST);
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return rtrim($base, '/').'/'.ltrim($href, '/');
    }

    /**
     * @return array{bytes: string, ext: string}|null
     */
    private function tryDownload(string $url): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->withHeaders(['User-Agent' => 'dply-favicon-fetcher'])->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();
        $length = strlen($bytes);
        if ($length === 0 || $length > self::MAX_BYTES) {
            return null;
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $ext = self::ACCEPTED[$contentType] ?? $this->extFromUrl($url);
        if ($ext === null) {
            return null;
        }

        return ['bytes' => $bytes, 'ext' => $ext];
    }

    private function extFromUrl(string $url): ?string
    {
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($ext, self::ACCEPTED, true) ? $ext : null;
    }

    private function store(Site $site, string $bytes, string $ext): string
    {
        $old = $site->logo_path;

        $path = self::DIR.'/'.$site->id.'-'.Str::lower(Str::random(8)).'.'.$ext;
        Storage::disk(self::DISK)->put($path, $bytes);

        if (($old) && $old !== '' && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        return $path;
    }
}
