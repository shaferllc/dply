<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Http\Controllers\Notifications\DiscordOAuthController;
use App\Models\DiscordInstallation;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Modules\Notifications\Services\DiscordGuildClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Discord guild state shared by both channel-creation surfaces
 * ({@see ManagesNotificationChannels} and {@see CreatesNotificationChannelInline}).
 *
 * Mirrors {@see ResolvesSlackWorkspaces} deliberately — same two modes, same
 * mode-resolution rule, same connect/return dance — because the two channel
 * types sit next to each other in one form and behaving differently would be
 * the surprise, not the consistency.
 *
 *  - `oauth`   — points at a {@see DiscordInstallation} plus a channel id.
 *  - `webhook` — the original pasted webhook URL, still first-class for
 *                self-hosters with no Discord application registered.
 *
 * @mixin DispatchesToastNotifications
 */
trait ResolvesDiscordGuilds
{
    /** 'oauth' | 'webhook' — which Discord form the create modal is showing. */
    public string $new_discord_mode = 'oauth';

    public string $new_discord_installation_id = '';

    public string $new_discord_channel_id = '';

    public string $edit_discord_mode = 'oauth';

    public string $edit_discord_installation_id = '';

    public string $edit_discord_channel_id = '';

    /**
     * Per-request memo; see the equivalent in {@see ResolvesSlackWorkspaces}.
     *
     * @var Collection<int, DiscordInstallation>|null
     */
    protected mixed $discordInstallationMemo = null;

    /**
     * Account a Discord install belongs to. Both host traits already map this
     * for Slack; this reuses that same owner so the two never diverge.
     */
    abstract protected function channelIntegrationOwner(): User|Organization|Team;

    public function discordOauthConfigured(): bool
    {
        return DiscordOAuthController::configured();
    }

    /**
     * The mode the form is actually in — the property is overridden to `webhook`
     * on deployments that can never satisfy the OAuth branch, so blade and
     * validation can't disagree about which fields are in play.
     */
    public function discordMode(string $prefix = 'new_'): string
    {
        $mode = (string) $this->{$prefix.'discord_mode'};

        if ($mode === 'oauth' && ! $this->discordOauthConfigured() && $this->discordInstallations()->isEmpty()) {
            return 'webhook';
        }

        return $mode === 'oauth' ? 'oauth' : 'webhook';
    }

    /**
     * @return Collection<int, DiscordInstallation>
     */
    public function discordInstallations(): mixed
    {
        if ($this->discordInstallationMemo !== null) {
            return $this->discordInstallationMemo;
        }

        $owner = $this->channelIntegrationOwner();

        return $this->discordInstallationMemo = DiscordInstallation::query()
            ->where('owner_type', $owner::class)
            ->where('owner_id', (string) $owner->getKey())
            ->orderBy('guild_name')
            ->get();
    }

    /**
     * "Add to Discord" URL. Carries no `return_to` — the blade anchors append the
     * real page path at click time, because this markup is usually rendered by a
     * Livewire update whose request path is the Livewire endpoint.
     */
    public function discordConnectUrl(): string
    {
        $owner = $this->channelIntegrationOwner();

        $type = match (true) {
            $owner instanceof Organization => 'organization',
            $owner instanceof Team => 'team',
            default => 'user',
        };

        return route('notifications.oauth.discord.redirect', [
            'owner' => $type,
            'owner_id' => (string) $owner->getKey(),
        ]);
    }

    /**
     * @return list<array{id: string, name: string, is_announcement: bool}>
     */
    public function discordChannelOptions(string $prefix = 'new_'): array
    {
        $installation = $this->discordInstallationFor((string) $this->{$prefix.'discord_installation_id'});

        return $installation instanceof DiscordInstallation
            ? DiscordGuildClient::channelsFor($installation)
            : [];
    }

    public function refreshDiscordChannels(string $prefix = 'new_'): void
    {
        $installation = $this->discordInstallationFor((string) $this->{$prefix.'discord_installation_id'});
        if (! $installation instanceof DiscordInstallation) {
            return;
        }

        DiscordGuildClient::channelsFor($installation, fresh: true);
    }

