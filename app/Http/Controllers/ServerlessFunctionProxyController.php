<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proxies a friendly dply-hosted URL ({app}/fn/{slug}) to a serverless
 * function's real DigitalOcean Functions invocation URL.
 *
 * DO Functions has no custom-domain support, so dply itself is the gateway:
 * the function keeps a clean, memorable URL on the dply domain and this
 * forwards the request through to the raw `…doserverless.co` action URL.
 */
class ServerlessFunctionProxyController extends Controller
{
    public function __invoke(Request $request, string $slug, string $path = ''): Response
    {
        $site = Site::query()
            ->where('meta->serverless->proxy_slug', $slug)
            ->first();

        abort_if($site === null, 404, 'No serverless function answers at this address.');

        $actionUrl = $site->serverlessConfig()['action_url'] ?? null;
        if (! is_string($actionUrl) || $actionUrl === '') {
            abort(503, 'This function has not finished deploying yet.');
        }

        $target = rtrim($actionUrl, '/');
        if ($path !== '') {
            $target .= '/'.ltrim($path, '/');
        }

        // Forward request headers except Host (must reflect the upstream) and
        // Content-Length (the client recomputes it).
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
            $upstream = $client->send($request->method(), $target, ['query' => $request->query()]);
        } catch (Throwable $e) {
            abort(502, 'The serverless function could not be reached: '.$e->getMessage());
        }

        $passHeaders = [];
        foreach (['Content-Type', 'Cache-Control', 'Location'] as $header) {
            $value = $upstream->header($header);
            if ($value !== '') {
                $passHeaders[$header] = $value;
            }
        }

        return response($upstream->body(), $upstream->status(), $passHeaders);
    }
}
