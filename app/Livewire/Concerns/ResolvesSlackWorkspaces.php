<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Http\Controllers\Notifications\SlackOAuthController;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\SlackInstallation;
use App\Models\Team;
use App\Models\User;
use App\Modules\Notifications\Services\SlackWorkspaceClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Slack workspace state shared by both channel-creation surfaces
 * ({@see ManagesNotificationChannels} and {@see CreatesNotificationChannelInline}),
 * because they render the same `notification-channel-fields` partial.
 *
 * A Slack channel now has two shapes:
 *  - `oauth`   — points at a {@see SlackInstallation} plus a channel id. One
 *                connect, then every further channel is a dropdown pick.
 *  - `webhook` — the original pasted incoming-webhook URL. Kept as a first-class
 *                option, not a legacy path: self-hosters without a registered
 *                Slack app depend on it, and existing rows must keep working.
 *
 * @mixin DispatchesToastNotifications
 */
trait ResolvesSlackWorkspaces
{
    /** 'oauth' | 'webhook' — which Slack form the create modal is showing. */
    public string $new_slack_mode = 'oauth';

    public string $new_slack_installation_id = '';

    public string $new_slack_channel_id = '';

    public string $edit_slack_mode = 'oauth';

    public string $edit_slack_installation_id = '';

    public string $edit_slack_channel_id = '';

    /** Set while a channel-list refresh is in flight, for the button spinner. */
    public bool $slackChannelsRefreshing = false;

    /**
     * Per-request memo. Blade asks for the install list several times in one
     * render (the workspaces strip, the mode resolver, the picker), and this is
     * a plain property rather than a #[Computed] so both host traits — one of
     * which already owns a `channels` computed — can share it without colliding.
     *
     * @var Collection<int, SlackInstallation>|null
     */
    protected mixed $slackInstallationMemo = null;

    /**
     * Account a Slack install belongs to. Both host traits already know their
     * owner but under different names, so each maps it here.
     */
    abstract protected function channelIntegrationOwner(): User|Organization|Team;

    /**
     * Reopen the channel modal after returning from Slack, with the workspace
     * just connected preselected — so connecting from a server tab finishes
     * where it started instead of stranding the operator on a settings page.
     *
     * A Livewire trait hook (`class_uses_recursive` picks it up through both
     * host traits), fired after mount. It self-limits to the one full page load
     * Slack redirected to: a Livewire update POSTs to its own endpoint and
     * carries none of the page's query string, so the marker is absent there.
     */
    public function bootedResolvesSlackWorkspaces(): void
    {
        $installationId = request()->query('slack_connected');
        if (! is_string($installationId) || $installationId === '') {
            return;
        }

        // Scoped lookup — a hand-typed id can't preselect another owner's install.
        $installation = $this->slackInstallationFor($installationId);
        if (! $installation instanceof SlackInstallation) {
            return;
        }

        if (! Gate::allows('manageNotificationChannels', $this->channelIntegrationOwner())) {
            return;
        }

        if (! in_array(NotificationChannel::TYPE_SLACK, NotificationChannel::typesForUi(), true)) {
            return;
        }

        $this->openCreateChannelModal();

        // After openCreateChannelModal(), which resets the form and re-runs the
        // mode default — so these have to land last.
        $this->new_type = NotificationChannel::TYPE_SLACK;
        $this->new_slack_mode = 'oauth';
        $this->new_slack_installation_id = (string) $installation->id;
        $this->new_slack_channel_id = '';
    }

    public function slackOauthConfigured(): bool
    {
        return SlackOAuthController::configured();
    }

    /**
     * The mode the form is *actually* in, which is not always the property.
     *
     * A deployment with no Slack app registered can never satisfy the OAuth
     * branch, so the property is overridden to `webhook` rather than trusted.
     * Blade and validation both read this, so a form can never render one shape
     * and be validated against the other.
     */
    public function slackMode(string $prefix = 'new_'): string
    {
        $mode = (string) $this->{$prefix.'slack_mode'};

        if ($mode === 'oauth' && ! $this->slackOauthConfigured() && $this->slackInstallations()->isEmpty()) {
            return 'webhook';
        }

        return $mode === 'oauth' ? 'oauth' : 'webhook';
    }

    /**
     * @return Collection<int, SlackInstallation>
     */
    public function slackInstallations(): mixed
    {
        if ($this->slackInstallationMemo !== null) {
            return $this->slackInstallationMemo;
        }

        $owner = $this->channelIntegrationOwner();

        return $this->slackInstallationMemo = SlackInstallation::query()
            ->where('owner_type', $owner::class)
            ->where('owner_id', (string) $owner->getKey())
            ->orderBy('team_name')
            ->get();
    }

    /**
     * "Add to Slack" URL.
     *
     * Deliberately carries no `return_to`: this href is often generated *during a
     * Livewire update request*, where `request()->path()` is the Livewire update
     * endpoint rather than the page the operator is looking at — following it
     * after the callback lands on `GET /livewire-xxx/update`, which only accepts
     * POST. The blade anchors append the real path at click time via Alpine
     * (same convention as the Git provider connect buttons), and the controller
     * falls back to the owner's own notifications page when JS is off.
     */
    public function slackConnectUrl(): string
    {
        $owner = $this->channelIntegrationOwner();

        $type = match (true) {
            $owner instanceof Organization => 'organization',
            $owner instanceof Team => 'team',
            default => 'user',
        };

        return route('notifications.oauth.slack.redirect', [
            'owner' => $type,
            'owner_id' => (string) $owner->getKey(),
        ]);
    }

