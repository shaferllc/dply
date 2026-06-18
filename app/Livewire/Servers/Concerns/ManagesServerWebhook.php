<?php

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceNotifications;
use App\Models\OutboundWebhookDelivery;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-server outbound webhook: a single signed (HMAC-SHA256) URL that receives
 * server-scoped events, plus the test-fire + resend + deliveries-log controls.
 *
 * Lives on {@see WorkspaceNotifications} so all of a
 * server's outbound event delivery (channel subscriptions, integration
 * webhooks, this signed endpoint) sits in one place. It was previously a
 * Settings → Webhook sub-tab; that URL now redirects here.
 *
 * The stored secret is APP_KEY-encrypted at rest under
 * meta['server_event_webhook_secret']; the form only carries plaintext when the
 * user is rotating it (empty = leave the stored secret untouched).
 */
trait ManagesServerWebhook
{
    public string $settingsWebhookUrl = '';

    /** Plaintext only when the user changes it; empty means leave stored secret. */
    public string $settingsWebhookSecret = '';

    public function saveServerWebhooks(): void
    {
        $this->authorize('update', $this->server);
        if ($this->webhookEditingBlocked()) {
            $this->toastError(__('Deployers cannot change webhook settings.'));

            return;
        }

        $this->validate([
            'settingsWebhookUrl' => ['nullable', 'string', 'max:2048'],
            'settingsWebhookSecret' => ['nullable', 'string', 'max:512'],
        ]);

        $meta = $this->server->meta ?? [];
        $url = trim($this->settingsWebhookUrl);
        if ($url === '') {
            unset($meta['server_event_webhook_url']);
            unset($meta['server_event_webhook_secret']);
        } else {
            $meta['server_event_webhook_url'] = $url;
            if ($this->settingsWebhookSecret !== '') {
                $meta['server_event_webhook_secret'] = Crypt::encryptString($this->settingsWebhookSecret);
            }
        }

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->settingsWebhookSecret = '';
        $this->syncServerWebhookFromServer();
        $this->toastSuccess(__('Webhook settings saved.'));
    }

    public function sendTestWebhook(OutboundWebhookDispatcher $dispatcher): void
    {
        $this->authorize('update', $this->server);

        $delivery = $dispatcher->dispatchForServer(
            'webhook.test',
            $this->server,
            [
                'message' => 'This is a test event from the Dply server notifications UI.',
                'fired_by_user_id' => Auth::id(),
            ],
            'Manual test webhook'
        );

        if ($this->server->organization) {
            audit_log($this->server->organization, Auth::user(), 'server.webhook.test_dispatched', $this->server, null, [
                'delivery_id' => (string) $delivery->id,
                'status' => $delivery->status,
            ]);
        }

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

        if ($delivery->url === '') {
            $this->toastError(__('Cannot resend: this delivery has no URL (would-send placeholder).'));

            return;
        }

        $dispatcher->resend($delivery);

        if ($this->server->organization) {
            audit_log($this->server->organization, Auth::user(), 'server.webhook.delivery_resent', $this->server, null, [
                'delivery_id' => (string) $delivery->id,
                'original_status' => $delivery->status,
                'event' => $delivery->event ?? null,
            ]);
        }

        $this->toastSuccess(__('Delivery requeued.'));
    }

    /**
     * Last 30 outbound webhook attempts for this server (newest first). Drives
     * the deliveries log; "would send" rows are included so an operator can
     * audit what would fire before wiring up a URL.
     *
     * @return Collection<int, OutboundWebhookDelivery>
     */
    protected function recentWebhookDeliveries(): Collection
    {
        return OutboundWebhookDelivery::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();
    }

    protected function syncServerWebhookFromServer(): void
    {
        $m = $this->server->meta ?? [];
        $this->settingsWebhookUrl = (string) ($m['server_event_webhook_url'] ?? '');
        $this->settingsWebhookSecret = '';
    }

    protected function webhookEditingBlocked(): bool
    {
        return (bool) Auth::user()?->currentOrganization()?->userIsDeployer(Auth::user());
    }
}
