<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

use App\Modules\Queue\Models\QueueNamespace;

/**
 * The public URL a customer's queue client posts to.
 *
 * Shared by the deploy-time env writer and the dashboard, which must show the
 * customer exactly the string their app will be configured with. Two copies of
 * this would drift, and the failure mode of drift here is a documented endpoint
 * that does not work.
 */
final class QueueEndpoint
{
    /** The SQS-compatible endpoint for one namespace, or '' when unavailable. */
    public static function forNamespace(QueueNamespace $namespace): string
    {
        $base = self::base();

        return $base === '' ? '' : $base.'/'.$namespace->id;
    }

    /** The API base, or '' when dply has no publicly reachable URL configured. */
    public static function base(): string
    {
        $configured = trim((string) config('queue_service.public_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // Then the platform's own public address, then APP_URL — the same
        // preference order `AcmeDnsHook` and `ValidateWebhookSignature` use.
        // APP_URL is included because on a real deployment it IS the public
        // address, and requiring a second variable that merely repeats it meant
        // the entire managed-queue surface silently vanished in production
        // while the worker fleets, which resolve through here, worked fine.
        foreach ([config('dply.public_app_url'), config('app.url')] as $candidate) {
            $base = self::publiclyReachable((string) $candidate);

            if ($base !== '') {
                return $base.'/api/queue/v1';
            }
        }

        return '';
    }

    /**
     * A URL a customer's server could actually resolve, or ''.
     *
     * The reachability rule this class exists for: APP_URL is typically a local
     * `*.test` in development, and a worker on DigitalOcean cannot reach it. An
     * endpoint that looks configured but is unroutable is worse than none — it
     * would be written into a customer's .env and fail on every push — so a
     * local-looking host resolves to '' and the feature is simply not offered.
     */
    private static function publiclyReachable(string $candidate): string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return '';
        }

        if (preg_match('~^https?://~i', $candidate) !== 1) {
            $candidate = 'https://'.$candidate;
        }

        $host = strtolower((string) (parse_url($candidate, PHP_URL_HOST) ?: ''));

        if ($host === '') {
            return '';
        }

        $isLocal = $host === 'localhost'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost')
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($host, FILTER_VALIDATE_IP) !== false;

        return $isLocal ? '' : rtrim($candidate, '/');
    }
}
