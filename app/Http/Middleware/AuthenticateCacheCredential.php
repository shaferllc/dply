<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ServiceCredential;
use App\Modules\Cache\Support\CacheRequestContext;
use App\Services\ServiceCredentialResolver;
use App\Services\SigV4Verifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a dply Cache request by AWS SigV4.
 *
 * Sibling of {@see AuthenticateQueueCredential}, and separate from it on
 * purpose: the two products resolve different things (a queue's namespace comes
 * from the credential, a cache's table comes from the request) and share only
 * the credential and the verifier, both of which now live in the kernel.
 *
 * SigV4 only — there is no bearer path here. The queue grew one for its
 * dply-native endpoints, which are not part of any compatibility contract. Every
 * cache request IS a DynamoDB request, and the AWS SDK always signs.
 */
class AuthenticateCacheCredential
{
    public function __construct(
        private readonly ServiceCredentialResolver $resolver,
        private readonly SigV4Verifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $parsed = $this->verifier->parse($request);

        if (! $parsed['ok'] || $parsed['access_key_id'] === null) {
            return $this->deny('UnrecognizedClientException', $parsed['error'] ?? 'Unauthorized.');
        }

        // An `sqs`-scoped signature must not authenticate a cache request even
        // when the key legitimately holds both grants. The scope is part of
        // what the client signed, so honouring it is what keeps one product's
        // credential from being spent on the other's endpoint.
        if ($parsed['service'] !== ServiceCredential::SERVICE_CACHE) {
            return $this->deny('UnrecognizedClientException', 'The security token included in the request is invalid.');
        }

        $credential = $this->resolver->resolve($parsed['access_key_id']);

        if (! $credential instanceof ServiceCredential) {
            // One response for "no such credential" and "revoked", so the
            // endpoint cannot be used to enumerate keys.
            return $this->deny('UnrecognizedClientException', 'The security token included in the request is invalid.');
        }

        $secret = (string) $credential->secret;

        if ($secret === '' || ! $this->verifier->verify($request, $parsed['access_key_id'], $secret)) {
            return $this->deny('InvalidSignatureException', 'The request signature we calculated does not match the signature you provided.');
        }

        $request->attributes->set('cache_context', new CacheRequestContext($credential));

        return $next($request);
    }

    /**
     * DynamoDB's error envelope. The SDK parses `__type` to decide which
     * exception to raise and whether to retry; anything else reaches the
     * customer as an unparseable transport failure with no clue what went wrong.
     */
    private function deny(string $code, string $message): Response
    {
        return response()->json([
            '__type' => 'com.amazonaws.dynamodb.v20120810#'.$code,
            'message' => $message,
        ], 400, ['Content-Type' => 'application/x-amz-json-1.0']);
    }
}
