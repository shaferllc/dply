<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\DiscordInstallation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Discord API for bot-backed notification channels.
 *
 * Counterpart to {@see SlackWorkspaceClient}, but the failure modes are the
 * opposite shape and worth stating plainly:
 *
 * - Discord uses **honest HTTP status codes** — a rejected call is a 4xx with
 *   `{"message": "...", "code": 50001}`. No Slack-style 200-with-ok:false.
 * - There is **one bot token for the whole application**, resolved via
 *   {@see PlatformNotificationApps} (admin Connections overlay, else env),
 *   not from the install row, so every guild shares it.
 * - Guild-level permission does not imply channel-level permission. A channel
 *   with a permission overwrite denies the bot even though the install looks
 *   healthy, and that surfaces only as a 403 on send (code 50001).
 */
class DiscordGuildClient
{
    private const BASE = 'https://discord.com/api/v10/';

    /** Channel lists back a dropdown, not a source of truth — minutes-stale is fine. */
    private const CHANNEL_CACHE_SECONDS = 300;

    /** GUILD_TEXT and GUILD_ANNOUNCEMENT are the only types a plain content message can go to. */
    private const POSTABLE_TYPES = [0, 5];

    public function __construct(private readonly string $botToken) {}

    public static function make(): self
    {
        return new self(PlatformNotificationApps::discord()['bot_token']);
    }

    /** Whether this deployment has a bot token at all — without one the picker cannot work. */
    public static function botConfigured(): bool
    {
        return PlatformNotificationApps::discord()['bot_token'] !== '';
    }

    /**
     * @return list<array{id: string, name: string, is_announcement: bool}>
     */
    public static function channelsFor(DiscordInstallation $installation, bool $fresh = false): array
    {
        $key = 'discord:channels:'.$installation->id;

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::remember($key, self::CHANNEL_CACHE_SECONDS, static function () use ($installation): array {
            return self::make()->channels($installation->guild_id);
        });

        return $cached;
    }

    public static function forgetChannelCache(DiscordInstallation $installation): void
    {
        Cache::forget('discord:channels:'.$installation->id);
    }

    /**
     * Text channels in a guild, ordered the way Discord shows them.
     *
     * @return list<array{id: string, name: string, is_announcement: bool}>
     */
    public function channels(string $guildId): array
    {
        $result = $this->call('get', 'guilds/'.$guildId.'/channels');
        if (! $result['ok'] || ! is_array($result['body'])) {
            return [];
        }

        $channels = [];
        foreach ($result['body'] as $row) {
            if (! is_array($row) || ! in_array($row['type'] ?? null, self::POSTABLE_TYPES, true)) {
                continue;
            }

            $id = $row['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }

            $channels[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? $id),
                'is_announcement' => ($row['type']) === 5,
                'position' => (int) ($row['position'] ?? 0),
            ];
        }

        usort($channels, static fn (array $a, array $b): int => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);

        // `position` was only needed for the sort; it has no meaning downstream.
        return array_map(
            static fn (array $c): array => ['id' => $c['id'], 'name' => $c['name'], 'is_announcement' => $c['is_announcement']],
            $channels,
        );
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function postMessage(string $channelId, string $content): array
    {
        $result = $this->call('post', 'channels/'.$channelId.'/messages', [
            // Discord hard-rejects anything over 2000 characters rather than
            // truncating, so an over-long alert would be dropped entirely.
            'content' => mb_substr($content, 0, 2000),
        ]);

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /**
     * Discord error codes are numeric and opaque; map the ones an operator can
     * actually act on. `50001` in particular almost always means a channel-level
     * permission overwrite, not a broken install — the fix is in Discord, not here.
     */
    public static function describeError(string $error): string
    {
        return match ($error) {
            '' => __('Discord rejected the request.'),
            '10003' => __('That channel no longer exists.'),
            '10004' => __('dply is no longer in that Discord server. Reconnect it.'),
            '50001' => __('dply cannot see that channel. Check the channel\'s permissions for the dply role in Discord.'),
            '50013' => __('dply lacks permission to post in that channel. Grant it Send Messages in Discord.'),
            '40005', '50035' => __('Discord rejected the message contents.'),
            'unauthorized' => __('The Discord bot token on this deployment is invalid.'),
            'rate_limited' => __('Discord is rate limiting us. Try again shortly.'),
            'not_configured' => __('No Discord bot token is configured on this deployment.'),
            default => __('Discord returned :error.', ['error' => $error]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error: string, body: mixed}
     */
    private function call(string $method, string $path, array $payload = []): array
    {
        if ($this->botToken === '') {
            return ['ok' => false, 'error' => 'not_configured', 'body' => null];
        }

        try {
            // "Bot " prefix, not Bearer — a bot token sent as Bearer is a 401.
            $request = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bot '.$this->botToken,
            ])->acceptJson();

            $response = $method === 'post'
                ? $request->asJson()->post(self::BASE.$path, $payload)
                : $request->get(self::BASE.$path);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'body' => null];
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => '', 'body' => $response->json()];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'error' => 'unauthorized', 'body' => null];
        }

        if ($response->status() === 429) {
            return ['ok' => false, 'error' => 'rate_limited', 'body' => null];
        }

        $code = $response->json('code');

        return [
            'ok' => false,
            'error' => is_int($code) || is_string($code) ? (string) $code : 'http_'.$response->status(),
            'body' => null,
        ];
    }
}
