<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\SlackInstallation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Slack Web API for bot-token notification channels.
 *
 * The one rule that governs every method here: **Slack answers 200 OK on
 * failure.** A rejected call still returns HTTP 200 with `{"ok": false, "error":
 * "channel_not_found"}` in the body, so `$response->successful()` is meaningless
 * on its own and every call has to read `ok` out of the JSON. Getting this wrong
 * is how a channel silently stops delivering while the UI reports success.
 *
 * Scope notes:
 * - `chat:write.public` is what lets dply post to a *public* channel it was never
 *   invited to. Without it the first message to each channel fails
 *   `not_in_channel` and someone has to `/invite @dply` everywhere.
 * - Private channels (`groups:read`) are listed but still require a manual invite;
 *   Slack has no scope that grants uninvited access to a private conversation.
 */
class SlackWorkspaceClient
{
    private const BASE = 'https://slack.com/api/';

    /** Conversation lists are for a dropdown, not a source of truth — a few minutes stale is fine and saves a round trip per keystroke-free render. */
    private const CHANNEL_CACHE_SECONDS = 300;

    /** Slack pages at 200; stop after this many pages so a 50k-channel workspace can't stall a web request. */
    private const MAX_PAGES = 10;

    public function __construct(private readonly string $botToken) {}

    public static function for(SlackInstallation $installation): self
    {
        return new self($installation->botToken());
    }

    /**
     * Channels the bot may post to, newest cache first.
     *
     * @return list<array{id: string, name: string, is_private: bool, is_member: bool}>
     */
    public static function channelsFor(SlackInstallation $installation, bool $fresh = false): array
    {
        $key = 'slack:channels:'.$installation->id;

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::remember($key, self::CHANNEL_CACHE_SECONDS, static function () use ($installation): array {
            return self::for($installation)->conversations();
        });

        return is_array($cached) ? $cached : [];
    }

    public static function forgetChannelCache(SlackInstallation $installation): void
    {
        Cache::forget('slack:channels:'.$installation->id);
    }

    /**
     * @return list<array{id: string, name: string, is_private: bool, is_member: bool}>
     */
    public function conversations(): array
    {
        $channels = [];
        $cursor = '';

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->call('conversations.list', [
                'types' => 'public_channel,private_channel',
                'exclude_archived' => 'true',
                'limit' => 200,
                'cursor' => $cursor,
            ]);

            if (! $result['ok']) {
                // Partial results beat none: a mid-pagination failure still leaves
                // the operator a usable dropdown of what we did read.
                break;
            }

            foreach ((array) ($result['body']['channels'] ?? []) as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                    continue;
                }

                $channels[] = [
                    'id' => $row['id'],
                    'name' => (string) ($row['name'] ?? $row['id']),
                    'is_private' => (bool) ($row['is_private'] ?? false),
                    'is_member' => (bool) ($row['is_member'] ?? false),
                ];
            }

            $cursor = (string) ($result['body']['response_metadata']['next_cursor'] ?? '');
            if ($cursor === '') {
                break;
            }
        }

        usort($channels, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $channels;
    }

    /**
     * Post to a channel by id.
     *
     * @return array{ok: bool, error: string}
     */
    public function postMessage(string $channelId, string $text): array
    {
        $result = $this->call('chat.postMessage', [
            'channel' => $channelId,
            'text' => $text,
            // Slack renders long alerts better unfurled-off; link previews on a
            // deploy URL add noise to an ops channel.
            'unfurl_links' => false,
            'unfurl_media' => false,
        ], asJson: true);

        if ($result['ok']) {
            return ['ok' => true, 'error' => ''];
        }

        $error = $result['error'];

        // `chat:write.public` covers public channels, but an install predating that
        // scope (or a private channel) lands here. One join attempt is cheap and
        // turns a hard failure into a working channel for public conversations.
        if ($error === 'not_in_channel') {
            $joined = $this->call('conversations.join', ['channel' => $channelId], asJson: true);
            if ($joined['ok']) {
                $retry = $this->call('chat.postMessage', [
                    'channel' => $channelId,
                    'text' => $text,
                    'unfurl_links' => false,
                    'unfurl_media' => false,
                ], asJson: true);

                return ['ok' => $retry['ok'], 'error' => $retry['error']];
            }
        }

        return ['ok' => false, 'error' => $error];
    }

    /**
     * Human-readable rendering of Slack's error slugs — "channel_not_found" tells
     * an operator nothing about what to do next.
     */
    public static function describeError(string $error): string
    {
        return match ($error) {
            '' => __('Slack rejected the request.'),
            'channel_not_found' => __('That channel no longer exists, or dply was removed from the workspace.'),
            'not_in_channel' => __('dply is not in that channel. Invite it with /invite @dply, then try again.'),
            'is_archived' => __('That channel is archived.'),
            'invalid_auth', 'token_revoked', 'account_inactive' => __('The Slack connection was revoked. Reconnect the workspace.'),
            'missing_scope' => __('The Slack connection is missing a permission. Reconnect the workspace to grant it.'),
            'rate_limited', 'ratelimited' => __('Slack is rate limiting us. Try again shortly.'),
            'restricted_action' => __('A workspace policy blocked this message.'),
            default => __('Slack returned :error.', ['error' => $error]),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, error: string, body: array<string, mixed>}
     */
    private function call(string $method, array $params, bool $asJson = false): array
    {
        if ($this->botToken === '') {
            return ['ok' => false, 'error' => 'invalid_auth', 'body' => []];
        }

        try {
            $request = Http::timeout(10)->withToken($this->botToken);
            $response = $asJson
                ? $request->asJson()->post(self::BASE.$method, $params)
                : $request->asForm()->post(self::BASE.$method, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'body' => []];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'http_'.$response->status(), 'body' => []];
        }

        $body = $response->json();
        if (! is_array($body)) {
            return ['ok' => false, 'error' => 'invalid_response', 'body' => []];
        }

        // The 200-OK-with-ok:false case this whole class exists to respect.
        if (empty($body['ok'])) {
            return ['ok' => false, 'error' => (string) ($body['error'] ?? ''), 'body' => $body];
        }

        return ['ok' => true, 'error' => '', 'body' => $body];
    }
}
