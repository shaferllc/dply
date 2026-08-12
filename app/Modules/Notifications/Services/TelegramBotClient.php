<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Telegram Bot API for connected-chat notification
 * channels.
 *
 * Telegram's failure shape is a third variant again: HTTP status codes are
 * mostly honest, but the body always carries `ok` plus a `description` string
 * that is the only human-readable signal — there are no stable numeric error
 * codes to switch on the way Discord has. So error mapping here matches on
 * description substrings, which is unlovely but is what the API offers.
 *
 * One bot token per deployment, from @BotFather, exactly like Discord.
 */
class TelegramBotClient
{
    public function __construct(private readonly string $botToken) {}

    public static function make(): self
    {
        $token = config('services.telegram.bot_token');

        return new self(is_string($token) ? $token : '');
    }

    public static function botConfigured(): bool
    {
        $token = config('services.telegram.bot_token');

        return is_string($token) && $token !== '';
    }

    /**
     * The bot's @username, needed to build t.me deep links. Cached by the caller
     * rather than here — it changes only when the operator renames the bot.
     *
     * @return array{ok: bool, username: string, error: string}
     */
    public function me(): array
    {
        $result = $this->call('getMe');

        return [
            'ok' => $result['ok'],
            'username' => (string) ($result['body']['username'] ?? ''),
            'error' => $result['error'],
        ];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function sendMessage(string $chatId, string $text): array
    {
        $result = $this->call('sendMessage', [
            'chat_id' => $chatId,
            // Telegram rejects messages over 4096 characters outright.
            'text' => mb_substr($text, 0, 4096),
            'disable_web_page_preview' => true,
        ]);

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /**
     * Point Telegram at our webhook. `secret_token` makes Telegram send an
     * X-Telegram-Bot-Api-Secret-Token header on every delivery, which is the
     * only thing standing between the public endpoint and forged updates.
     *
     * @return array{ok: bool, error: string}
     */
    public function setWebhook(string $url, string $secret): array
    {
        $result = $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            // Only what the connect flow needs. Narrowing this keeps dply from
            // receiving (and having to discard) every message in every group the
            // bot sits in — which would be a needless privacy exposure.
            'allowed_updates' => json_encode(['message']),
            'drop_pending_updates' => true,
        ]);

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function deleteWebhook(): array
    {
        $result = $this->call('deleteWebhook', ['drop_pending_updates' => true]);

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /**
     * @return array{ok: bool, url: string, pending: int, last_error: string, error: string}
     */
    public function webhookInfo(): array
    {
        $result = $this->call('getWebhookInfo');

        return [
            'ok' => $result['ok'],
            'url' => (string) ($result['body']['url'] ?? ''),
            'pending' => (int) ($result['body']['pending_update_count'] ?? 0),
            'last_error' => (string) ($result['body']['last_error_message'] ?? ''),
            'error' => $result['error'],
        ];
    }

    public static function describeError(string $error): string
    {
        $lower = mb_strtolower($error);

        return match (true) {
            $error === '' => __('Telegram rejected the request.'),
            $error === 'not_configured' => __('No Telegram bot token is configured on this deployment.'),
            str_contains($lower, 'chat not found') => __('That chat no longer exists, or the dply bot was removed from it.'),
            str_contains($lower, 'bot was kicked'), str_contains($lower, 'bot is not a member') => __('The dply bot was removed from that chat. Add it back and reconnect.'),
            str_contains($lower, 'not enough rights'), str_contains($lower, 'need administrator') => __('The dply bot needs to be an administrator of that channel to post.'),
            str_contains($lower, 'blocked') => __('That user blocked the dply bot.'),
            str_contains($lower, 'too many requests') => __('Telegram is rate limiting us. Try again shortly.'),
            str_contains($lower, 'unauthorized') => __('The Telegram bot token on this deployment is invalid.'),
            default => __('Telegram returned :error.', ['error' => $error]),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, error: string, body: array<string, mixed>}
     */
    private function call(string $method, array $params = []): array
    {
        if ($this->botToken === '') {
            return ['ok' => false, 'error' => 'not_configured', 'body' => []];
        }

        // Escape the token for use in a path segment, but put the colon back:
        // Telegram's own URL format is literally `bot<digits>:<secret>`, and a
        // %3A there risks a 404 that reads exactly like a bad token, which is a
        // miserable thing to debug. Everything else stays encoded so a malformed
        // token can't inject path segments.
        $url = 'https://api.telegram.org/bot'.str_replace('%3A', ':', rawurlencode($this->botToken)).'/'.$method;

        try {
            $response = Http::timeout(10)->asForm()->post($url, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'body' => []];
        }

        $body = $response->json();
        if (! is_array($body)) {
            return ['ok' => false, 'error' => 'http_'.$response->status(), 'body' => []];
        }

        if (empty($body['ok'])) {
            return [
                'ok' => false,
                // `description` is the only human-usable error signal Telegram gives.
                'error' => (string) ($body['description'] ?? 'http_'.$response->status()),
                'body' => [],
            ];
        }

        $result = $body['result'] ?? [];

        return ['ok' => true, 'error' => '', 'body' => is_array($result) ? $result : []];
    }
}
