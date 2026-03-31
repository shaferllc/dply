<?php

namespace App\Services\Serverless;

use Dply\Core\Net\IpAllowList;
use Dply\Core\Security\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class ServerlessWebhookSignatureValidator
{
    /**
     * @return array{ok: bool, status: int, message: string}
     */
    public function validate(Request $request): array
    {
        $secret = config('serverless.webhook_secret');
        if ($secret === null || $secret === '') {
            return [
                'ok' => false,
                'status' => 400,
                'message' => 'Webhook secret not configured.',
            ];
        }

        $allowed = config('serverless.webhook_allowed_ips');
        if (is_array($allowed) && $allowed !== []) {
            $ip = (string) $request->ip();
            if (! IpAllowList::contains($ip, $allowed)) {
                return [
                    'ok' => false,
                    'status' => 403,
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
            $skew = (int) config('serverless.webhook_timestamp_tolerance', 300);
            if ($ts <= 0 || abs(time() - $ts) > $skew) {
                return [
                    'ok' => false,
                    'status' => 401,
                    'message' => 'Stale or invalid timestamp.',
                ];
            }
        }

        $mode = WebhookSignature::verify($secret, $payload, $sigHeader, $ts);
        if ($mode === 'timestamped') {
            $replayKey = 'serverless-webhook-replay:'.$ts.':'.hash('sha256', $payload);
            if (! Cache::add($replayKey, 1, now()->addMinutes(15))) {
                return [
                    'ok' => false,
                    'status' => 409,
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
                'message' => 'Invalid signature.',
            ];
        }

        return [
            'ok' => true,
            'status' => 202,
            'message' => 'Deployment queued.',
        ];
    }
}
