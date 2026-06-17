<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveServerlessCustomDomain;
use App\Models\Site;
use App\Services\Serverless\ServerlessRoutingResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Proxies a friendly dply-hosted URL ({app}/fn/{slug}) to a serverless
 * function's real DigitalOcean Functions invocation URL.
 *
 * DO Functions has no custom-domain support, so dply itself is the gateway:
 * the function keeps a clean, memorable URL on the dply domain and this
 * forwards the request through to the raw `…doserverless.co` action URL.
 *
 * Before forwarding, the resolved routing rules are applied in order:
 * redirects → CORS preflight short-circuit → forward upstream → response
 * decoration (static headers + CORS). Custom-domain hosts are dispatched
 * here by the {@see ResolveServerlessCustomDomain}
 * middleware via {@see self::proxyForSite()}.
 */
class ServerlessFunctionProxyController extends Controller
{
    public function __invoke(Request $request, string $slug, string $path = ''): SymfonyResponse
    {
        $site = Site::query()
            ->where('meta->serverless->proxy_slug', $slug)
            ->first();

        abort_if($site === null, 404, 'No serverless function answers at this address.');

        return $this->proxyForSite($request, $site, $path);
    }

    /**
     * Run a request through the routing rules + upstream forward for a
     * specific site. Used both by the slug-path entrypoint above and by
     * the custom-domain middleware when a request arrives on a hostname
     * registered in `site.meta.serverless.routing.custom_domains`.
     */
    public function proxyForSite(Request $request, Site $site, string $path = ''): SymfonyResponse
    {
        $routing = app(ServerlessRoutingResolver::class)->forSite($site);

        $redirect = $this->matchRedirect($path, $routing['redirects']);
        if ($redirect !== null) {
            return $redirect;
        }

        if ($routing['cors']['enabled'] && $request->isMethod('OPTIONS')) {
            $preflight = $this->corsPreflight($request, $routing['cors']);
            if ($preflight !== null) {
                return $preflight;
            }
        }

        $actionUrl = $site->serverlessConfig()['action_url'] ?? null;
        if (! is_string($actionUrl) || $actionUrl === '') {
            abort(503, 'This function has not finished deploying yet.');
        }

        $upstream = $this->forward($request, $actionUrl, $path);

        return $this->decorate($request, $upstream, $routing);
    }

    /**
     * First-match-wins redirect lookup. Supports `kind=exact` (whole path
     * equality) and `kind=prefix` (path begins with `from`). Anything else
     * is treated as exact. Returned response is an external redirect.
     *
     * The path is the captured route segment (post-`{slug}`), already
     * stripped of the proxy mount point — so a rule with `from: /old`
     * matches a GET to `/fn/{slug}/old` and to `https://api.acme.com/old`
     * alike.
     *
     * @param  list<array{from: string, to: string, status: int, kind: string}>  $redirects
     */
    private function matchRedirect(string $path, array $redirects): ?RedirectResponse
    {
        $path = '/'.ltrim($path, '/');

        foreach ($redirects as $rule) {
            $from = '/'.ltrim($rule['from'], '/');
            $kind = $rule['kind'];
            $matched = match ($kind) {
                'prefix' => $from !== '/' && str_starts_with($path, $from),
                default => $path === $from,
            };
            if ($matched) {
                return redirect()->away((string) $rule['to'], (int) ($rule['status'] ?: 302));
            }
        }

        return null;
    }

