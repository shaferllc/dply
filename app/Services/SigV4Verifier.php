<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ServiceCredential;
use Illuminate\Http\Request;

/**
 * Verifies an AWS Signature Version 4 on an inbound request.
 *
 * Kernel, not module: dply Queue (SQS-compatible) and dply Cache
 * (DynamoDB-compatible) both authenticate this way, and the credential they
 * authenticate is a single {@see ServiceCredential}. Which
 * service a request is for is carried by the SigV4 credential scope itself
 * (`…/sqs/aws4_request` vs `…/dynamodb/aws4_request`), so {@see parse()}
 * returns it and the caller routes on it. See docs/adr/dply-cache.md,
 * decision 6.
 *
 * ## Why this does not call the SDK's signer
 *
 * The obvious approach — reconstruct the request, re-sign it with
 * `Aws\Signature\SignatureV4::signRequest()`, compare — is wrong, and subtly
 * so. That method overwrites `X-Amz-Date` with `gmdate()` at the moment it
 * runs (SignatureV4.php:116-119), so verification would sign with the
 * *server's* current second rather than the second the client signed at. It
 * therefore only matches when the two coincide: in tests, where client and
 * server are microseconds apart, it passes most of the time; across a real
 * network it would fail for nearly every request. That intermittency is how
 * the bug surfaced.
 *
 * The SDK helpers that would let a subclass reuse the correct parts
 * (`createContext`, `createStringToSign`) are private, so there is no seam.
 * Hence: build the canonical request directly, mirroring
 * `SignatureV4::createContext()`, and take the timestamp from what the client
 * actually sent.
 *
 * The risk of reimplementing SigV4 is drift from the real thing. That is
 * covered by the suite signing with the SDK's own `SignatureV4` and asserting
 * acceptance — the SDK is the oracle, so divergence fails loudly here rather
 * than quietly in production.
 *
 * Only headers the client declared in `SignedHeaders` are canonicalised. Load
 * balancers routinely add headers, and signing over anything the client did
 * not sign would break every request that passes through one.
 */
final class SigV4Verifier
{
    /** Reject signatures outside this window. Matches AWS. */
    private const MAX_CLOCK_SKEW_SECONDS = 900;

    /** AWS service name in the credential scope => the dply service it maps to. */
    public const SERVICE_MAP = [
        'sqs' => ServiceCredential::SERVICE_QUEUE,
        'dynamodb' => ServiceCredential::SERVICE_CACHE,
    ];

