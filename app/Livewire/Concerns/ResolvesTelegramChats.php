<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramConnectToken;
use App\Models\TelegramInstallation;
use App\Models\User;
use App\Modules\Notifications\Services\TelegramBotClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Telegram chat state shared by both channel-creation surfaces.
 *
 * The connect flow is shaped differently from Slack's and Discord's, because
 * Telegram is: there is no OAuth and **no redirect back to us**. Clicking
 * connect opens the Telegram app, the operator picks a chat there, and dply
 * learns about it out-of-band when Telegram POSTs `/start <token>` to the
 * webhook.
 *
 * So instead of the return-to-modal dance, the form issues a claim-check token,
 * opens the deep link in a new tab, and *waits* — polling for the token to be
 * redeemed. When it is, the new chat appears selected. Nothing else in the form
 * moves, so the operator never loses their place.
 *
 *  - `connected` — points at a {@see TelegramInstallation} discovered this way.
 *  - `manual`    — the original pasted bot token + chat ID, still first-class
 *                  for self-hosters who never registered a bot with us.
 */
trait ResolvesTelegramChats
{
    /** 'connected' | 'manual' — which Telegram form the create modal is showing. */
    public string $new_telegram_mode = 'connected';

    public string $new_telegram_installation_id = '';

    public string $edit_telegram_mode = 'connected';

    public string $edit_telegram_installation_id = '';

    /** Deep link the operator is currently expected to be following, if any. */
    public string $telegramConnectLink = '';

    /** The claim-check token behind {@see $telegramConnectLink}; polled until redeemed. */
    public string $telegramConnectToken = '';

    /**
     * Per-request memo.
     *
     * @var Collection<int, TelegramInstallation>|null
     */
    protected mixed $telegramInstallationMemo = null;

    abstract protected function channelIntegrationOwner(): User|Organization|Team;

    public function telegramBotConfigured(): bool
    {
        return TelegramBotClient::botConfigured();
    }

    /**
     * The mode the form is actually in — forced to `manual` where no bot is
     * configured, so blade and validation can never disagree.
     */
    public function telegramMode(string $prefix = 'new_'): string
    {
        $mode = (string) $this->{$prefix.'telegram_mode'};

        if ($mode === 'connected' && ! $this->telegramBotConfigured() && $this->telegramInstallations()->isEmpty()) {
            return 'manual';
        }

        return $mode === 'connected' ? 'connected' : 'manual';
    }

    /**
     * @return Collection<int, TelegramInstallation>
     */
    public function telegramInstallations(): mixed
    {
        if ($this->telegramInstallationMemo !== null) {
            return $this->telegramInstallationMemo;
        }

        $owner = $this->channelIntegrationOwner();

        return $this->telegramInstallationMemo = TelegramInstallation::query()
            ->where('owner_type', $owner::class)
            ->where('owner_id', (string) $owner->getKey())
            ->orderBy('chat_title')
            ->get();
    }

    /**
     * The bot's username, needed for the t.me link.
     *
     * Cached for a day: it changes only when someone renames the bot in
     * BotFather, and an uncached getMe on every render would put a Telegram
     * round trip in the render path.
     */
    public function telegramBotUsername(): string
    {
        if (! $this->telegramBotConfigured()) {
            return '';
        }

        $cached = Cache::remember('telegram:bot_username', now()->addDay(), static function (): string {
            $me = TelegramBotClient::make()->me();

            return $me['ok'] ? $me['username'] : '';
        });

        return is_string($cached) ? $cached : '';
    }

    /**
     * Issue a claim check and hand back the deep link.
     *
     * `startgroup` (not `start`) is what makes Telegram show its own group
     * picker — the one-tap path to a group chat. The operator can still open the
     * bot directly for a DM; both arrive here as the same `/start <token>`.
     */
    public function startTelegramConnect(): void
    {
        $owner = $this->channelIntegrationOwner();
        Gate::authorize('manageNotificationChannels', $owner);

        $username = $this->telegramBotUsername();
        if ($username === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Could not reach Telegram to identify the dply bot. Check the bot token on this deployment.'));
            }

            return;
        }

        // Housekeeping on a natural trigger rather than a scheduled job: these
        // rows are worthless once expired and there are very few of them.
        TelegramConnectToken::purgeExpired();

        $token = TelegramConnectToken::issueFor($owner, Auth::user());

        $this->telegramConnectToken = $token->token;
        $this->telegramConnectLink = 'https://t.me/'.$username.'?startgroup='.$token->token;

