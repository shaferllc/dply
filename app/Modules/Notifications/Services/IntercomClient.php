<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Intercom REST API for Intercom notification channels.
 *
 * Intercom's failure shape is a fourth variant again. Unlike Slack (HTTP 200 +
 * `ok:false`) it uses honest status codes, and unlike Telegram it does give
 * stable machine-readable codes — but they arrive nested under an `errors`
 * array, several entries deep, and the first entry is the one that matters:
 *
 *   {"type":"error.list","errors":[{"code":"token_unauthorized","message":"…"}]}
 *
 * So `call()` digs that code out and everything downstream switches on it.
 *
 * Credentials are per-channel, not per-deployment: each NotificationChannel
 * stores its own access token, because an Intercom token is scoped to one
 * workspace and orgs bring their own. services.intercom.token is only a
 * fallback for the app-wide `intercom` notification driver.
 */
class IntercomClient
{
    /**
     * Intercom serves EU and AU workspaces from separate domains and a token
     * issued in one region simply 401s against the others — which reads exactly
     * like a bad token, so region has to be carried explicitly rather than guessed.
     */
    private const BASE_URLS = [
        'us' => 'https://api.intercom.io',
        'eu' => 'https://api.eu.intercom.io',
        'au' => 'https://api.au.intercom.io',
    ];

    /**
     * Pinning the version keeps Intercom from silently moving the payload shape
     * under us; 2.11 is the version whose /messages contract this client targets.
     */
    private const API_VERSION = '2.11';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $region = 'us',
    ) {}

    public static function make(?string $accessToken = null, ?string $region = null): self
    {
        if ($accessToken === null || $accessToken === '') {
            $configured = config('services.intercom.token');
            $accessToken = is_string($configured) ? $configured : '';
        }

        if ($region === null || $region === '') {
            $configured = config('services.intercom.region');
            $region = is_string($configured) && $configured !== '' ? $configured : 'us';
        }

        return new self($accessToken, self::normalizeRegion($region));
    }

    /**
     * Whether the deployment has an app-level token. Mirrors
     * TelegramBotClient::botConfigured() — used to decide whether the `intercom`
     * driver can send for a notifiable that has no channel of its own.
     */
    public static function appTokenConfigured(): bool
    {
        $token = config('services.intercom.token');

        return is_string($token) && $token !== '';
    }

    public static function normalizeRegion(string $region): string
    {
        $region = mb_strtolower(trim($region));

        return isset(self::BASE_URLS[$region]) ? $region : 'us';
    }

    /**
     * @return array<int, string>
     */
    public static function regions(): array
    {
        return array_keys(self::BASE_URLS);
    }

    public static function labelForRegion(string $region): string
    {
        return match (self::normalizeRegion($region)) {
            'eu' => __('Europe (api.eu.intercom.io)'),
            'au' => __('Australia (api.au.intercom.io)'),
            default => __('United States (api.intercom.io)'),
        };
    }

    /**
     * Create an admin-initiated message.
     *
     * @param  array<string, mixed>  $payload  Built by IntercomMessage::toArray()
     * @return array{ok: bool, error: string, status: int, body: array<string, mixed>}
     */
    public function postMessage(array $payload): array
    {
        return $this->call('POST', '/messages', $payload);
    }

    /**
     * Fetch the admins on the workspace. Used to validate that the configured
     * `from` admin id actually exists before a channel is saved — a wrong admin
     * id otherwise fails only at delivery time, silently.
     *
     * @return array{ok: bool, error: string, admins: array<int, array{id: string, name: string, email: string}>}
     */
    public function admins(): array
    {
        $result = $this->call('GET', '/admins');

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'admins' => []];
        }

        $rows = $result['body']['admins'] ?? [];
        $admins = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $admins[] = [
                    'id' => (string) $row['id'],
                    'name' => (string) ($row['name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                ];
            }
        }

        return ['ok' => true, 'error' => '', 'admins' => $admins];
    }

    /**
     * Turn a normalised error code into copy an operator can act on. Every
     * client in this namespace carries one of these; the arms are chosen for
     * "what do I change to fix this", not for fidelity to Intercom's wording.
     */
    public static function describeError(string $error): string
    {
        return match ($error) {
            '' => __('Intercom rejected the request.'),
            'not_configured' => __('No Intercom access token is set for this channel.'),
            'token_not_found', 'token_unauthorized', 'missing_authorization' => __('Intercom rejected the access token. Check it was copied in full, and that it was issued for the selected region.'),
            'token_revoked', 'token_expired' => __('That Intercom access token is no longer valid. Re-issue it in the Developer Hub under Configure → Authentication.'),
            'token_blocked' => __('Intercom has blocked this access token or app. Contact Intercom support.'),
            'action_forbidden' => __('The Intercom access token is missing the "Write conversations" permission. Add it in the Developer Hub under Configure → Authentication, then re-issue the token.'),
            'admin_not_found' => __('That admin ID does not exist on the Intercom workspace. Copy it from Settings → Teammates on the same workspace the token belongs to.'),
            // Intercom returns a bare 404 (no error code) when the recipient
            // cannot be resolved, so this arm is keyed on the status fallback
            // that extractErrorCode() produces.
            'http_404' => __('Intercom could not find the recipient. Check the ID or e-mail address.'),
            'parameter_not_found', 'parameter_invalid', 'type_mismatch' => __('Intercom rejected the message parameters. Check the recipient type and, for e-mail messages, that a subject is set.'),
            'rate_limit_exceeded', 'retry_after' => __('Intercom is rate limiting us. Try again shortly.'),
            'api_plan_restricted' => __('The Intercom plan on that workspace does not include the messages API.'),
            default => __('Intercom returned :error.', ['error' => $error]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error: string, status: int, body: array<string, mixed>}
     */
    private function call(string $method, string $path, array $payload = []): array
    {
        if ($this->accessToken === '') {
            return ['ok' => false, 'error' => 'not_configured', 'status' => 0, 'body' => []];
        }

        $url = (self::BASE_URLS[$this->region] ?? self::BASE_URLS['us']).$path;

        try {
            $request = Http::timeout(10)
                ->withToken($this->accessToken)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Intercom-Version' => self::API_VERSION,
                ])
                ->asJson();

            $response = $method === 'GET'
                ? $request->get($url)
                : $request->post($url, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 0, 'body' => []];
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => self::extractErrorCode($body, $response->status()),
                'status' => $response->status(),
                'body' => $body,
            ];
        }

        return ['ok' => true, 'error' => '', 'status' => $response->status(), 'body' => $body];
    }

    /**
     * Intercom nests the useful code at errors.0.code. Fall back to the message,
     * then to the status, so a body we don't recognise still yields something
     * greppable rather than an empty string.
     *
     * @param  array<string, mixed>  $body
     */
    private static function extractErrorCode(array $body, int $status): string
    {
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            $code = $errors[0]['code'] ?? null;
            if (is_string($code) && $code !== '') {
                return $code;
            }

            $message = $errors[0]['message'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'http_'.$status;
    }
}
