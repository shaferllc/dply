<?php

namespace App\Models;

use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory, HasUlids;

    public const TYPE_SLACK = 'slack';

    public const TYPE_DISCORD = 'discord';

    public const TYPE_EMAIL = 'email';

    public const TYPE_TELEGRAM = 'telegram';

    public const TYPE_PUSHOVER = 'pushover';

    public const TYPE_MICROSOFT_TEAMS = 'microsoft_teams';

    public const TYPE_ROCKETCHAT = 'rocketchat';

    public const TYPE_GOOGLE_CHAT = 'google_chat';

    public const TYPE_MOBILE_APP = 'mobile_app';

    public const TYPE_WEBHOOK = 'webhook';

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::TYPE_SLACK,
            self::TYPE_DISCORD,
            self::TYPE_EMAIL,
            self::TYPE_TELEGRAM,
            self::TYPE_PUSHOVER,
            self::TYPE_MICROSOFT_TEAMS,
            self::TYPE_ROCKETCHAT,
            self::TYPE_GOOGLE_CHAT,
            self::TYPE_MOBILE_APP,
            self::TYPE_WEBHOOK,
        ];
    }

    /**
     * Types shown in UI dropdowns (subset of {@see types()} controlled by config).
     * Pass $preserveType so an existing channel keeps a type visible even if newly disabled.
     *
     * @return list<string>
     */
    public static function typesForUi(?string $preserveType = null): array
    {
        $configured = config('notification_channels.enabled_types', []);
        if (! is_array($configured)) {
            $configured = [];
        }

        $allowed = array_values(array_intersect(self::types(), $configured));

        if ($preserveType !== null
            && $preserveType !== ''
            && in_array($preserveType, self::types(), true)
            && ! in_array($preserveType, $allowed, true)) {
            $allowed[] = $preserveType;
        }

        usort($allowed, function (string $a, string $b): int {
            $order = array_flip(self::types());

            return ($order[$a] ?? 0) <=> ($order[$b] ?? 0);
        });

        return $allowed;
    }

    public static function labelForType(string $type): string
    {
        return match ($type) {
            self::TYPE_SLACK => 'Slack',
            self::TYPE_DISCORD => 'Discord',
            self::TYPE_EMAIL => __('E-mail address'),
            self::TYPE_TELEGRAM => 'Telegram',
            self::TYPE_PUSHOVER => 'Pushover',
            self::TYPE_MICROSOFT_TEAMS => __('Microsoft Teams'),
            self::TYPE_ROCKETCHAT => 'Rocket.Chat',
            self::TYPE_GOOGLE_CHAT => 'Google Chat',
            self::TYPE_MOBILE_APP => __('Mobile app'),
            self::TYPE_WEBHOOK => __('HTTP webhook'),
            default => $type,
        };
    }

    protected $fillable = [
        'owner_type',
        'owner_id',
        'type',
        'label',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function sendTest(?User $actor = null): array
    {
        $actorLabel = $actor?->name ?? config('app.name');

        return match ($this->type) {
            self::TYPE_SLACK => $this->sendSlackTest($actorLabel),
            self::TYPE_DISCORD => $this->sendDiscordTest($actorLabel),
            self::TYPE_EMAIL => $this->sendEmailTest($actorLabel),
            self::TYPE_TELEGRAM => $this->sendTelegramTest($actorLabel),
            self::TYPE_PUSHOVER => $this->sendPushoverTest($actorLabel),
            self::TYPE_MICROSOFT_TEAMS => $this->sendMicrosoftTeamsTest($actorLabel),
            self::TYPE_ROCKETCHAT => $this->sendRocketchatTest($actorLabel),
            self::TYPE_GOOGLE_CHAT => $this->sendGoogleChatTest($actorLabel),
            self::TYPE_MOBILE_APP => $this->sendMobileAppTest($actorLabel),
            self::TYPE_WEBHOOK => $this->sendWebhookTest($actorLabel),
            default => ['ok' => false, 'message' => __('Unknown channel type.')],
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendSlackTest(string $actorLabel): array
    {
        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Slack webhook URL is missing.')];
        }

        $payload = [
            'text' => __(':app test notification (:label) from :actor', [
                'app' => config('app.name'),
                'label' => $this->label,
                'actor' => $actorLabel,
            ]),
        ];

        $channel = $this->config['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            $payload['channel'] = $channel;
        }

        try {
            $response = Http::timeout(10)->post($url, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Slack returned :status.', ['status' => $response->status()])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendDiscordTest(string $actorLabel): array
    {
        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Discord webhook URL is missing.')];
        }

        $body = [
            'content' => __('[:app] Test notification (:label) from :actor', [
                'app' => config('app.name'),
                'label' => $this->label,
                'actor' => $actorLabel,
            ]),
        ];

        try {
            $response = Http::timeout(10)->asJson()->post($url, $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Discord returned :status.', ['status' => $response->status()])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendEmailTest(string $actorLabel): array
    {
        $to = $this->config['email'] ?? null;
        if (! is_string($to) || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => __('Valid email address is required.')];
        }

        $body = __('[:app] Test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        try {
            Mail::raw($body, function ($message) use ($to): void {
                $message->to($to)->subject(__('[:app] Notification channel test', ['app' => config('app.name')]));
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => __('Test email sent.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendTelegramTest(string $actorLabel): array
    {
        $token = $this->config['bot_token'] ?? null;
        $chatId = $this->config['chat_id'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            return ['ok' => false, 'message' => __('Telegram bot token and chat ID are required.')];
        }

        $text = __('[:app] Test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        $url = 'https://api.telegram.org/bot'.rawurlencode($token).'/sendMessage';

        try {
            $response = Http::timeout(10)->asForm()->post($url, [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Telegram API returned :status.', ['status' => $response->status()])];
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['ok'])) {
            return ['ok' => false, 'message' => __('Telegram rejected the request.')];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendPushoverTest(string $actorLabel): array
    {
        $appToken = $this->config['app_token'] ?? null;
        $userKey = $this->config['user_key'] ?? null;
        if (! is_string($appToken) || $appToken === '' || ! is_string($userKey) || $userKey === '') {
            return ['ok' => false, 'message' => __('Pushover application token and user key are required.')];
        }

        $message = __('[:app] Test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        try {
            $response = Http::timeout(10)->asForm()->post('https://api.pushover.net/1/messages.json', [
                'token' => $appToken,
                'user' => $userKey,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Pushover returned :status.', ['status' => $response->status()])];
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['status'])) {
            return ['ok' => false, 'message' => __('Pushover rejected the request.')];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendMicrosoftTeamsTest(string $actorLabel): array
    {
        return $this->sendJsonTextWebhookTest(
            $this->config['webhook_url'] ?? null,
            __('[:app] Test notification (:label) from :actor', [
                'app' => config('app.name'),
                'label' => $this->label,
                'actor' => $actorLabel,
            ]),
            __('Microsoft Teams webhook URL is missing.'),
            'Microsoft Teams'
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendRocketchatTest(string $actorLabel): array
    {
        return $this->sendJsonTextWebhookTest(
            $this->config['webhook_url'] ?? null,
            __('[:app] Test notification (:label) from :actor', [
                'app' => config('app.name'),
                'label' => $this->label,
                'actor' => $actorLabel,
            ]),
            __('Rocket.Chat webhook URL is missing.'),
            'Rocket.Chat'
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendGoogleChatTest(string $actorLabel): array
    {
        return $this->sendJsonTextWebhookTest(
            $this->config['webhook_url'] ?? null,
            __('[:app] Test notification (:label) from :actor', [
                'app' => config('app.name'),
                'label' => $this->label,
                'actor' => $actorLabel,
            ]),
            __('Google Chat webhook URL is missing.'),
            'Google Chat'
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendJsonTextWebhookTest(?string $url, string $text, string $missingUrlMessage, string $providerLabel): array
    {
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => $missingUrlMessage];
        }

        try {
            $response = Http::timeout(10)->asJson()->post($url, ['text' => $text]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __(':provider returned :status.', [
                'provider' => $providerLabel,
                'status' => $response->status(),
            ])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendMobileAppTest(string $actorLabel): array
    {
        $token = $this->config['device_token'] ?? null;
        $platform = $this->config['platform'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($platform) || ! in_array($platform, ['ios', 'android'], true)) {
            return ['ok' => false, 'message' => __('Device token and platform (iOS or Android) are required.')];
        }

        return [
            'ok' => true,
            'message' => __('Device registered. Push delivery is available when the dply mobile app uses this token.'),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendWebhookTest(string $actorLabel): array
    {
        $url = $this->config['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Webhook URL is missing.')];
        }

        $payload = [
            'event' => 'notification_channel.test',
            'label' => $this->label,
            'app' => config('app.name'),
            'actor' => $actorLabel,
            'sent_at' => now()->toIso8601String(),
        ];

        try {
            $response = Http::timeout(10)
                ->withHeaders(is_array($this->config['headers'] ?? null) ? $this->config['headers'] : [])
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Endpoint returned :status.', ['status' => $response->status()])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }
}
