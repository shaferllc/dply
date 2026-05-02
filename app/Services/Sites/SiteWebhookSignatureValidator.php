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
     * @return array{
     *     ok: bool,
     *     status: int,
     *     outcome: string,
     *     detail: ?string,
     *     message: string,
     *     auth_mode?: string,
     *     github_event?: string,
     *     gitlab_event?: string
     * }
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

        $githubSig = (string) $request->header('X-Hub-Signature-256', '');
        if ($githubSig !== '') {
            return $this->validateGitHubStyleSignature($secret, $payload, $githubSig, $request, 'github');
        }

        $bbSig = (string) $request->header('X-Hub-Signature', '');
        if ($bbSig !== '' && str_starts_with(strtolower($bbSig), 'sha256=')) {
            return $this->validateGitHubStyleSignature($secret, $payload, $bbSig, $request, 'bitbucket');
        }

        $gitlabToken = (string) $request->header('X-Gitlab-Token', '');
        if ($gitlabToken !== '') {
            if (! hash_equals((string) $secret, $gitlabToken)) {
                return [
                    'ok' => false,
                    'status' => 401,
                    'outcome' => WebhookDeliveryLog::OUTCOME_REJECTED,
                    'detail' => 'invalid_gitlab_token',
                    'message' => 'Invalid GitLab webhook token.',
                ];
            }

            return [
                'ok' => true,
                'status' => 202,
                'outcome' => WebhookDeliveryLog::OUTCOME_ACCEPTED,
                'detail' => null,
                'message' => 'Deployment queued.',
                'auth_mode' => 'gitlab',
                'gitlab_event' => (string) $request->header('X-Gitlab-Event', ''),
            ];
        }

        return $this->validateDplySignature($request, $site, $secret, $payload);
    }

    /**
     * @return array{ok: bool, status: int, outcome: string, detail: ?string, message: string, auth_mode?: string, github_event?: string}
     */
    private function validateGitHubStyleSignature(string $secret, string $payload, string $sigHeader, Request $request, string $mode): array
    {
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);
        if (! hash_equals($expected, $sigHeader)) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => WebhookDeliveryLog::OUTCOME_REJECTED,
                'detail' => 'invalid_github_signature',
                'message' => 'Invalid signature.',
            ];
        }

        return [
            'ok' => true,
            'status' => 202,
            'outcome' => WebhookDeliveryLog::OUTCOME_ACCEPTED,
            'detail' => null,
            'message' => 'Deployment queued.',
            'auth_mode' => $mode,
            'github_event' => (string) $request->header('X-GitHub-Event', ''),
        ];
    }

    /**
     * @return array{ok: bool, status: int, outcome: string, detail: ?string, message: string, auth_mode?: string}
     */
    private function validateDplySignature(Request $request, Site $site, string $secret, string $payload): array
    {
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
            'auth_mode' => 'dply',
        ];
    }
}