    /**
     * @return array{ok: bool, access_key_id: ?string, service: ?string, error: ?string}
     */
    public function parse(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'AWS4-HMAC-SHA256')) {
            return ['ok' => false, 'access_key_id' => null, 'service' => null, 'error' => 'Missing or unsupported Authorization header.'];
        }

        $credential = $this->part($header, 'Credential');

        if ($credential === null
            || $this->part($header, 'SignedHeaders') === null
            || $this->part($header, 'Signature') === null) {
            return ['ok' => false, 'access_key_id' => null, 'service' => null, 'error' => 'Malformed Authorization header.'];
        }

        // Credential=<accessKeyId>/<date>/<region>/<service>/aws4_request
        $segments = explode('/', $credential);

        if (count($segments) < 5) {
            return ['ok' => false, 'access_key_id' => null, 'service' => null, 'error' => 'Malformed credential scope.'];
        }

        // Segment 3 is the AWS service name. Unknown services resolve to null
        // rather than a guess: the caller must be able to reject a signature
        // scoped to a service dply does not implement, and silently defaulting
        // to one of them would let an `sqs`-scoped key address a cache.
        $service = self::SERVICE_MAP[strtolower($segments[3])] ?? null;

        if ($service === null) {
            return ['ok' => false, 'access_key_id' => null, 'service' => null, 'error' => 'Unsupported service in the credential scope.'];
        }

        return ['ok' => true, 'access_key_id' => $segments[0], 'service' => $service, 'error' => null];
    }

    public function verify(Request $request, string $accessKeyId, string $secret): bool
    {
        $header = (string) $request->header('Authorization', '');
        $credential = $this->part($header, 'Credential');
        $signedHeaders = $this->part($header, 'SignedHeaders');
        $presented = $this->part($header, 'Signature');

        if ($credential === null || $signedHeaders === null || $presented === null) {
            return false;
        }

        $segments = explode('/', $credential);

        if (count($segments) < 5) {
            return false;
        }

        [, $shortDate, $region, $service] = $segments;

        $longDate = (string) $request->header('X-Amz-Date', '');

        if ($longDate === '' || ! $this->timestampIsFresh($longDate)) {
            return false;
        }

        // The scope date must agree with the signed timestamp, or a captured
        // signature could be replayed against another day's signing key.
        if (! str_starts_with($longDate, $shortDate)) {
            return false;
        }

        $scope = $shortDate.'/'.$region.'/'.$service.'/aws4_request';
        $payloadHash = $this->payloadHash($request);
        $signingKey = $this->signingKey($secret, $shortDate, $region, $service);

        // A trailing slash is significant to the canonical URI and is routinely
        // added or removed in transit — the AWS SDK posts to `/api/queue/v1/`,
        // while proxies and rewrite rules normalise it away. Strictness gives
        // the worst failure mode: works direct, fails behind a load balancer,
        // with only "signature mismatch" to go on.
        foreach ($this->candidatePaths($request) as $path) {
            $canonical = $this->canonicalRequest($request, $path, $signedHeaders, $payloadHash);
            $stringToSign = "AWS4-HMAC-SHA256\n{$longDate}\n{$scope}\n".hash('sha256', $canonical);

            if (hash_equals(hash_hmac('sha256', $stringToSign, $signingKey), $presented)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors `SignatureV4::createContext()`: method, canonical path, canonical
     * query, canonical headers, signed-header list, payload hash.
     */
    private function canonicalRequest(Request $request, string $path, string $signedHeaders, string $payloadHash): string
    {
        $names = array_values(array_filter(array_map('trim', explode(';', strtolower($signedHeaders)))));
        sort($names);

        $canonicalHeaders = '';
        foreach ($names as $name) {
            // Internal whitespace collapses; the value is trimmed.
            $value = (string) $request->header($name, '');
            $canonicalHeaders .= $name.':'.preg_replace('/\s+/', ' ', trim($value))."\n";
        }

        return $request->getMethod()."\n"
            .$this->canonicalPath($path)."\n"
            .$this->canonicalQuery($request)."\n"
            .$canonicalHeaders."\n"
            .implode(';', $names)."\n"
            .$payloadHash;
    }

    /** Mirrors `SignatureV4::createCanonicalizedPath()`. */
    private function canonicalPath(string $path): string
    {
        return '/'.str_replace('%2F', '/', rawurlencode(ltrim($path, '/')));
    }

    private function canonicalQuery(Request $request): string
    {
        $query = $request->getQueryString();

        if ($query === null || $query === '') {
            return '';
        }

        parse_str($query, $params);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            foreach ((array) $value as $item) {
                $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * The SDK sends a body hash header for streaming operations and hashes the
     * body otherwise. Honour the header when present so both paths verify.
     */
    private function payloadHash(Request $request): string
    {
        $declared = (string) $request->header('X-Amz-Content-Sha256', '');

        return $declared !== '' ? $declared : hash('sha256', $request->getContent());
    }

    /** The four-step SigV4 key derivation. */
    private function signingKey(string $secret, string $shortDate, string $region, string $service): string
    {
        $date = hash_hmac('sha256', $shortDate, 'AWS4'.$secret, true);
        $regionKey = hash_hmac('sha256', $region, $date, true);
        $serviceKey = hash_hmac('sha256', $service, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    /**
     * The path as received, plus its trailing-slash counterpart.
     *
     * @return list<string>
     */
    private function candidatePaths(Request $request): array
    {
        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);

        if ($path === '') {
            $path = '/';
        }

        $trimmed = rtrim($path, '/');

        return array_values(array_unique([
            $path,
            $trimmed.'/',
            $trimmed === '' ? '/' : $trimmed,
        ]));
    }

    private function timestampIsFresh(string $stamp): bool
    {
        $parsed = strtotime($stamp);

        if ($parsed === false) {
            return false;
        }

        return abs(time() - $parsed) <= self::MAX_CLOCK_SKEW_SECONDS;
    }

    /** Pull `Key=value` out of the Authorization header. */
    private function part(string $header, string $key): ?string
    {
        if (preg_match('/'.preg_quote($key, '/').'=([^,\s]+)/', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
