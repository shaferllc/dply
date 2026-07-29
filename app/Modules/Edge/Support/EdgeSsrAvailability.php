<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Modules\Edge\Services\EdgeCloudflareClient;
use RuntimeException;

/**
 * Whether Worker-native SSR can be offered / created.
 *
 * Availability is driven by platform Cloudflare credentials (account + API
 * token). The dispatch namespace id does not need to be pre-seeded in .env —
 * create/upload paths ensure the namespace via the API when the token can.
 */
final class EdgeSsrAvailability
{
    public static function isAvailable(): bool
    {
        if (FakeEdgeProvision::enabled()) {
            return true;
        }

        return self::hasPlatformCredentials() && self::dispatchNamespaceName() !== '';
    }

    public static function unavailableReason(): ?string
    {
        if (self::isAvailable()) {
            return null;
        }

        if (! self::hasPlatformCredentials()) {
            return __('Set DPLY_EDGE_CF_ACCOUNT_ID and DPLY_EDGE_CF_API_TOKEN (Workers for Platforms permission), or use Hybrid with a Cloud origin.');
        }

        return __('SSR is unavailable — configure a dispatch namespace name or use Hybrid with a Cloud origin.');
    }

    /**
     * Resolve (or create) the platform dispatch namespace id when credentials
     * allow it. No-op / empty when Fake Edge is on or credentials are missing.
     */
    public static function ensureDispatchNamespaceId(): string
    {
        if (FakeEdgeProvision::enabled()) {
            return 'fake-dispatch';
        }

        $configured = trim((string) config('edge.cloudflare.dispatch_namespace_id', ''));
        if ($configured !== '') {
            return $configured;
        }

        if (! self::hasPlatformCredentials()) {
            throw new RuntimeException(
                'SSR Edge sites need DPLY_EDGE_CF_ACCOUNT_ID and DPLY_EDGE_CF_API_TOKEN. '
                .'Use Hybrid with a Cloud origin, or set those credentials and retry.',
            );
        }

        $name = self::dispatchNamespaceName();
        if ($name === '') {
            throw new RuntimeException(
                'SSR Edge sites need a Workers for Platforms dispatch namespace name '
                .'(DPLY_EDGE_CF_DISPATCH_NAMESPACE).',
            );
        }

        try {
            return EdgeCloudflareClient::fromConfig()->ensureDispatchNamespace($name);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Could not ensure the SSR dispatch namespace via the Cloudflare API ('
                .$e->getMessage()
                .'). Check that the API token has Workers for Platforms / dispatch namespace permission '
                .'and the account is on Workers Paid, or use Hybrid with a Cloud origin.',
                0,
                $e,
            );
        }
    }

    public static function assertAvailable(): void
    {
        if (self::isAvailable()) {
            return;
        }

        throw new RuntimeException(
            self::unavailableReason()
                ?? 'Worker-native SSR is not available in this environment.',
        );
    }

    private static function hasPlatformCredentials(): bool
    {
        return trim((string) config('edge.cloudflare.account_id', '')) !== ''
            && trim((string) config('edge.cloudflare.api_token', '')) !== '';
    }

    private static function dispatchNamespaceName(): string
    {
        return trim((string) config('edge.cloudflare.dispatch_namespace_name', ''));
    }
}
