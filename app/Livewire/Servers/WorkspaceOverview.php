<?php

namespace App\Livewire\Servers;

use App\Jobs\CheckServerHealthJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\InsightFinding;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceOverview extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $health_check_url = '';

    /** @var list<string> */
    public array $quick_notification_channel_ids = [];

    /** @var list<string> */
    public array $quick_notification_event_keys = [];

    public bool $showQuickNotificationChannelModal = false;

    public string $quick_new_owner_scope = 'personal';

    public string $quick_new_type = NotificationChannel::TYPE_SLACK;

    public string $quick_new_label = '';

    public string $quick_new_slack_webhook_url = '';

    public string $quick_new_slack_channel = '';

    public string $quick_new_discord_webhook_url = '';

    public string $quick_new_email_address = '';

    public string $quick_new_telegram_bot_token = '';

    public string $quick_new_telegram_chat_id = '';

    public string $quick_new_pushover_app_token = '';

    public string $quick_new_pushover_user_key = '';

    public string $quick_new_teams_webhook_url = '';

    public string $quick_new_rocketchat_webhook_url = '';

    public string $quick_new_google_chat_webhook_url = '';

    public string $quick_new_mobile_device_token = '';

    public string $quick_new_mobile_platform = 'ios';

    public string $quick_new_webhook_url = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->health_check_url = (string) ($server->meta['health_check_url'] ?? '');
        $types = NotificationChannel::typesForUi();
        if ($types !== []) {
            $this->quick_new_type = $types[0];
        }
        $this->quick_new_owner_scope = $this->canManageOrganizationNotificationChannels() ? 'organization' : 'personal';
    }

    public function checkHealth(): void
    {
        $this->authorize('view', $this->server);
        if ($this->server->status === Server::STATUS_READY && ! empty($this->server->ip_address)) {
            CheckServerHealthJob::dispatch($this->server);
        }
        $this->flash_success = 'Health check has been queued. Status will update shortly.';
    }

    public function saveHealthCheckUrl(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['health_check_url' => 'nullable|string|url|max:500']);
        $meta = $this->server->meta ?? [];
        $meta['health_check_url'] = trim($this->health_check_url) ?: null;
        if ($meta['health_check_url'] === null) {
            unset($meta['health_check_url']);
        }
        $this->server->update(['meta' => $meta]);
        $this->flash_success = 'Health check URL updated.';
    }

    public function rerunSetup(): void
    {
        $this->authorize('update', $this->server);

        $server = $this->server->fresh();
        if (! $server || ! RunSetupScriptJob::shouldDispatch($server)) {
            $this->flash_error = 'This server is not ready for a setup re-run yet.';

            return;
        }

        $meta = $server->meta ?? [];
        unset($meta['provision_task_id']);

        $server->update([
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'meta' => $meta,
        ]);

        WaitForServerSshReadyJob::dispatch($server->fresh());

        $this->redirectRoute('servers.journey', $server, navigate: true);
    }

    public function saveQuickNotificationAssignments(): void
    {
        $this->authorize('view', $this->server);

        $this->validate([
            'quick_notification_channel_ids' => ['required', 'array', 'min:1'],
            'quick_notification_channel_ids.*' => ['string', 'exists:notification_channels,id'],
            'quick_notification_event_keys' => ['required', 'array', 'min:1'],
            'quick_notification_event_keys.*' => ['string', 'in:server.automatic_updates,server.ssh_login,server.insights_alerts,server.monitoring'],
        ], [], [
            'quick_notification_channel_ids' => __('channels'),
            'quick_notification_event_keys' => __('notification types'),
        ]);

        $org = Auth::user()?->currentOrganization();
        $allowedChannelIds = AssignableNotificationChannels::forUser(Auth::user(), $org)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        foreach ($this->quick_notification_channel_ids as $channelId) {
            if (! in_array((string) $channelId, $allowedChannelIds, true)) {
                $this->addError('quick_notification_channel_ids', __('Invalid channel selected.'));

                return;
            }
        }

        $created = 0;

        foreach ($this->quick_notification_channel_ids as $channelId) {
            $channel = NotificationChannel::query()->findOrFail((string) $channelId);
            Gate::authorize('manageNotificationChannels', $channel->owner);

            foreach ($this->quick_notification_event_keys as $eventKey) {
                $row = NotificationSubscription::firstOrCreate([
                    'notification_channel_id' => $channel->id,
                    'subscribable_type' => Server::class,
                    'subscribable_id' => $this->server->id,
                    'event_key' => $eventKey,
                ]);

                if ($row->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $this->flash_success = __('Saved :count new notification subscription(s) for this server.', ['count' => $created]);
    }

    public function createQuickNotificationChannel(): void
    {
        $owner = $this->quickNotificationChannelOwner();

        Gate::authorize('manageNotificationChannels', $owner);

        $rules = array_merge(
            [
                'quick_new_type' => ['required', 'string', \Illuminate\Validation\Rule::in(NotificationChannel::typesForUi())],
                'quick_new_owner_scope' => ['required', 'string', \Illuminate\Validation\Rule::in($this->quickAddOwnerScopes())],
            ],
            $this->quickChannelValidationRulesForType($this->quick_new_type)
        );

        $this->validate($rules, [], $this->quickChannelValidationAttributes());

        $channel = $owner->notificationChannels()->create([
            'type' => $this->quick_new_type,
            'label' => $this->quick_new_label,
            'config' => $this->quickChannelConfigFromInput(),
        ]);

        $this->quick_notification_channel_ids = array_values(array_unique([
            ...$this->quick_notification_channel_ids,
            (string) $channel->id,
        ]));

        $this->resetQuickNotificationChannelFields();
        $this->showQuickNotificationChannelModal = false;
        $this->flash_success = __('Channel created and selected for this server.');
    }

    public function openQuickNotificationChannelModal(): void
    {
        $this->resetErrorBag();
        $this->showQuickNotificationChannelModal = true;
    }

    public function closeQuickNotificationChannelModal(): void
    {
        $this->showQuickNotificationChannelModal = false;
    }

    protected function quickNotificationChannelOwner(): \App\Models\User|Organization
    {
        if ($this->quick_new_owner_scope === 'organization' && $this->canManageOrganizationNotificationChannels()) {
            return $this->server->organization;
        }

        return Auth::user();
    }

    protected function canManageOrganizationNotificationChannels(): bool
    {
        return $this->server->organization instanceof Organization
            && Gate::allows('manageNotificationChannels', $this->server->organization);
    }

    /**
     * @return list<string>
     */
    protected function quickAddOwnerScopes(): array
    {
        return $this->canManageOrganizationNotificationChannels()
            ? ['personal', 'organization']
            : ['personal'];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function quickChannelValidationRulesForType(string $type): array
    {
        $base = [
            'quick_new_label' => ['required', 'string', 'max:255'],
        ];

        return match ($type) {
            NotificationChannel::TYPE_SLACK => $base + [
                'quick_new_slack_webhook_url' => ['required', 'url', 'max:2000'],
                'quick_new_slack_channel' => ['nullable', 'string', 'max:255'],
            ],
            NotificationChannel::TYPE_DISCORD => $base + [
                'quick_new_discord_webhook_url' => ['required', 'url', 'max:2000'],
            ],
            NotificationChannel::TYPE_EMAIL => $base + [
                'quick_new_email_address' => ['required', 'email:rfc', 'max:255'],
            ],
            NotificationChannel::TYPE_TELEGRAM => $base + [
                'quick_new_telegram_bot_token' => ['required', 'string', 'max:255'],
                'quick_new_telegram_chat_id' => ['required', 'string', 'max:255'],
            ],
            NotificationChannel::TYPE_PUSHOVER => $base + [
                'quick_new_pushover_app_token' => ['required', 'string', 'max:255'],
                'quick_new_pushover_user_key' => ['required', 'string', 'max:255'],
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => $base + [
                'quick_new_teams_webhook_url' => ['required', 'url', 'max:2000'],
            ],
            NotificationChannel::TYPE_ROCKETCHAT => $base + [
                'quick_new_rocketchat_webhook_url' => ['required', 'url', 'max:2000'],
            ],
            NotificationChannel::TYPE_GOOGLE_CHAT => $base + [
                'quick_new_google_chat_webhook_url' => ['required', 'url', 'max:2000'],
            ],
            NotificationChannel::TYPE_MOBILE_APP => $base + [
                'quick_new_mobile_device_token' => ['required', 'string', 'max:4000'],
                'quick_new_mobile_platform' => ['required', 'string', 'in:ios,android'],
            ],
            default => $base + [
                'quick_new_webhook_url' => ['required', 'url', 'max:2000'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    protected function quickChannelValidationAttributes(): array
    {
        return [
            'quick_new_owner_scope' => __('owner'),
            'quick_new_type' => __('type'),
            'quick_new_label' => __('label'),
            'quick_new_slack_webhook_url' => __('webhook URL'),
            'quick_new_slack_channel' => __('channel'),
            'quick_new_discord_webhook_url' => __('webhook URL'),
            'quick_new_email_address' => __('email address'),
            'quick_new_telegram_bot_token' => __('bot token'),
            'quick_new_telegram_chat_id' => __('chat ID'),
            'quick_new_pushover_app_token' => __('application token'),
            'quick_new_pushover_user_key' => __('user key'),
            'quick_new_teams_webhook_url' => __('webhook URL'),
            'quick_new_rocketchat_webhook_url' => __('webhook URL'),
            'quick_new_google_chat_webhook_url' => __('webhook URL'),
            'quick_new_mobile_device_token' => __('device token'),
            'quick_new_mobile_platform' => __('platform'),
            'quick_new_webhook_url' => __('URL'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function quickChannelConfigFromInput(): array
    {
        return match ($this->quick_new_type) {
            NotificationChannel::TYPE_SLACK => array_filter([
                'webhook_url' => $this->quick_new_slack_webhook_url,
                'channel' => trim($this->quick_new_slack_channel) ?: null,
            ], fn ($value) => $value !== null && $value !== ''),
            NotificationChannel::TYPE_DISCORD => ['webhook_url' => $this->quick_new_discord_webhook_url],
            NotificationChannel::TYPE_EMAIL => ['email' => $this->quick_new_email_address],
            NotificationChannel::TYPE_TELEGRAM => [
                'bot_token' => $this->quick_new_telegram_bot_token,
                'chat_id' => $this->quick_new_telegram_chat_id,
            ],
            NotificationChannel::TYPE_PUSHOVER => [
                'app_token' => $this->quick_new_pushover_app_token,
                'user_key' => $this->quick_new_pushover_user_key,
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => ['webhook_url' => $this->quick_new_teams_webhook_url],
            NotificationChannel::TYPE_ROCKETCHAT => ['webhook_url' => $this->quick_new_rocketchat_webhook_url],
            NotificationChannel::TYPE_GOOGLE_CHAT => ['webhook_url' => $this->quick_new_google_chat_webhook_url],
            NotificationChannel::TYPE_MOBILE_APP => [
                'device_token' => $this->quick_new_mobile_device_token,
                'platform' => $this->quick_new_mobile_platform,
            ],
            default => ['url' => $this->quick_new_webhook_url],
        };
    }

    protected function resetQuickNotificationChannelFields(): void
    {
        $types = NotificationChannel::typesForUi();
        if ($types !== [] && ! in_array($this->quick_new_type, $types, true)) {
            $this->quick_new_type = $types[0];
        }

        $this->quick_new_label = '';
        $this->quick_new_slack_webhook_url = '';
        $this->quick_new_slack_channel = '';
        $this->quick_new_discord_webhook_url = '';
        $this->quick_new_email_address = '';
        $this->quick_new_telegram_bot_token = '';
        $this->quick_new_telegram_chat_id = '';
        $this->quick_new_pushover_app_token = '';
        $this->quick_new_pushover_user_key = '';
        $this->quick_new_teams_webhook_url = '';
        $this->quick_new_rocketchat_webhook_url = '';
        $this->quick_new_google_chat_webhook_url = '';
        $this->quick_new_mobile_device_token = '';
        $this->quick_new_mobile_platform = 'ios';
        $this->quick_new_webhook_url = '';
    }

    public function render(): View
    {
        $this->server->refresh();

        $siteSummaries = $this->server->sites()
            ->with(['domains'])
            ->orderBy('name')
            ->limit(4)
            ->get()
            ->map(function (Site $site): array {
                $primaryDomain = $site->domains->firstWhere('is_primary', true) ?? $site->domains->first();

                return [
                    'name' => $site->name,
                    'status' => $site->status,
                    'primary_domain' => $primaryDomain?->hostname,
                    'route' => route('sites.show', ['server' => $this->server, 'site' => $site]),
                ];
            });

        $siteIds = $this->server->sites()->pluck('id');
        $latestDeployment = $siteIds->isEmpty()
            ? null
            : SiteDeployment::query()
                ->with('site')
                ->whereIn('site_id', $siteIds)
                ->latest('created_at')
                ->first();

        $monitorLastSampleAt = isset(($this->server->meta ?? [])['monitoring_last_sample_at'])
            ? Carbon::parse($this->server->meta['monitoring_last_sample_at'])->timezone(config('app.timezone'))
            : null;

        $currentUser = Auth::user();
        $hasProfileSshKeys = $currentUser?->sshKeys()->exists() ?? false;
        $serverHasPersonalProfileKey = $this->server->hasPersonalUserSshKey($currentUser);

        $opsSummary = [
            'firewall_rules_enabled' => $this->server->firewallRules()->where('enabled', true)->count(),
            'cron_jobs' => $this->server->cronJobs()->count(),
            'daemons' => $this->server->supervisorPrograms()->count(),
            'ssh_keys' => $this->server->authorizedKeys()->count(),
        ];

        $healthSummary = [
            'status' => $this->server->health_status,
            'last_checked_at' => $this->server->last_health_check_at,
            'monitor_last_sample_at' => $monitorLastSampleAt,
        ];

        $insightFindings = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('detected_at')
            ->limit(3)
            ->get();

        $openInsightQuery = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN);

        $insightSummary = [
            'open_count' => (clone $openInsightQuery)->count(),
            'critical_count' => (clone $openInsightQuery)->where('severity', InsightFinding::SEVERITY_CRITICAL)->count(),
            'warning_count' => (clone $openInsightQuery)->where('severity', InsightFinding::SEVERITY_WARNING)->count(),
            'info_count' => (clone $openInsightQuery)->where('severity', InsightFinding::SEVERITY_INFO)->count(),
            'latest_detected_at' => (clone $openInsightQuery)->max('detected_at'),
        ];

        $assignableChannels = AssignableNotificationChannels::forUser(Auth::user(), Auth::user()?->currentOrganization());
        $serverEventOptions = collect(config('notification_events.categories.server.events', []))->all();
        $quickAddTypes = NotificationChannel::typesForUi();

        return view('livewire.servers.workspace-overview', [
            'siteSummaries' => $siteSummaries,
            'siteCount' => $this->server->sites()->count(),
            'latestDeployment' => $latestDeployment,
            'opsSummary' => $opsSummary,
            'healthSummary' => $healthSummary,
            'hasProfileSshKeys' => $hasProfileSshKeys,
            'insightFindings' => $insightFindings,
            'insightSummary' => $insightSummary,
            'assignableChannels' => $assignableChannels,
            'serverEventOptions' => $serverEventOptions,
            'serverHasPersonalProfileKey' => $serverHasPersonalProfileKey,
            'quickAddTypes' => $quickAddTypes,
            'canManageOrganizationNotificationChannels' => $this->canManageOrganizationNotificationChannels(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
