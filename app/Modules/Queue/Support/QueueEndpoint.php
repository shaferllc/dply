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

        // Same reachability rule as the serverless log-ingest URL: a function
        // on DigitalOcean cannot reach a local *.test address, so an unset
        // public URL means the feature is simply not offered.
        $public = trim((string) config('dply.public_app_url', ''));

        if ($public === '') {
            return '';
        }

        if (preg_match('~^https?://~i', $public) !== 1) {
            $public = 'https://'.$public;
        }

        return rtrim($public, '/').'/api/queue/v1';
    }
}
