<?php

namespace App\Livewire\Concerns;

use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * @property-read Collection<int, NotificationChannel> $channels Livewire computed (access as $this->channels; do not invoke $this->channels()).
 */
trait ManagesNotificationChannels
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    public string $new_type = NotificationChannel::TYPE_SLACK;

    public string $new_label = '';

    public string $new_slack_webhook_url = '';

    public string $new_slack_channel = '';

    public string $new_discord_webhook_url = '';

    public string $new_email_address = '';

    public string $new_telegram_bot_token = '';

    public string $new_telegram_chat_id = '';

    public string $new_pushover_app_token = '';

    public string $new_pushover_user_key = '';

    public string $new_teams_webhook_url = '';

    public string $new_rocketchat_webhook_url = '';

    public string $new_google_chat_webhook_url = '';

    public string $new_mobile_device_token = '';

    public string $new_mobile_platform = 'ios';

    public string $new_webhook_url = '';

    public string $search = '';

    public ?string $editing_id = null;

    public string $edit_type = NotificationChannel::TYPE_SLACK;

    public string $edit_label = '';

    public string $edit_slack_webhook_url = '';

    public string $edit_slack_channel = '';

    public string $edit_discord_webhook_url = '';

    public string $edit_email_address = '';

    public string $edit_telegram_bot_token = '';

    public string $edit_telegram_chat_id = '';

    public string $edit_pushover_app_token = '';

    public string $edit_pushover_user_key = '';

    public string $edit_teams_webhook_url = '';

    public string $edit_rocketchat_webhook_url = '';

    public string $edit_google_chat_webhook_url = '';

    public string $edit_mobile_device_token = '';

    public string $edit_mobile_platform = 'ios';

    public string $edit_webhook_url = '';

    public ?string $testing_id = null;

    abstract protected function owner(): User|Organization|Team;

    abstract protected function notificationChannelsViewData(): array;

    /**
     * Call from each component's mount() so the default type matches config-enabled types.
     */
    protected function syncNotificationChannelTypeDefaults(): void
    {
        $allowed = NotificationChannel::typesForUi();
        if ($allowed === []) {
            return;
        }

        if (! in_array($this->new_type, $allowed, true)) {
            $this->new_type = $allowed[0];
        }
    }

    public function updatedSearch(): void
    {
        unset($this->channels);
    }

    #[Computed]
    public function channels()
    {
        $q = $this->owner()->notificationChannels()->withCount('subscriptions')->orderBy('label');
        $s = trim($this->search);
        if ($s !== '') {
            $q->where('label', 'like', '%'.$s.'%');
        }

        return $q->get();
    }

    public function canManage(): bool
    {
        return Gate::allows('manageNotificationChannels', $this->owner());
    }

    public function createChannel(): void
    {
        Gate::authorize('manageNotificationChannels', $this->owner());
        $this->resetErrorBag();

        $rules = array_merge(
            [
                'new_type' => ['required', 'string', Rule::in(NotificationChannel::typesForUi())],
            ],
            $this->validationRulesForType($this->new_type, 'new_')
        );
        $this->validate($rules, [], array_merge(['new_type' => __('type')], $this->validationAttributes('new_')));

        $config = $this->configFromInput($this->new_type, 'new_');
        $this->owner()->notificationChannels()->create([
            'type' => $this->new_type,
            'label' => $this->new_label,
            'config' => $config,
        ]);

        $this->resetNewChannelFields();
        unset($this->channels);
        $this->toastSuccess(__('Channel created.'));
    }

    protected function resetNewChannelFields(): void
    {
        $this->new_label = '';
        $this->new_slack_webhook_url = '';
        $this->new_slack_channel = '';
        $this->new_discord_webhook_url = '';
        $this->new_email_address = '';
        $this->new_telegram_bot_token = '';
        $this->new_telegram_chat_id = '';
        $this->new_pushover_app_token = '';
        $this->new_pushover_user_key = '';
        $this->new_teams_webhook_url = '';
        $this->new_rocketchat_webhook_url = '';
        $this->new_google_chat_webhook_url = '';
        $this->new_mobile_device_token = '';
        $this->new_mobile_platform = 'ios';
        $this->new_webhook_url = '';
    }

    public function startEdit(int $id): void
    {
        $channel = $this->owner()->notificationChannels()->findOrFail($id);
        Gate::authorize('update', $channel);
        $this->editing_id = $channel->id;
        $this->edit_type = $channel->type;
        $this->edit_label = $channel->label;
        $cfg = $channel->config;
        $this->clearEditChannelFields();
        if ($channel->type === NotificationChannel::TYPE_SLACK) {
            $this->edit_slack_webhook_url = (string) ($cfg['webhook_url'] ?? '');
            $this->edit_slack_channel = (string) ($cfg['channel'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_DISCORD) {
            $this->edit_discord_webhook_url = (string) ($cfg['webhook_url'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_EMAIL) {
            $this->edit_email_address = (string) ($cfg['email'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_TELEGRAM) {
            $this->edit_telegram_bot_token = (string) ($cfg['bot_token'] ?? '');
            $this->edit_telegram_chat_id = (string) ($cfg['chat_id'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_PUSHOVER) {
            $this->edit_pushover_app_token = (string) ($cfg['app_token'] ?? '');
            $this->edit_pushover_user_key = (string) ($cfg['user_key'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_MICROSOFT_TEAMS) {
            $this->edit_teams_webhook_url = (string) ($cfg['webhook_url'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_ROCKETCHAT) {
            $this->edit_rocketchat_webhook_url = (string) ($cfg['webhook_url'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_GOOGLE_CHAT) {
            $this->edit_google_chat_webhook_url = (string) ($cfg['webhook_url'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_MOBILE_APP) {
            $this->edit_mobile_device_token = (string) ($cfg['device_token'] ?? '');
            $this->edit_mobile_platform = (string) ($cfg['platform'] ?? 'ios');
        } elseif ($channel->type === NotificationChannel::TYPE_WEBHOOK) {
            $this->edit_webhook_url = (string) ($cfg['url'] ?? '');
        }
    }

    protected function clearEditChannelFields(): void
    {
        $this->edit_slack_webhook_url = '';
        $this->edit_slack_channel = '';
        $this->edit_discord_webhook_url = '';
        $this->edit_email_address = '';
        $this->edit_telegram_bot_token = '';
        $this->edit_telegram_chat_id = '';
        $this->edit_pushover_app_token = '';
        $this->edit_pushover_user_key = '';
        $this->edit_teams_webhook_url = '';
        $this->edit_rocketchat_webhook_url = '';
        $this->edit_google_chat_webhook_url = '';
        $this->edit_mobile_device_token = '';
        $this->edit_mobile_platform = 'ios';
        $this->edit_webhook_url = '';
    }

    public function cancelEdit(): void
    {
        $this->editing_id = null;
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $channel = $this->owner()->notificationChannels()->findOrFail($this->editing_id);
        Gate::authorize('update', $channel);
        $this->resetErrorBag();

        $rules = array_merge(
            [
                'edit_type' => ['required', 'string', Rule::in(NotificationChannel::typesForUi($this->edit_type))],
            ],
            $this->validationRulesForType($this->edit_type, 'edit_')
        );
        $this->validate($rules, [], array_merge(['edit_type' => __('type')], $this->validationAttributes('edit_')));

        $channel->update([
            'type' => $this->edit_type,
            'label' => $this->edit_label,
            'config' => $this->configFromInput($this->edit_type, 'edit_'),
        ]);

        $this->cancelEdit();
        unset($this->channels);
        $this->toastSuccess(__('Channel updated.'));
    }

    public function deleteChannel(string|int $id): void
    {
        $channel = $this->owner()->notificationChannels()->findOrFail($id);
        Gate::authorize('delete', $channel);
        $channel->delete();
        unset($this->channels);
        $this->toastSuccess(__('Channel removed.'));
    }

    public function sendTest(string|int $id): void
    {
        $channel = $this->owner()->notificationChannels()->findOrFail($id);
        Gate::authorize('update', $channel);
        $this->testing_id = (string) $id;
        $result = $channel->sendTest(Auth::user());
        $this->testing_id = null;
        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(string $prefix): array
    {
        $p = $prefix;

        return [
            $p.'label' => __('label'),
            $p.'slack_webhook_url' => __('webhook URL'),
            $p.'slack_channel' => __('channel'),
            $p.'discord_webhook_url' => __('webhook URL'),
            $p.'email_address' => __('email address'),
            $p.'telegram_bot_token' => __('bot token'),
            $p.'telegram_chat_id' => __('chat ID'),
            $p.'pushover_app_token' => __('application token'),
            $p.'pushover_user_key' => __('user key'),
            $p.'teams_webhook_url' => __('webhook URL'),
            $p.'rocketchat_webhook_url' => __('webhook URL'),
            $p.'google_chat_webhook_url' => __('webhook URL'),
            $p.'mobile_device_token' => __('device token'),
            $p.'mobile_platform' => __('platform'),
            $p.'webhook_url' => __('URL'),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function validationRulesForType(string $type, string $prefix): array
    {
        $labelKey = $prefix.'label';

        $base = [
            $labelKey => ['required', 'string', 'max:160'],
        ];

        return match ($type) {
            NotificationChannel::TYPE_SLACK => $base + [
                $prefix.'slack_webhook_url' => ['required', 'string', 'url', 'max:2048'],
                $prefix.'slack_channel' => ['nullable', 'string', 'max:120'],
            ],
            NotificationChannel::TYPE_DISCORD => $base + [
                $prefix.'discord_webhook_url' => ['required', 'string', 'url', 'max:2048'],
            ],
            NotificationChannel::TYPE_EMAIL => $base + [
                $prefix.'email_address' => ['required', 'string', 'email', 'max:254'],
            ],
            NotificationChannel::TYPE_TELEGRAM => $base + [
                $prefix.'telegram_bot_token' => ['required', 'string', 'max:512'],
                $prefix.'telegram_chat_id' => ['required', 'string', 'max:64'],
            ],
            NotificationChannel::TYPE_PUSHOVER => $base + [
                $prefix.'pushover_app_token' => ['required', 'string', 'max:64'],
                $prefix.'pushover_user_key' => ['required', 'string', 'max:64'],
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => $base + [
                $prefix.'teams_webhook_url' => ['required', 'string', 'url', 'max:2048'],
            ],
            NotificationChannel::TYPE_ROCKETCHAT => $base + [
                $prefix.'rocketchat_webhook_url' => ['required', 'string', 'url', 'max:2048'],
            ],
            NotificationChannel::TYPE_GOOGLE_CHAT => $base + [
                $prefix.'google_chat_webhook_url' => ['required', 'string', 'url', 'max:2048'],
            ],
            NotificationChannel::TYPE_MOBILE_APP => $base + [
                $prefix.'mobile_device_token' => ['required', 'string', 'max:4096'],
                $prefix.'mobile_platform' => ['required', 'string', 'in:ios,android'],
            ],
            NotificationChannel::TYPE_WEBHOOK => $base + [
                $prefix.'webhook_url' => ['required', 'string', 'url', 'max:2048'],
            ],
            default => $base,
        };
    }

    protected function configFromInput(string $type, string $prefix): array
    {
        return match ($type) {
            NotificationChannel::TYPE_SLACK => [
                'webhook_url' => $this->{$prefix.'slack_webhook_url'},
                'channel' => $this->{$prefix.'slack_channel'} ?: null,
            ],
            NotificationChannel::TYPE_DISCORD => [
                'webhook_url' => $this->{$prefix.'discord_webhook_url'},
            ],
            NotificationChannel::TYPE_EMAIL => [
                'email' => $this->{$prefix.'email_address'},
            ],
            NotificationChannel::TYPE_TELEGRAM => [
                'bot_token' => $this->{$prefix.'telegram_bot_token'},
                'chat_id' => $this->{$prefix.'telegram_chat_id'},
            ],
            NotificationChannel::TYPE_PUSHOVER => [
                'app_token' => $this->{$prefix.'pushover_app_token'},
                'user_key' => $this->{$prefix.'pushover_user_key'},
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => [
                'webhook_url' => $this->{$prefix.'teams_webhook_url'},
            ],
            NotificationChannel::TYPE_ROCKETCHAT => [
                'webhook_url' => $this->{$prefix.'rocketchat_webhook_url'},
            ],
            NotificationChannel::TYPE_GOOGLE_CHAT => [
                'webhook_url' => $this->{$prefix.'google_chat_webhook_url'},
            ],
            NotificationChannel::TYPE_MOBILE_APP => [
                'device_token' => $this->{$prefix.'mobile_device_token'},
                'platform' => $this->{$prefix.'mobile_platform'},
            ],
            NotificationChannel::TYPE_WEBHOOK => [
                'url' => $this->{$prefix.'webhook_url'},
            ],
            default => [],
        };
    }

    public function renderNotificationChannelsView(string $view = 'livewire.settings.notification-channels'): View
    {
        return view($view, array_merge([
            'backUrl' => null,
            'backLabel' => null,
            'useOrgShell' => false,
            'organization' => null,
            'orgShellSection' => 'notifications',
        ], $this->notificationChannelsViewData(), [
            'channels' => $this->channels,
            'canManage' => $this->canManage(),
            'types' => NotificationChannel::typesForUi(),
            'typesForEdit' => NotificationChannel::typesForUi($this->editing_id ? $this->edit_type : null),
        ]));
    }
}
