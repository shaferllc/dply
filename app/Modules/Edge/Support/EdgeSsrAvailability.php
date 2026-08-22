<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Whether Worker-native SSR can be offered / created.
 *
 * Availability is driven by platform credentials. A failed dispatch-namespace
 * ensure (plan/permission) is cached so the create UI stops offering SSR and
 * steers operators to Hybrid instead of repeating a hard API error.
 */
final class EdgeSsrAvailability
{
    private const DISPATCH_DENIED_CACHE_KEY = 'edge.ssr.dispatch_namespace_denied';

    public static function isAvailable(): bool
    {
        if (FakeEdgeProvision::enabled()) {
            return true;
        }

        if (! self::hasPlatformCredentials() || self::dispatchNamespaceName() === '') {
            return false;
        }

        return ! Cache::has(self::DISPATCH_DENIED_CACHE_KEY);
    }

    public static function unavailableReason(): ?string
    {
        if (self::isAvailable()) {
            return null;
        }

        if (Cache::has(self::DISPATCH_DENIED_CACHE_KEY)) {
            return __('Worker-native SSR isn’t available on this Edge account. Use Hybrid with a Cloud origin instead.');
        }

        if (! self::hasPlatformCredentials()) {
            return __('Worker-native SSR isn’t configured yet. Use Hybrid with a Cloud origin, or finish Edge platform setup.');
        }

        return __('Worker-native SSR isn’t available. Use Hybrid with a Cloud origin instead.');
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
            Cache::forget(self::DISPATCH_DENIED_CACHE_KEY);

            return $configured;
        }

        if (! self::hasPlatformCredentials()) {
            throw new RuntimeException(
                (string) __('Worker-native SSR isn’t configured yet. Use Hybrid with a Cloud origin instead.'),
            );
        }

        $name = self::dispatchNamespaceName();
        if ($name === '') {
            throw new RuntimeException(
                (string) __('Worker-native SSR isn’t available. Use Hybrid with a Cloud origin instead.'),
            );
        }

        try {
            $id = EdgeCloudflareClient::fromConfig()->ensureDispatchNamespace($name);
            Cache::forget(self::DISPATCH_DENIED_CACHE_KEY);

            return $id;
        } catch (\Throwable $e) {
            Cache::put(self::DISPATCH_DENIED_CACHE_KEY, true, now()->addHours(6));
            Log::warning('Edge SSR dispatch namespace ensure failed', [
                'namespace' => $name,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                (string) __('Worker-native SSR isn’t available on this Edge account. Switch runtime to Hybrid and deploy with a Cloud origin.'),
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
                ?? (string) __('Worker-native SSR isn’t available. Use Hybrid with a Cloud origin instead.'),
        );
    }

    /** @internal testing */
    public static function forgetDispatchDeniedCache(): void
    {
        Cache::forget(self::DISPATCH_DENIED_CACHE_KEY);
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
