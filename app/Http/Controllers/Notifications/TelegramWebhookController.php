<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramConnectToken;
use App\Models\TelegramInstallation;
use App\Models\User;
use App\Modules\Notifications\Services\TelegramBotClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives Telegram bot updates and turns a `/start <token>` into a connected
 * chat.
 *
 * This is a **public, unauthenticated endpoint** — Telegram's servers call it,
 * not a browser with a session — so two things carry the whole security weight:
 *
 *  1. The `X-Telegram-Bot-Api-Secret-Token` header, set when the webhook was
 *     registered and compared here in constant time. Without a match nothing is
 *     parsed at all.
 *  2. The connect token itself, which is single-use and expiring. It is a bearer
 *     credential: whoever presents it gets a chat attached to the issuing
 *     account, so consumption happens inside a locking transaction to make a
 *     double-redeem race impossible.
 *
 * It always answers 200. Telegram retries non-2xx deliveries with backoff, and a
 * retry storm over a message we were never going to act on is worse than a
 * silent drop — genuine faults go to the log instead.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->secretMatches($request)) {
            // 403 rather than 200: this is not Telegram, so retry semantics
            // don't apply and there's no reason to look welcoming.
            return response()->json(['ok' => false], 403);
        }

        $payload = $this->startPayload($request);
        if ($payload === null) {
            // Ordinary chatter in a group the bot sits in. Nothing to do.
            return response()->json(['ok' => true]);
        }

        $chat = $request->input('message.chat');
        if (! is_array($chat) || ! isset($chat['id'])) {
            return response()->json(['ok' => true]);
        }

        $installation = $this->redeem($payload, $chat);

        if ($installation instanceof TelegramInstallation) {
            // Confirm in the chat itself. This is the only feedback the operator
            // gets inside Telegram, and it doubles as proof the bot can actually
            // post here — which the connect UI cannot otherwise verify.
            TelegramBotClient::make()->sendMessage(
                (string) $chat['id'],
                __(':app is connected to this chat. Alerts you route here will arrive as messages.', [
                    'app' => config('app.name'),
                ]),
            );
        }

        return response()->json(['ok' => true]);
    }

    private function secretMatches(Request $request): bool
    {
        $expected = config('services.telegram.webhook_secret');
        if (! is_string($expected) || $expected === '') {
            // Refuse to run unsecured. An unset secret would otherwise mean
            // "accept anything", which is the worst possible default here.
            Log::warning('telegram.webhook.no_secret_configured');

            return false;
        }

        return hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''));
    }

    /**
     * Extract the payload from `/start <token>`.
     *
     * In groups Telegram delivers `/start@dply_bot <token>` — the bot mention is
     * part of the command word, so a naive `str_starts_with('/start ')` misses
     * every group connect, which is the main case.
     */
    private function startPayload(Request $request): ?string
    {
        $text = $request->input('message.text');
        if (! is_string($text)) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($text), 2);
        if ($parts === false || count($parts) < 2) {
            return null;
        }

        [$command, $payload] = $parts;

        // '/start' or '/start@somebot'
        if ($command !== '/start' && ! str_starts_with($command, '/start@')) {
            return null;
        }

        $payload = trim($payload);

        return $payload === '' ? null : $payload;
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function redeem(string $payload, array $chat): ?TelegramInstallation
    {
        return DB::transaction(function () use ($payload, $chat): ?TelegramInstallation {
            // lockForUpdate + a re-check inside the lock: two deliveries of the
            // same update (Telegram retries) must not create two installs.
            $token = TelegramConnectToken::query()
                ->where('token', $payload)
                ->lockForUpdate()
                ->first();

            if (! $token instanceof TelegramConnectToken || ! $token->isRedeemable()) {
                return null;
            }

            $owner = $this->ownerFor($token);
            if (! $owner instanceof Model) {
                return null;
            }

            $chatId = (string) $chat['id'];

            $installation = TelegramInstallation::query()->updateOrCreate(
                [
                    'owner_type' => $token->owner_type,
                    'owner_id' => $token->owner_id,
                    'chat_id' => $chatId,
                ],
                [
                    'chat_title' => $this->chatTitle($chat),
                    'chat_type' => (string) ($chat['type'] ?? 'group'),
                    'connected_by_user_id' => $token->user_id,
                ],
            );

            $token->forceFill([
                'consumed_at' => now(),
                'installation_id' => $installation->id,
            ])->save();

            $org = match (true) {
                $owner instanceof Organization => $owner,
                $owner instanceof Team => $owner->organization,
                default => null,
            };
            if ($org instanceof Organization) {
                audit_log($org, $token->user_id ? User::query()->find($token->user_id) : null,
                    'notification_channel.telegram_connected', $installation, null, [
                        'installation_id' => (string) $installation->id,
                        'chat_type' => $installation->chat_type,
                        'chat_title' => $installation->chat_title,
                    ]);
            }

            return $installation;
        });
    }

    /**
     * Groups carry `title`; private chats only have names.
     *
     * @param  array<string, mixed>  $chat
     */
    private function chatTitle(array $chat): string
    {
        $title = $chat['title'] ?? null;
        if (is_string($title) && $title !== '') {
            return $title;
        }

        $name = trim(((string) ($chat['first_name'] ?? '')).' '.((string) ($chat['last_name'] ?? '')));
        if ($name !== '') {
            return $name;
        }

        $username = $chat['username'] ?? null;

        return is_string($username) && $username !== '' ? '@'.$username : __('Telegram chat');
    }

    private function ownerFor(TelegramConnectToken $token): ?Model
    {
        // Only ever the three notification-channel owners.
        $model = match ($token->owner_type) {
            User::class => User::query()->find($token->owner_id),
            Organization::class => Organization::query()->find($token->owner_id),
            Team::class => Team::query()->find($token->owner_id),
            default => null,
        };

        return $model instanceof Model ? $model : null;
    }
}
