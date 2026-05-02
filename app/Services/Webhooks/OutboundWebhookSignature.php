<?php

namespace App\Services\Webhooks;

/**
 * HMAC-SHA256 signature for outbound webhooks. Format mirrors the inbound
 * SiteWebhookSignatureValidator: `t=<unix>,v1=<hex>` over `<unix>.<body>`.
 */
class OutboundWebhookSignature
{
    public static function header(string $secret, int $timestamp, string $body): string
    {
        $payload = $timestamp.'.'.$body;
        $hex = hash_hmac('sha256', $payload, $secret);

        return 't='.$timestamp.',v1='.$hex;
    }
}
