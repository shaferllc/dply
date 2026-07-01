<?php

declare(strict_types=1);

namespace App\Support\Debug;

use Lookout\Tracing\Reporting\ErrorReportClient;

/**
 * The per-request error reference for dply's OWN control-plane 500s — the
 * occurrence id the Lookout SDK stamps on the exception it reports. Displaying
 * it on the branded 500 (and as the X-Dply-Ref response header) lets a user
 * quote "ref XYZ" and a platform admin resolve it straight to the Lookout
 * event / the debug page's ?ref= fetch-back — no SSH log-grep, unlike the
 * customer-site X-Dply-Ref path.
 *
 * Returns null when the Lookout SDK isn't installed or nothing was reported for
 * this request (e.g. no DSN configured) — there's simply nothing to reference.
 */
final class DebugReference
{
    public static function current(): ?string
    {
        if (! class_exists(ErrorReportClient::class)) {
            return null;
        }

        $ref = ErrorReportClient::lastOccurrenceUuid();

        return is_string($ref) && $ref !== '' ? $ref : null;
    }
}
