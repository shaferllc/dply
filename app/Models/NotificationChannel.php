<?php

namespace App\Models;

use App\Jobs\SendNotificationChannelTestEmailJob;
use App\Mail\NotificationChannelMail;
use App\Modules\Notifications\Services\DiscordGuildClient;
use App\Modules\Notifications\Services\SlackWorkspaceClient;
use App\Modules\Notifications\Services\TelegramBotClient;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * @property string $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $type
 * @property string $label
 * @property array<string, mixed> $config
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Model $owner
 * @property-read Collection<int, NotificationSubscription> $subscriptions
 */
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory, HasUlids;

    public const TYPE_SLACK = 'slack';

    /**
     * Slack channels come in two shapes. `oauth` rides a {@see SlackInstallation}
     * bot token and posts by channel id; anything else is the original pasted
     * incoming-webhook URL. Stored in `config['auth']` — absent means webhook, so
     * rows written before "Add to Slack" existed need no backfill.
     */
    public const SLACK_AUTH_OAUTH = 'oauth';

    public const TYPE_DISCORD = 'discord';

    /**
     * Discord's twin of {@see SLACK_AUTH_OAUTH}: `oauth` posts through the dply
     * bot in a connected guild, anything else is a pasted webhook URL. Absent
     * means webhook, so pre-existing rows need no backfill.
     */
    public const DISCORD_AUTH_OAUTH = 'oauth';

    public const TYPE_EMAIL = 'email';

    public const TYPE_TELEGRAM = 'telegram';

    /**
     * Telegram's twin of {@see SLACK_AUTH_OAUTH}: `connected` posts through the
     * deployment bot into a chat discovered via the /start claim check; anything
     * else is a hand-pasted bot token + chat ID. Absent means manual.
     */
    public const TELEGRAM_AUTH_CONNECTED = 'connected';

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<NotificationSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function sendTest(?User $actor = null): array
    {
        $actorLabel = $actor !== null ? $actor->name : config('app.name');

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
        $text = __(':app test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        if ($this->usesSlackOauth()) {
            return $this->postSlackViaBotToken($text);
        }

        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Slack webhook URL is missing.')];
        }

        $payload = ['text' => $text];

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

    /** Whether this Slack channel posts through a connected workspace's bot token. */
    public function usesSlackOauth(): bool
    {
        return $this->type === self::TYPE_SLACK
            && ($this->config['auth'] ?? null) === self::SLACK_AUTH_OAUTH;
    }

    /** The connected workspace behind an OAuth Slack channel, if it still exists. */
    public function slackInstallation(): ?SlackInstallation
    {
        $id = $this->config['installation_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return SlackInstallation::query()->find($id);
    }

    /**
     * Post through the workspace bot token.
     *
     * The disconnected-workspace case gets its own message on purpose: it is the
     * one failure an operator can fix themselves, and "Slack returned an error"
     * would send them hunting through Slack's admin UI instead of clicking
     * reconnect.
     *
     * @return array{ok: bool, message: string}
     */
    protected function postSlackViaBotToken(string $text): array
    {
        $installation = $this->slackInstallation();
        if (! $installation instanceof SlackInstallation) {
            return ['ok' => false, 'message' => __('The Slack workspace for this channel was disconnected. Reconnect it in Notifications settings.')];
        }

        $channelId = $this->config['channel_id'] ?? null;
        if (! is_string($channelId) || $channelId === '') {
            return ['ok' => false, 'message' => __('No Slack channel is selected.')];
        }

        $result = SlackWorkspaceClient::for($installation)->postMessage($channelId, $text);

        return $result['ok']
            ? ['ok' => true, 'message' => __('Test message sent.')]
            : ['ok' => false, 'message' => SlackWorkspaceClient::describeError($result['error'])];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendDiscordTest(string $actorLabel): array
    {
        $text = __('[:app] Test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        if ($this->usesDiscordOauth()) {
            return $this->postDiscordViaBot($text);
        }

        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Discord webhook URL is missing.')];
        }

        $body = ['content' => $text];

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

    /** Whether this Discord channel posts through the dply bot in a connected guild. */
    public function usesDiscordOauth(): bool
    {
        return $this->type === self::TYPE_DISCORD
            && ($this->config['auth'] ?? null) === self::DISCORD_AUTH_OAUTH;
    }

    /** The connected guild behind an OAuth Discord channel, if it still exists. */
    public function discordInstallation(): ?DiscordInstallation
    {
        $id = $this->config['installation_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return DiscordInstallation::query()->find($id);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function postDiscordViaBot(string $text): array
    {
        if (! $this->discordInstallation() instanceof DiscordInstallation) {
            return ['ok' => false, 'message' => __('The Discord server for this channel was disconnected. Reconnect it in Notifications settings.')];
        }

        $channelId = $this->config['channel_id'] ?? null;
        if (! is_string($channelId) || $channelId === '') {
            return ['ok' => false, 'message' => __('No Discord channel is selected.')];
        }

        $result = DiscordGuildClient::make()->postMessage($channelId, $text);

        return $result['ok']
            ? ['ok' => true, 'message' => __('Test message sent.')]
            : ['ok' => false, 'message' => DiscordGuildClient::describeError($result['error'])];
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

        // Dispatch rather than Mail::to(...)->queue(): the facade resolves the
        // default mailer eagerly (building its transport) even when only queueing,
        // so a misconfigured mailer would crash this web request. The job defers
        // all mailer resolution to the worker. See SendNotificationChannelTestEmailJob.
        try {
            SendNotificationChannelTestEmailJob::dispatch($to, (string) $this->label, $actorLabel);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => __('Test email queued — it will arrive shortly.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendTelegramTest(string $actorLabel): array
    {
        $text = __('[:app] Test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        if ($this->usesTelegramConnected()) {
            return $this->postTelegramViaBot($text);
        }

        $token = $this->config['bot_token'] ?? null;
        $chatId = $this->config['chat_id'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            return ['ok' => false, 'message' => __('Telegram bot token and chat ID are required.')];
        }

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

    /** Whether this Telegram channel posts through the deployment bot. */
    public function usesTelegramConnected(): bool
    {
        return $this->type === self::TYPE_TELEGRAM
            && ($this->config['auth'] ?? null) === self::TELEGRAM_AUTH_CONNECTED;
    }

    /** The connected chat behind a bot-backed Telegram channel, if it still exists. */
    public function telegramInstallation(): ?TelegramInstallation
    {
        $id = $this->config['installation_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return TelegramInstallation::query()->find($id);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function postTelegramViaBot(string $text): array
    {
        $installation = $this->telegramInstallation();
        if (! $installation instanceof TelegramInstallation) {
            return ['ok' => false, 'message' => __('The Telegram chat for this channel was disconnected. Reconnect it in Notifications settings.')];
        }

        $result = TelegramBotClient::make()->sendMessage($installation->chat_id, $text);

        return $result['ok']
            ? ['ok' => true, 'message' => __('Test message sent.')]
            : ['ok' => false, 'message' => TelegramBotClient::describeError($result['error'])];
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

    /**
     * Deliver a short operational alert (insights, health, etc.) to this channel.
     */
    public function sendOperationalMessage(string $subject, string $text, ?string $actionUrl = null, ?string $actionLabel = null): void
    {
        $lines = array_filter([$subject, $text, $actionUrl && $actionLabel ? $actionLabel.': '.$actionUrl : $actionUrl]);
        $full = implode("\n\n", $lines);

        try {
            match ($this->type) {
                self::TYPE_SLACK => $this->deliverSlackPlain($full),
                self::TYPE_DISCORD => $this->deliverDiscordPlain($full),
                self::TYPE_EMAIL => $this->deliverEmail($subject, $text, $actionUrl, $actionLabel),
                self::TYPE_TELEGRAM => $this->deliverTelegramPlain($full),
                self::TYPE_PUSHOVER => $this->deliverPushoverPlain($subject, $full),
                self::TYPE_MICROSOFT_TEAMS => $this->deliverTeamsPlain($subject, $full),
                self::TYPE_ROCKETCHAT => $this->deliverRocketchatPlain($full),
                self::TYPE_GOOGLE_CHAT => $this->deliverGoogleChatPlain($full),
                self::TYPE_WEBHOOK => $this->deliverWebhookInsight($subject, $text, $actionUrl),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('notification_channel.operational_failed', [
                'channel_id' => $this->id,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function deliverSlackPlain(string $text): void
    {
        if ($this->usesSlackOauth()) {
            $result = $this->postSlackViaBotToken($text);
            if (! $result['ok']) {
                Log::warning('notification_channel.slack_post_failed', [
                    'channel_id' => $this->id,
                    'message' => $result['message'],
                ]);
            }

            return;
        }

        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        $payload = ['text' => $text];
        $channel = $this->config['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            $payload['channel'] = $channel;
        }

        Http::timeout(10)->post($url, $payload);
    }

    protected function deliverDiscordPlain(string $text): void
    {
        if ($this->usesDiscordOauth()) {
            $result = $this->postDiscordViaBot($text);
            if (! $result['ok']) {
                Log::warning('notification_channel.discord_post_failed', [
                    'channel_id' => $this->id,
                    'message' => $result['message'],
                ]);
            }

            return;
        }

        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        Http::timeout(10)->asJson()->post($url, ['content' => mb_substr($text, 0, 1900)]);
    }

    protected function deliverEmail(string $subject, string $body, ?string $actionUrl = null, ?string $actionLabel = null): void
    {
        $to = $this->config['email'] ?? null;
        if (! is_string($to) || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // The body arrives as newline-separated lines (e.g. "Server: x\nAccount: y");
        // render each as its own paragraph in the branded template.
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", $body),
        ), static fn (string $line): bool => $line !== ''));

        Mail::to($to)->queue(new NotificationChannelMail(
            heading: $subject,
            bodyLines: $lines,
            actionUrl: $actionUrl,
            actionLabel: $actionLabel,
            subjectLine: $subject,
        ));
    }

    protected function deliverTelegramPlain(string $text): void
    {
        if ($this->usesTelegramConnected()) {
            $result = $this->postTelegramViaBot($text);
            if (! $result['ok']) {
                Log::warning('notification_channel.telegram_post_failed', [
                    'channel_id' => $this->id,
                    'message' => $result['message'],
                ]);
            }

            return;
        }

        $token = $this->config['bot_token'] ?? null;
        $chatId = $this->config['chat_id'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            return;
        }

        $url = 'https://api.telegram.org/bot'.rawurlencode($token).'/sendMessage';
        Http::timeout(10)->asForm()->post($url, [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4000),
        ]);
    }

    protected function deliverPushoverPlain(string $title, string $message): void
    {
        $appToken = $this->config['app_token'] ?? null;
        $userKey = $this->config['user_key'] ?? null;
        if (! is_string($appToken) || $appToken === '' || ! is_string($userKey) || $userKey === '') {
            return;
        }

        Http::timeout(10)->asForm()->post('https://api.pushover.net/1/messages.json', [
            'token' => $appToken,
            'user' => $userKey,
            'title' => mb_substr($title, 0, 250),
            'message' => mb_substr($message, 0, 1000),
        ]);
    }

    protected function deliverTeamsPlain(string $title, string $text): void
    {
        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        Http::timeout(10)->asJson()->post($url, [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => $title,
            'title' => $title,
            'text' => $text,
        ]);
    }

    protected function deliverRocketchatPlain(string $text): void
    {
        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        Http::timeout(10)->asJson()->post($url, ['text' => $text]);
    }

    protected function deliverGoogleChatPlain(string $text): void
    {
        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        Http::timeout(10)->asJson()->post($url, ['text' => $text]);
    }

    protected function deliverWebhookInsight(string $subject, string $text, ?string $actionUrl): void
    {
        $url = $this->config['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        Http::timeout(10)
            ->withHeaders(is_array($this->config['headers'] ?? null) ? $this->config['headers'] : [])
            ->asJson()
            ->post($url, [
                'event' => 'server.insights_alerts',
                'subject' => $subject,
                'text' => $text,
                'action_url' => $actionUrl,
                'sent_at' => now()->toIso8601String(),
            ]);
    }
}
