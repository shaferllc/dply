<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ServiceCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueRequestContext;
use App\Modules\Queue\Support\QueueTier;
use App\Services\ServiceCredentialResolver;
use App\Services\SigV4Verifier;
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
        private readonly ServiceCredentialResolver $resolver,
        private readonly SigV4Verifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Two schemes, because the surface serves two protocols. SQS clients
        // must sign with SigV4 — that is the compatibility contract. The
        // dply-native endpoints (failed jobs) take a bearer token instead:
        // they are not SQS operations, so there is no reason to make a caller
        // sign, and requiring it would mean shipping a SigV4 signer into every
        // customer app.
        $resolved = $this->bearer($request) !== null
            ? $this->resolver->resolveBySecret((string) $this->bearer($request))
            : $this->fromSignature($request);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        if (! $resolved instanceof ServiceCredential) {
            // One response for "no such credential", "revoked", and "bad
            // signature", so the endpoint cannot be used to enumerate keys.
            return $this->deny('InvalidClientTokenId', 'The security token included in the request is invalid.');
        }

        $namespace = $this->namespaceFor($resolved);

        if (! $namespace instanceof QueueNamespace) {
            return $this->deny('InvalidClientTokenId', 'The security token included in the request is invalid.');
        }

        $request->attributes->set('queue_context', new QueueRequestContext(
            namespace: $namespace,
            credential: $resolved,
            // Throughput is bought per namespace by tier, not granted by the
            // org's plan — a namespace that pays for more gets more. An unknown
            // tier slug resolves to the configured default rather than
            // throttling a live queue to nothing over a config typo.
            requestsPerMinute: QueueTier::resolve($namespace->tier)->requestsPerMinute,
        ));

        return $next($request);
    }

    /**
     * The namespace this key addresses.
     *
     * Still derived from the credential and never from the request, which is
     * what keeps a client from naming someone else's namespace — the SQS wire
     * format has no namespace field at all (`QueueUrl` names a *queue within*
     * a namespace), so there is nowhere for one to be smuggled in.
     *
     * A key granted on two namespaces is therefore unaddressable rather than
     * ambiguous, and is refused. Every mint path in the product issues exactly
     * one queue grant, so this is an invariant being enforced, not a case
     * being handled: silently picking the first grant would make which
     * namespace a request hit depend on jsonb key order.
     */
    private function namespaceFor(ServiceCredential $credential): ?QueueNamespace
    {
        $ids = $credential->resourceIds(ServiceCredential::SERVICE_QUEUE);

        if (count($ids) !== 1) {
            return null;
        }

        $namespace = QueueNamespace::query()->find($ids[0]);

        return $namespace instanceof QueueNamespace && $namespace->isReachable()
            ? $namespace
            : null;
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
     */
    private function fromSignature(Request $request): ServiceCredential|Response|null
    {
        $parsed = $this->verifier->parse($request);

        if (! $parsed['ok'] || $parsed['access_key_id'] === null) {
            return $this->deny('InvalidClientTokenId', $parsed['error'] ?? 'Unauthorized.');
        }

        // A signature scoped to `dynamodb` must not authenticate a queue
        // request even when the key is valid for both services. The scope is
        // part of what was signed, so honouring it here is what stops one
        // grant being spent on the other product's endpoint.
        if ($parsed['service'] !== ServiceCredential::SERVICE_QUEUE) {
            return $this->deny('InvalidClientTokenId', 'The security token included in the request is invalid.');
        }

        $credential = $this->resolver->resolve($parsed['access_key_id']);

        if (! $credential instanceof ServiceCredential) {
            return null;
        }

        $secret = (string) $credential->secret;

        if ($secret === '' || ! $this->verifier->verify($request, $parsed['access_key_id'], $secret)) {
            return $this->deny('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
        }

        return $credential;
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
