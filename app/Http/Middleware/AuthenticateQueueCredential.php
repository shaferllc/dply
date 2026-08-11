<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\QueueCredentialResolver;
use App\Modules\Queue\Services\SigV4Verifier;
use App\Modules\Queue\Support\QueueRequestContext;
use App\Modules\Queue\Support\QueueTier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a dply Queue request by AWS SigV4.
 *
 * Deliberately not `auth.api`. That middleware is built for a human's API
 * token: it resolves a `User`, bcrypt-checks the hash, writes `last_used_at`
 * on every call, and carries org-wide abilities. All four are wrong for a
 * credential that lives in a container, is presented hundreds of times a
 * minute, and must be able to do exactly one thing.
 *
 * The resolved namespace is attached to the request as a
 * {@see QueueRequestContext}. Controllers read tenancy from there and only
 * there — never from the body or the path.
 */
class AuthenticateQueueCredential
{
    public function __construct(
        private readonly QueueCredentialResolver $resolver,
        private readonly SigV4Verifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Two schemes, because the surface serves two protocols. SQS clients
        // must sign with SigV4 — that is the compatibility contract. The
        // dply-native endpoints (locks, failed jobs) take a bearer token
        // instead: they are not SQS operations, so there is no reason to make
        // a caller sign, and requiring it would mean shipping a SigV4 signer
        // into every customer app.
        $resolved = $this->bearer($request) !== null
            ? $this->resolver->resolveBySecret((string) $this->bearer($request))
            : $this->fromSignature($request);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        if ($resolved === null) {
            // One response for "no such credential", "revoked", and "bad
            // signature", so the endpoint cannot be used to enumerate keys.
            return $this->deny('InvalidClientTokenId', 'The security token included in the request is invalid.');
        }

        $credential = $resolved['credential'];

        $request->attributes->set('queue_context', new QueueRequestContext(
            namespace: $resolved['namespace'],
            credential: $credential,
            // Throughput is bought per namespace by tier, not granted by the
            // org's plan — a namespace that pays for more gets more. An unknown
            // tier slug resolves to the configured default rather than
            // throttling a live queue to nothing over a config typo.
            requestsPerMinute: QueueTier::resolve($resolved['namespace']->tier)->requestsPerMinute,
        ));

        return $next($request);
    }

    /** The bearer secret, when the caller used that scheme. */
    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    /**
     * Resolve and verify a SigV4-signed request.
     *
     * @return array{credential: QueueCredential, namespace: QueueNamespace}|Response|null
     */
    private function fromSignature(Request $request): array|Response|null
    {
        $parsed = $this->verifier->parse($request);

        if (! $parsed['ok'] || $parsed['access_key_id'] === null) {
            return $this->deny('InvalidClientTokenId', $parsed['error'] ?? 'Unauthorized.');
        }

        $resolved = $this->resolver->resolve($parsed['access_key_id']);

        if ($resolved === null) {
            return null;
        }

        $secret = (string) $resolved['credential']->secret;

        if ($secret === '' || ! $this->verifier->verify($request, $parsed['access_key_id'], $secret)) {
            return $this->deny('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
        }

        return $resolved;
    }

    /**
     * AWS JSON protocol error shape, so the SDK surfaces something meaningful
     * rather than a generic transport failure.
     */
    private function deny(string $code, string $message): Response
    {
        return response()->json([
            '__type' => 'com.amazon.coral.service#'.$code,
            'message' => $message,
        ], 403, ['Content-Type' => 'application/x-amz-json-1.0']);
    }
}
