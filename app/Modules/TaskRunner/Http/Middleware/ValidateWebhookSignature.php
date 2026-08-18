<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed TaskRunner webhooks are generated against DPLY_PUBLIC_APP_URL (the
 * tunnel the droplet can reach). Local Herd / Jetty then presents Host as
 * the internal vhost (dply.test). Laravel's default `signed` middleware
 * HMACs the incoming host, so a valid public-host signature becomes 403
 * and provision output never lands.
 *
 * Accept the signature if it matches the request as seen, the path only,
 * or the same path reconstructed on the configured public origin.
 */
final class ValidateWebhookSignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->signatureIsValid($request)) {
            return $next($request);
        }

        throw new InvalidSignatureException;
    }

    private function signatureIsValid(Request $request): bool
    {
        if ($request->hasValidSignature() || $request->hasValidSignature(absolute: false)) {
            return true;
        }

        foreach ($this->alternateRoots() as $root) {
            $candidate = Request::create($this->urlOnRoot($request, $root), $request->method());

            if (URL::hasValidSignature($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function alternateRoots(): array
    {
        $roots = [];

        foreach ([config('dply.public_app_url'), config('app.url')] as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $root = rtrim(trim($raw), '/');
            if (! preg_match('~^https?://~i', $root)) {
                $root = 'https://'.$root;
            }

            $parts = parse_url($root);
            $host = is_array($parts) ? ($parts['host'] ?? null) : null;
            if (! is_string($host) || $host === '') {
                $roots[] = $root;

                continue;
            }

            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            // webhookUrl() forceRootUrl can keep the current request scheme
            // (http in phpunit) even when the configured origin is https.
            $roots[] = 'https://'.$host.$port;
            $roots[] = 'http://'.$host.$port;
        }

        return array_values(array_unique($roots));
    }

    private function urlOnRoot(Request $request, string $root): string
    {
        $url = $root.$request->getBaseUrl().$request->getPathInfo();
        $query = $request->getQueryString();

        if (is_string($query) && $query !== '') {
            $url .= '?'.$query;
        }

        return $url;
    }
}