        // Opened by the browser, not a redirect: the operator keeps this page
        // (and the half-filled form on it) exactly where it was.
        $this->dispatch('open-external', url: $this->telegramConnectLink);
    }

    /** Direct-message variant of the same claim check — no group picker. */
    public function telegramDirectMessageLink(): string
    {
        $username = $this->telegramBotUsername();

        return $username === '' || $this->telegramConnectToken === ''
            ? ''
            : 'https://t.me/'.$username.'?start='.$this->telegramConnectToken;
    }

    public function cancelTelegramConnect(): void
    {
        $this->telegramConnectToken = '';
        $this->telegramConnectLink = '';
    }

    /**
     * Poll target while the deep link is outstanding. Cheap by construction: one
     * indexed lookup, and it stops as soon as the token resolves or expires.
     */
    public function pollTelegramConnect(): void
    {
        if ($this->telegramConnectToken === '') {
            return;
        }

        $token = TelegramConnectToken::query()->where('token', $this->telegramConnectToken)->first();

        if (! $token instanceof TelegramConnectToken) {
            $this->cancelTelegramConnect();

            return;
        }

        if ($token->consumed_at === null) {
            if ($token->expires_at->isPast()) {
                $this->cancelTelegramConnect();
                if (method_exists($this, 'toastError')) {
                    $this->toastError(__('That Telegram link expired before a chat was picked. Try connecting again.'));
                }
            }

            return;
        }

        $this->telegramInstallationMemo = null;

        $installation = $token->installation_id !== null
            ? $this->telegramInstallationFor((string) $token->installation_id)
            : null;

        if ($installation instanceof TelegramInstallation) {
            $this->new_telegram_mode = 'connected';
            $this->new_telegram_installation_id = (string) $installation->id;

            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('Connected to ":chat".', ['chat' => $installation->chat_title]));
            }
        }

        $this->cancelTelegramConnect();
    }

    /**
     * Forget a chat. Channels pointed at it are left in place, reporting a
     * fixable error on send.
     *
     * Does not remove the bot from the Telegram chat — only a chat admin can do
     * that, so the toast says so rather than implying access was revoked.
     */
    public function disconnectTelegramChat(string $installationId): void
    {
        $owner = $this->channelIntegrationOwner();
        Gate::authorize('manageNotificationChannels', $owner);

        $installation = $this->telegramInstallationFor($installationId);
        if (! $installation instanceof TelegramInstallation) {
            return;
        }

        $chatTitle = $installation->chat_title;
        $installation->delete();
        $this->telegramInstallationMemo = null;

        if ($this->new_telegram_installation_id === $installationId) {
            $this->new_telegram_installation_id = '';
        }

        $org = match (true) {
            $owner instanceof Organization => $owner,
            $owner instanceof Team => $owner->organization,
            default => Auth::user()?->currentOrganization(),
        };
        if ($org instanceof Organization) {
            audit_log($org, Auth::user(), 'notification_channel.telegram_disconnected', null, [
                'installation_id' => $installationId,
                'chat_title' => $chatTitle,
            ], null);
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Telegram chat ":chat" disconnected. Remove the dply bot in Telegram to fully revoke it.', [
                'chat' => $chatTitle,
            ]));
        }
    }

    protected function syncTelegramModeDefault(string $prefix = 'new_'): void
    {
        $installations = $this->telegramInstallations();

        if ($installations->isNotEmpty()) {
            $this->{$prefix.'telegram_mode'} = 'connected';
            if ((string) $this->{$prefix.'telegram_installation_id'} === '') {
                $first = $installations->first();
                $this->{$prefix.'telegram_installation_id'} = $first instanceof TelegramInstallation ? (string) $first->id : '';
            }

            return;
        }

        $this->{$prefix.'telegram_mode'} = $this->telegramBotConfigured() ? 'connected' : 'manual';
    }

    /**
     * Config blob for a connected Telegram channel.
     *
     * `bot_token` is deliberately NOT stored: it is deployment config, and
     * copying it into every channel row would mean a token rotation silently
     * broke every existing channel.
     *
     * @return array<string, mixed>
     */
    protected function telegramConnectedConfigFromInput(string $prefix): array
    {
        $installation = $this->telegramInstallationFor((string) $this->{$prefix.'telegram_installation_id'});

        return [
            'auth' => NotificationChannel::TELEGRAM_AUTH_CONNECTED,
            'installation_id' => $installation instanceof TelegramInstallation ? (string) $installation->id : '',
            'chat_id' => $installation instanceof TelegramInstallation ? $installation->chat_id : '',
            'chat_title' => $installation instanceof TelegramInstallation ? $installation->chat_title : '',
        ];
    }

    /** Scoped lookup — an id from the request never reaches another owner's chat. */
    protected function telegramInstallationFor(string $installationId): ?Model
    {
        if ($installationId === '') {
            return null;
        }

        $owner = $this->channelIntegrationOwner();

        return TelegramInstallation::query()
            ->where('owner_type', $owner::class)
            ->where('owner_id', (string) $owner->getKey())
            ->whereKey($installationId)
            ->first();
    }
}
