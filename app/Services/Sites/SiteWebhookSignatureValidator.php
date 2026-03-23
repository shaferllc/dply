<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\WebhookDeliveryLog;
use Dply\Core\Net\IpAllowList;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SiteWebhookSignatureValidator
{
    /**
     * @return array{ok: bool, status: int, outcome: string, detail: ?string, message: string}
     */
    public function validateForWebhook(Request $request, Site $site): array
    {
        $secret = $site->webhook_secret;
        if ($secret === null || $secret === '') {
            return [
                'ok' => false,
                'status' => 400,
                'outcome' => WebhookDeliveryLog::OUTCOME_REJECTED,
                'detail' => 'secret_missing',
                'message' => 'Webhook secret not configured.',
            ];
        }

        $allowed = $site->webhook_allowed_ips;
        if (is_array($allowed) && $allowed !== []) {
            $ip = (string) $request->ip();
            if (! IpAllowList::contains($ip, $allowed)) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'outcome' => WebhookDeliveryLog::OUTCOME_REJECTED,
                    'detail' => 'ip_not_allowed',
                    'message' => 'Source IP not allowed for this webhook.',
                ];
            }
        }

        $payload = $request->getContent();
        $sigHeader = (string) $request->header('X-Dply-Signature', '');

        $valid = false;
        $timestampHeader = $request->header('X-Dply-Timestamp');
        $ts = null;
        if ($timestampHeader !== null && $timestampHeader !== '') {
            $ts = (int) $timestampHeader;
            $skew = (int) config('sites.webhook_timestamp_tolerance', 300);
            if ($ts <= 0 || abs(time() - $ts) > $skew) {
                return [
                    'ok' => false,
                    'status' => 401,
                    'outcome' => WebhookDeliveryLog::OUTCOME_REJECTED,
                    'detail' => 'stale_timestamp',
                    'message' => 'Stale or invalid timestamp.',
                ];
            }
        }

        $mode = WebhookSignature::verify($secret, $payload, $sigHeader, $ts);
        if ($mode === 'timestamped') {
            $replayKey = 'webhook-replay:'.$site->id.':'.$ts.':'.hash('sha256', $payload);
            if (! Cache::add($replayKey, 1, now()->addMinutes(15))) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'outcome' => WebhookDeliveryLog::OUTCOME_REJECTED,
                    'detail' => 'duplicate_delivery',
                    'message' => 'Duplicate webhook delivery.',
                ];
            }
            $valid = true;
        } elseif ($mode === 'legacy') {
            $valid = true;
        }

        if (! $valid) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => WebhookDeliveryLog::OUTCOME_REJECTED,
                'detail' => 'invalid_signature',
                'message' => 'Invalid signature.',
            ];
        }

        return [
            'ok' => true,
            'status' => 202,
            'outcome' => WebhookDeliveryLog::OUTCOME_ACCEPTED,
            'detail' => null,
            'message' => 'Deployment queued.',
        ];
    }
}
