<?php

namespace App\Services\Insights\Runners;

use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

class SslCertificateInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site === null) {
            return [];
        }

        if ($site->ssl_status === Site::SSL_ACTIVE) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'ssl_certificate_checks',
                dedupeHash: 'ssl_'.$site->ssl_status,
                severity: $site->ssl_status === Site::SSL_FAILED ? 'critical' : 'warning',
                title: __('SSL is not active'),
                body: __('SSL status for this site is “:status”. Issuance may be pending or failed.', [
                    'status' => $site->ssl_status,
                ]),
                meta: ['ssl_status' => $site->ssl_status],
            ),
        ];
    }
}