    /**
     * Channels for the currently selected workspace.
     *
     * @return list<array{id: string, name: string, is_private: bool, is_member: bool}>
     */
    public function slackChannelOptions(string $prefix = 'new_'): array
    {
        $installation = $this->slackInstallationFor((string) $this->{$prefix.'slack_installation_id'});

        return $installation instanceof SlackInstallation
            ? SlackWorkspaceClient::channelsFor($installation)
            : [];
    }

    /**
     * Drop the cached channel list — for the "Refresh" button next to the picker,
     * so a channel created in Slack seconds ago shows up without waiting out the
     * cache TTL.
     */
    public function refreshSlackChannels(string $prefix = 'new_'): void
    {
        $installation = $this->slackInstallationFor((string) $this->{$prefix.'slack_installation_id'});
        if (! $installation instanceof SlackInstallation) {
            return;
        }

        $this->slackChannelsRefreshing = true;
        SlackWorkspaceClient::channelsFor($installation, fresh: true);
        $this->slackChannelsRefreshing = false;
    }

    /**
     * Forget a workspace. Channels already pointed at it are left alone rather
     * than silently deleted — they surface as "workspace disconnected" in the
     * list, which is a fixable state; a vanished alert route is not.
     */
    public function disconnectSlackWorkspace(string $installationId): void
    {
        $owner = $this->channelIntegrationOwner();
        Gate::authorize('manageNotificationChannels', $owner);

        $installation = $this->slackInstallationFor($installationId);
        if (! $installation instanceof SlackInstallation) {
            return;
        }

        SlackWorkspaceClient::forgetChannelCache($installation);
        $teamName = $installation->team_name;
        $installation->delete();
        $this->slackInstallationMemo = null;

        if ($this->new_slack_installation_id === $installationId) {
            $this->new_slack_installation_id = '';
            $this->new_slack_channel_id = '';
        }

        $org = match (true) {
            $owner instanceof Organization => $owner,
            $owner instanceof Team => $owner->organization,
            default => Auth::user()?->currentOrganization(),
        };
        if ($org instanceof Organization) {
            audit_log($org, Auth::user(), 'notification_channel.slack_disconnected', null, [
                'installation_id' => $installationId,
                'team_name' => $teamName,
            ], null);
        }

        $this->toastSuccess(__('Slack workspace ":team" disconnected.', ['team' => $teamName]));
    }

    /**
     * Default the Slack form to whichever mode the owner can actually use: the
     * picker when a workspace is connected, the "Add to Slack" prompt when the
     * deployment has an app but no install yet, and the manual webhook fields
     * when neither applies.
     */
    protected function syncSlackModeDefault(string $prefix = 'new_'): void
    {
        $hasInstall = $this->slackInstallations()->isNotEmpty();

        if ($hasInstall) {
            $this->{$prefix.'slack_mode'} = 'oauth';
            if ((string) $this->{$prefix.'slack_installation_id'} === '') {
                $first = $this->slackInstallations()->first();
                $this->{$prefix.'slack_installation_id'} = (string) $first->id;
            }

            return;
        }

        $this->{$prefix.'slack_mode'} = $this->slackOauthConfigured() ? 'oauth' : 'webhook';
    }

    /** Selecting a different workspace invalidates the channel choice under it. */
    public function updatedNewSlackInstallationId(): void
    {
        $this->new_slack_channel_id = '';
    }

    public function updatedEditSlackInstallationId(): void
    {
        $this->edit_slack_channel_id = '';
    }

    /**
     * Config blob for an OAuth-backed Slack channel. `channel` (the #name) is
     * denormalized alongside `channel_id` purely so the channel list can label a
     * row without an API call; delivery always keys off the id, which survives a
     * rename.
     *
     * @return array<string, mixed>
     */
    protected function slackOauthConfigFromInput(string $prefix): array
    {
        $installation = $this->slackInstallationFor((string) $this->{$prefix.'slack_installation_id'});
        $channelId = (string) $this->{$prefix.'slack_channel_id'};

        $name = '';
        foreach ($this->slackChannelOptions($prefix) as $option) {
            if ($option['id'] === $channelId) {
                $name = $option['name'];
                break;
            }
        }

        return [
            'auth' => NotificationChannel::SLACK_AUTH_OAUTH,
            'installation_id' => $installation instanceof SlackInstallation ? (string) $installation->id : '',
            'channel_id' => $channelId,
            'channel' => $name !== '' ? '#'.$name : null,
            'team_id' => $installation instanceof SlackInstallation ? $installation->team_id : '',
            'team_name' => $installation instanceof SlackInstallation ? $installation->team_name : '',
        ];
    }

    /** Scoped lookup — an id from the request never reaches another owner's install. */
    protected function slackInstallationFor(string $installationId): ?Model
    {
        if ($installationId === '') {
            return null;
        }

        $owner = $this->channelIntegrationOwner();

        return SlackInstallation::query()
            ->where('owner_type', $owner::class)
            ->where('owner_id', (string) $owner->getKey())
            ->whereKey($installationId)
            ->first();
    }
}
