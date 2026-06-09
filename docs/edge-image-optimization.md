# Edge — image optimization

Resize and reformat images at the edge via Cloudflare Image Resizing.
Enabled per Edge site from **Delivery → Image optimization**.

## How it works

1. Operator enables image opt and lists allowed source hostnames.
2. Worker registers `/_dply/image` on the site's hostname.
3. Server-side code generates signed URLs of the shape
   `https://{site-hostname}/_dply/image?url=<src>&w=<n>&q=<n>&fmt=<auto|avif|webp|jpeg|png>&sig=<hmac>`.
4. Worker verifies the signature, checks the source host is allowed,
   then calls Cloudflare Image Resizing with `cf.image` options. The
   resized response is returned with strong cache headers.

## Generating signed URLs

### From PHP

```php
use App\Services\Edge\EdgeImageUrlSigner;

$url = app(EdgeImageUrlSigner::class)->urlFor(
    site: $site,
    sourceUrl: 'https://images.example.com/hero.jpg',
    width: 800,
    quality: 75,
    format: 'auto',
);
```

### From frontend frameworks

Use the URL pattern in your `<img>` / `next/image` loader. Sign the URL
*on the server* so the secret never reaches the browser.

#### Next.js custom loader

```ts
// app/lib/image-loader.ts
export default function dplyLoader({ src, width, quality }) {
  const params = new URLSearchParams({ url: src, w: String(width), q: String(quality ?? 75), fmt: 'auto' });
  // The `sig` must come from the server — fetch a tiny signing endpoint
  // or inline a signed URL into the page at SSR time.
  return `/api/sign-image?${params.toString()}`;
}
```

The Next.js server then proxies `/api/sign-image` to a Laravel endpoint
that returns the signed `/_dply/image?...` URL.

#### Astro / SvelteKit

Same shape: sign on the server. Both frameworks have server-side render
hooks where you can call `EdgeImageUrlSigner` (via an API call) and
inject the signed URL into the HTML.

## What the optimizer accepts

| Param | Required | Notes |
|---|---|---|
| `url`  | yes | Absolute http(s) URL. Host must be in the site's allowlist. |
| `sig`  | yes | Hex HMAC-SHA256 of canonical params with the site's signing secret. |
| `w`    | no  | Width 1–4096. Smaller of (this, source width) wins. |
| `q`    | no  | Quality 1–100. Defaults to Cloudflare's automatic. |
| `fmt`  | no  | `auto` (default) negotiates AVIF/WebP/JPEG by Accept header. |

## Cache behavior

Resized images set `Cache-Control: public, max-age=86400, s-maxage=86400, immutable`
so both the browser and Cloudflare's edge cache the result. A given
canonical URL is stable forever (until the source changes), so cache
invalidation = change the source filename or vary the URL.

## Failure modes

| Status | Meaning |
|---|---|
| 400 | Missing required param, invalid source URL, bad format |
| 403 | Bad signature, or source host not in the allow list |
| 404 | Image optimization not enabled for this site |
| 502 | Source returned a non-2xx, or Cloudflare Image Resizing failed |

## Rotating the signing secret

**Delivery → Image optimization → Rotate**. Any pre-rendered signed
URLs return 403 until re-signed. The KV host map is republished
immediately so the change is live within ~60s of propagation.
