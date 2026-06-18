<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use RuntimeException;

/**
 * Generates signed `/_dply/image` URLs for hybrid Edge sites.
 *
 * The Worker enforces an HMAC-SHA256 over the canonical query string
 * (sorted, lowercased keys, sig excluded). This helper produces the
 * same signature server-side so dply-rendered HTML, feeds, OG meta,
 * etc. can emit pre-signed image URLs without round-tripping.
 *
 * Usage:
 *   $signer = app(EdgeImageUrlSigner::class);
 *   echo $signer->urlFor($site, 'https://images.example.com/hero.jpg', width: 800, quality: 75);
 *   // → https://my-site.example/_dply/image?fmt=auto&q=75&url=...&w=800&sig=...
 */
class EdgeImageUrlSigner
{
    /**
     * Build a signed image URL. Throws when the site has no image
     * signing secret configured (operator must enable image opt first).
     */
    public function urlFor(
        Site $site,
        string $sourceUrl,
        ?int $width = null,
        ?int $quality = null,
        string $format = 'auto',
    ): string {
        $edge = $site->edgeMeta();
        $images = is_array($edge['images'] ?? null) ? $edge['images'] : [];
        $secret = is_string($images['signing_secret'] ?? null) ? trim((string) $images['signing_secret']) : '';
        if ($secret === '') {
            throw new RuntimeException('Image optimization is not enabled for this Edge site.');
        }

        $hostname = $site->edgeHostname();
        if (! is_string($hostname) || $hostname === '') {
            throw new RuntimeException('Site has no Edge hostname yet — finish provisioning first.');
        }

        $params = [
            'fmt' => in_array($format, ['auto', 'avif', 'webp', 'jpeg', 'png'], true) ? $format : 'auto',
            'url' => $sourceUrl,
        ];
        if ($width !== null) {
            $params['w'] = (string) max(1, min(4096, $width));
        }
        if ($quality !== null) {
            $params['q'] = (string) max(1, min(100, $quality));
        }

        ksort($params);
        $canonical = implode('&', array_map(
            fn (string $k, string $v): string => strtolower($k).'='.$v,
            array_keys($params),
            array_values($params),
        ));
        $sig = hash_hmac('sha256', $canonical, $secret);

        $params['sig'] = $sig;

        return 'https://'.$hostname.'/_dply/image?'.http_build_query($params);
    }
}
