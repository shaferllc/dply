<?php

/**
 * dply logging shim for a raw OpenWhisk PHP action.
 *
 * Injected at deploy time by App\Services\Deploy\ServerlessLoggingShimInjector.
 * Do not edit in the user's repo — dply overwrites this file on every deploy.
 *
 * The DigitalOcean Functions activations list API is structurally empty, so
 * an un-wrapped raw action is invisible to dply. This shim wraps the repo's
 * own action and fire-and-forget POSTs each organic invocation to dply's
 * ingest endpoint, exactly as the Laravel adapter does for framework apps.
 */

require_once __DIR__.'/{{DPLY_ENTRY}}';

if (! function_exists('dply_raw_report')) {
    /**
     * @param  array<string, mixed>  $args
     */
    function dply_raw_report(array $args, int $status, int $durationMs): void
    {
        try {
            $headers = is_array($args['__ow_headers'] ?? null) ? $args['__ow_headers'] : [];
            // dply-initiated invocations are already captured inline by the
            // caller — never double-report them.
            foreach (['x-dply-run', 'x-dply-source'] as $marker) {
                if (trim((string) ($headers[$marker] ?? '')) !== '') {
                    return;
                }
            }

            $endpoint = trim((string) (getenv('DPLY_LOG_INGEST_URL') ?: ''));
            $secret = trim((string) (getenv('DPLY_LOG_INGEST_SECRET') ?: ''));
            if ($endpoint === '' || $secret === '') {
                return;
            }

            $host = (string) parse_url($endpoint, PHP_URL_HOST);
            if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
                return;
            }

            $payload = json_encode([
                'method' => strtoupper((string) ($args['__ow_method'] ?? 'GET')),
                'path' => '/'.ltrim((string) ($args['__ow_path'] ?? ''), '/'),
                'status' => $status,
                'duration_ms' => $durationMs,
                'logs' => [],
                'context' => [],
            ]);
            if (! is_string($payload)) {
                return;
            }

            $ch = curl_init($endpoint);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Dply-Signature: '.hash_hmac('sha256', $payload, $secret),
                ],
                CURLOPT_TIMEOUT_MS => 800,
                CURLOPT_CONNECTTIMEOUT_MS => 400,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOSIGNAL => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            // fire-and-forget — never let reporting affect the response
        }
    }
}

if (! function_exists('dplyMain')) {
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    function dplyMain(array $args): array
    {
        $start = microtime(true);
        $thrown = null;
        try {
            $result = main($args);
            if (! is_array($result)) {
                $result = ['body' => $result];
            }
            $status = (int) ($result['statusCode'] ?? 200);
        } catch (\Throwable $e) {
            $thrown = $e;
            $status = 500;
            $result = ['statusCode' => 500, 'body' => $e->getMessage()];
        }

        dply_raw_report($args, $status, (int) round((microtime(true) - $start) * 1000));

        if ($thrown !== null) {
            throw $thrown;
        }

        return $result;
    }
}
