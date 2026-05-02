<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesExtendedServerSettings;
use App\Livewire\Servers\Concerns\ManagesWorkspaceSettingsForm;
use App\Models\NotificationSubscription;
use App\Models\OutboundWebhookDelivery;
use App\Models\Server;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\ServerHealthProbe;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSettings extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesExtendedServerSettings;
    use ManagesWorkspaceSettingsForm;

    /** @var string Settings sub-page slug (see config server_settings.workspace_tabs). */
    public string $section = 'connection';

    /** @var array<string, mixed>|null Most recent inline test-connection result. Null until the user clicks Test connection. */
    public ?array $testConnectionResult = null;

    public function mount(Server $server, ?string $section = null): void
    {
        if ($section === null) {
            $this->redirect(route('servers.settings', ['server' => $server, 'section' => 'connection']), navigate: true);

            return;
        }

        $allowed = array_keys(config('server_settings.workspace_tabs', []));
        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        $this->bootWorkspace($server);
        $this->server->refresh();
        $this->syncSettingsFormFromServer();
        $this->syncExtendedServerSettingsFromServer();
    }

    #[Computed]
    public function canEditServerSettings(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }

    public function checkHealth(ServerHealthProbe $probe): void
    {
        $this->authorize('view', $this->server);

        if ($this->server->status !== Server::STATUS_READY || empty($this->server->ip_address)) {
            $this->testConnectionResult = [
                'ok' => false,
                'method' => null,
                'latency_ms' => null,
                'host' => $this->server->ip_address ?: null,
                'port' => (int) ($this->server->ssh_port ?: 22),
                'http_status' => null,
                'http_url' => null,
                'error' => __('Server is not ready or has no IP address.'),
                'tested_at' => now()->toIso8601String(),
            ];

            return;
        }

        $result = $probe->probe($this->server);
        $this->testConnectionResult = $result;

        $this->server->update([
            'last_health_check_at' => now(),
            'health_status' => $result['ok'] ? Server::HEALTH_REACHABLE : Server::HEALTH_UNREACHABLE,
        ]);
        $this->server->refresh();
    }

    public function sendTestWebhook(OutboundWebhookDispatcher $dispatcher): void
    {
        $this->authorize('update', $this->server);

        $delivery = $dispatcher->dispatchForServer(
            'webhook.test',
            $this->server,
            [
                'message' => 'This is a test event from the Dply server settings UI.',
                'fired_by_user_id' => auth()->id(),
            ],
            'Manual test webhook'
        );

        if ($delivery->status === OutboundWebhookDelivery::STATUS_WOULD_SEND) {
            $this->toastSuccess(__('No URL configured — recorded as “would send”. Check the deliveries log below.'));

            return;
        }

        $this->toastSuccess(__('Test webhook queued. Refresh the deliveries log in a moment to see the result.'));
    }

    public function resendWebhookDelivery(string $deliveryId, OutboundWebhookDispatcher $dispatcher): void
    {
        $this->authorize('update', $this->server);

        $delivery = OutboundWebhookDelivery::query()
            ->where('server_id', $this->server->id)
            ->whereKey($deliveryId)
            ->first();

        if ($delivery === null) {
            $this->toastError(__('Delivery not found.'));

            return;
        }

        if ($delivery->url === null || $delivery->url === '') {
            $this->toastError(__('Cannot resend: this delivery has no URL (would-send placeholder).'));

            return;
        }

        $dispatcher->resend($delivery);
        $this->toastSuccess(__('Delivery requeued.'));
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load([
            'sites.domains',
            'serverDatabases',
            'cronJobs',
            'supervisorPrograms',
            'firewallRules',
            'authorizedKeys',
            'recipes',
            'providerCredential',
        ]);

        $webhookDeliveries = $this->section === 'webhook'
            ? OutboundWebhookDelivery::query()
                ->where('server_id', $this->server->id)
                ->orderByDesc('created_at')
                ->limit(30)
                ->get()
            : collect();

        $serverNotifSubscriptions = collect();
        $assignableChannels = collect();
        if ($this->section === 'alerts') {
            $serverNotifSubscriptions = NotificationSubscription::query()
                ->where('subscribable_type', Server::class)
                ->where('subscribable_id', $this->server->id)
                ->with('channel')
                ->get();
            $assignableChannels = AssignableNotificationChannels::forUser(auth()->user(), auth()->user()?->currentOrganization())
                ->sortBy('label')
                ->values();
        }

        return view('livewire.servers.workspace-settings', [
            'section' => $this->section,
            'workspaces' => $this->workspacesForCurrentServerOrg(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'webhookDeliveries' => $webhookDeliveries,
            'serverNotifSubscriptions' => $serverNotifSubscriptions,
            'assignableChannels' => $assignableChannels,
        ]);
    }
}