    /**
     * 204 preflight response when the request's Origin is allowed by the
     * site's CORS policy. Returns null when there's no Origin header at
     * all so a non-CORS OPTIONS request can still be forwarded upstream.
     *
     * @param  array{enabled: bool, origins: list<string>, methods: list<string>, headers: list<string>, allow_credentials: bool, max_age: int}  $cors
     */
    private function corsPreflight(Request $request, array $cors): ?Response
    {
        $origin = (string) $request->header('Origin', '');
        if ($origin === '') {
            return null;
        }

        $allowed = $this->resolveAllowedOrigin($origin, $cors['origins']);
        if ($allowed === null) {
            return null;
        }

        $headers = [
            'Access-Control-Allow-Origin' => $allowed,
            'Access-Control-Allow-Methods' => implode(', ', $cors['methods']),
            'Access-Control-Allow-Headers' => implode(', ', $cors['headers']),
            'Access-Control-Max-Age' => (string) max(0, (int) $cors['max_age']),
            'Vary' => 'Origin',
        ];
        if ($cors['allow_credentials']) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return response('', 204, $headers);
    }

    /**
     * Forward the request body + query to the upstream action URL. Drops
     * the Host and Content-Length headers so the upstream client recomputes
     * them. Catches network failures and surfaces a 502 — the caller would
     * otherwise see an opaque 500.
     */
    private function forward(Request $request, string $actionUrl, string $path): HttpClientResponse
    {
        $target = rtrim($actionUrl, '/');
        if ($path !== '') {
            $target .= '/'.ltrim($path, '/');
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower((string) $name), ['host', 'content-length'], true)) {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        $client = Http::withHeaders($headers)->timeout(70)->withoutRedirecting();

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            $client = $client->withBody(
                $request->getContent(),
                (string) $request->header('Content-Type', 'application/octet-stream'),
            );
        }

        try {
            return $client->send($request->method(), $target, ['query' => $request->query()]);
        } catch (Throwable $e) {
            abort(502, 'The serverless function could not be reached: '.$e->getMessage());
        }
    }

    /**
     * Build the final response by passing through safe upstream headers,
     * layering the operator-configured static headers (skipping reserved
     * names), and tacking on CORS headers when the request had an Origin.
     *
     * @param  array{
     *   redirects: list<array{from: string, to: string, status: int, kind: string}>,
     *   headers: list<array{name: string, value: string}>,
     *   cors: array{enabled: bool, origins: list<string>, methods: list<string>, headers: list<string>, allow_credentials: bool, max_age: int},
     *   custom_domains: list<array{hostname: string, mode: string, dns_status: string, cname_target: string, verified_at: ?string, error: ?string}>
     * }  $routing
     */
    private function decorate(Request $request, HttpClientResponse $upstream, array $routing): Response
    {
        $passHeaders = [];
        foreach (['Content-Type', 'Cache-Control', 'Location'] as $header) {
            $value = $upstream->header($header);
            if ($value !== '') {
                $passHeaders[$header] = $value;
            }
        }

        foreach ($routing['headers'] as $entry) {
            $name = $entry['name'];
            if ($name === '' || in_array(strtolower($name), ['content-type', 'cache-control', 'location'], true)) {
                continue;
            }
            $passHeaders[$name] = $entry['value'];
        }

        if ($routing['cors']['enabled']) {
            $origin = (string) $request->header('Origin', '');
            if ($origin !== '') {
                $allowed = $this->resolveAllowedOrigin($origin, $routing['cors']['origins']);
                if ($allowed !== null) {
                    $passHeaders['Access-Control-Allow-Origin'] = $allowed;
                    $passHeaders['Vary'] = 'Origin';
                    if ($routing['cors']['allow_credentials']) {
                        $passHeaders['Access-Control-Allow-Credentials'] = 'true';
                    }
                }
            }
        }

        return response($upstream->body(), $upstream->status(), $passHeaders);
    }

    /**
     * `*` becomes the literal `*` (echoes-back model is unsafe for
     * credentialed requests). Otherwise return the request's Origin only
     * when it's on the allow-list — this matches the request value rather
     * than the policy entry so credentialed requests work.
     *
     * @param  list<string>  $allowList
     */
    private function resolveAllowedOrigin(string $origin, array $allowList): ?string
    {
        foreach ($allowList as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '*') {
                return '*';
            }
            if ($entry !== '' && $entry === strtolower($origin)) {
                return $origin;
            }
        }

        return null;
    }
}