    /**
     * Forget a server. Channels pointed at it are left in place — they report a
     * fixable "server disconnected" on send, which beats a silently vanished
     * alert route.
     *
     * Note this does not remove the bot from the Discord guild; only a Discord
     * admin can do that, and dply has no permission to kick itself.
     */
    public function disconnectDiscordGuild(string $installationId): void
    {
        $owner = $this->channelIntegrationOwner();
        Gate::authorize('manageNotificationChannels', $owner);

        $installation = $this->discordInstallationFor($installationId);
        if (! $installation instanceof DiscordInstallation) {
            return;
        }

        DiscordGuildClient::forgetChannelCache($installation);
        $guildName = $installation->guild_name;
        $installation->delete();
        $this->discordInstallationMemo = null;

        if ($this->new_discord_installation_id === $installationId) {
            $this->new_discord_installation_id = '';
            $this->new_discord_channel_id = '';
        }

        $org = match (true) {
            $owner instanceof Organization => $owner,
            $owner instanceof Team => $owner->organization,
            default => Auth::user()?->currentOrganization(),
        };
        if ($org instanceof Organization) {
            audit_log($org, Auth::user(), 'notification_channel.discord_disconnected', null, [
                'installation_id' => $installationId,
                'guild_name' => $guildName,
            ], null);
        }

        $this->toastSuccess(__('Discord server ":guild" disconnected. Remove the dply bot in Discord to fully revoke it.', [
            'guild' => $guildName,
        ]));
    }

    /**
     * Reopen the channel modal after returning from Discord, with the server just
     * connected preselected. Livewire trait hook; see the Slack twin for why
     * `booted` (rather than `mount`) and why it self-limits to one page load.
     */
    public function bootedResolvesDiscordGuilds(): void
    {
        $installationId = request()->query('discord_connected');
        if (! is_string($installationId) || $installationId === '') {
            return;
        }

        $installation = $this->discordInstallationFor($installationId);
        if (! $installation instanceof DiscordInstallation) {
            return;
        }

        if (! Gate::allows('manageNotificationChannels', $this->channelIntegrationOwner())) {
            return;
        }

        if (! in_array(NotificationChannel::TYPE_DISCORD, NotificationChannel::typesForUi(), true)) {
            return;
        }

        $this->openCreateChannelModal();

        $this->new_type = NotificationChannel::TYPE_DISCORD;
        $this->new_discord_mode = 'oauth';
        $this->new_discord_installation_id = (string) $installation->id;
        $this->new_discord_channel_id = '';
    }

    public function updatedNewDiscordInstallationId(): void
    {
        $this->new_discord_channel_id = '';
    }

    public function updatedEditDiscordInstallationId(): void
    {
        $this->edit_discord_channel_id = '';
    }

    protected function syncDiscordModeDefault(string $prefix = 'new_'): void
    {
        $installations = $this->discordInstallations();

        if ($installations->isNotEmpty()) {
            $this->{$prefix.'discord_mode'} = 'oauth';
            if ((string) $this->{$prefix.'discord_installation_id'} === '') {
                $first = $installations->first();
                $this->{$prefix.'discord_installation_id'} = (string) $first->id;
            }

            return;
        }

        $this->{$prefix.'discord_mode'} = $this->discordOauthConfigured() ? 'oauth' : 'webhook';
    }

    /**
     * Config blob for an OAuth-backed Discord channel. `channel` (the #name) is
     * denormalized for labelling only; delivery keys off `channel_id`, which
     * survives a rename.
     *
     * @return array<string, mixed>
     */
    protected function discordOauthConfigFromInput(string $prefix): array
    {
        $installation = $this->discordInstallationFor((string) $this->{$prefix.'discord_installation_id'});
        $channelId = (string) $this->{$prefix.'discord_channel_id'};

        $name = '';
        foreach ($this->discordChannelOptions($prefix) as $option) {
            if ($option['id'] === $channelId) {
                $name = $option['name'];
                break;
            }
        }

        return [
            'auth' => NotificationChannel::DISCORD_AUTH_OAUTH,
            'installation_id' => $installation instanceof DiscordInstallation ? (string) $installation->id : '',
            'channel_id' => $channelId,
            'channel' => $name !== '' ? '#'.$name : null,
            'guild_id' => $installation instanceof DiscordInstallation ? $installation->guild_id : '',
            'guild_name' => $installation instanceof DiscordInstallation ? $installation->guild_name : '',
        ];
    }

    /** Scoped lookup — an id from the request never reaches another owner's install. */
    protected function discordInstallationFor(string $installationId): ?Model
    {
        if ($installationId === '') {
            return null;
        }

        $owner = $this->channelIntegrationOwner();

        return DiscordInstallation::query()
            ->where('owner_type', $owner::class)
            ->where('owner_id', (string) $owner->getKey())
            ->whereKey($installationId)
            ->first();
    }
}
