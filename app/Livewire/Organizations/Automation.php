<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\ApiToken;
use App\Models\NotificationWebhookDestination;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Automation extends Component
{
    use ConfirmsActionWithModal;

    public Organization $organization;

    public string $token_name = '';

    public ?string $token_expires_at = null;

    /** full | read | deploy | ops — maps to API abilities */
    public string $token_scope = 'full';

    public string $token_allowed_ips_text = '';

    public string $int_hook_name = '';

    public string $int_hook_driver = NotificationWebhookDestination::DRIVER_SLACK;

    public string $int_hook_url = '';

    public ?string $int_hook_site_id = null;

    public bool $int_evt_success = true;

    public bool $int_evt_failed = true;

    public bool $int_evt_skipped = true;

    public bool $int_evt_deploy_started = false;

    public bool $int_evt_uptime_down = true;

    public bool $int_evt_uptime_recovered = true;

    public bool $int_evt_insight_opened = false;

    public bool $int_evt_insight_resolved = false;

    public bool $deploy_email_notifications_enabled = true;

    public ?string $new_token_plaintext = null;

    public ?string $new_token_name = null;

    public bool $show_new_api_token_modal = false;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        abort_unless($organization->hasAdminAccess(auth()->user()), 403);
        $this->organization = $organization;
        $this->refreshOrganization();
    }

    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh()
            ->load([
                'apiTokens',
                'notificationWebhookDestinations',
                'sites' => fn ($q) => $q->orderBy('name'),
            ]);
        $this->deploy_email_notifications_enabled = (bool) $this->organization->deploy_email_notifications_enabled;
    }

    public function updatedDeployEmailNotificationsEnabled(): void
    {
        $this->authorize('update', $this->organization);

        $this->organization->update([
            'deploy_email_notifications_enabled' => $this->deploy_email_notifications_enabled,
        ]);
        audit_log($this->organization, auth()->user(), 'organization.deploy_email_notifications_updated', null, null, [
            'enabled' => $this->deploy_email_notifications_enabled,
        ]);
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Deploy email preferences updated.');
    }

    public function createApiToken(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'token_name' => 'required|string|max:255',
            'token_expires_at' => 'nullable|date|after:today',
            'token_allowed_ips_text' => 'nullable|string|max:4000',
        ]);

        $expiresAt = $this->token_expires_at ? Carbon::parse($this->token_expires_at) : null;
        if ($expiresAt === null && $this->token_scope === 'deploy') {
            $expiresAt = now()->addDays((int) config('dply.api_token_deploy_default_ttl_days', 14));
        }
        $presets = config('api_token_permissions.presets', []);
        $abilities = match ($this->token_scope) {
            'read' => $presets['read'] ?? [],
            'deploy' => $presets['deploy'] ?? [],
            'ops' => $presets['ops'] ?? [],
            default => $presets['full'] ?? ['*'],
        };
        $allowedIps = $this->parseTokenAllowedIps($this->token_allowed_ips_text);
        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::createToken(
            auth()->user(),
            $this->organization,
            $this->token_name,
            $expiresAt,
            $abilities,
            $allowedIps
        );

        $this->new_token_plaintext = $plaintext;
        $this->new_token_name = $token->name;
        $this->show_new_api_token_modal = true;
        $this->reset(['token_name', 'token_expires_at', 'token_allowed_ips_text']);
        $this->refreshOrganization();
    }

    public function clearNewToken(): void
    {
        $this->show_new_api_token_modal = false;
        $this->new_token_plaintext = null;
        $this->new_token_name = null;
    }

    /**
     * Opens the confirm modal without embedding JSON in wire:click (which breaks HTML attributes).
     */
    public function promptRevokeApiToken(string $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::query()
            ->where('organization_id', $this->organization->id)
            ->whereKey($apiTokenId)
            ->first();

        if ($apiToken === null) {
            return;
        }

        $this->openConfirmActionModal(
            'revokeApiToken',
            [$apiToken->id],
            __('Revoke API token'),
            __('Revoke :name? Integrations using this token will stop working immediately. This cannot be undone.', ['name' => $apiToken->name]),
            __('Revoke token'),
            true
        );
    }

    public function revokeApiToken(int|string $apiTokenId): void
    {
        $this->authorize('update', $this->organization);

        $apiToken = ApiToken::where('organization_id', $this->organization->id)->findOrFail($apiTokenId);
        $apiToken->delete();

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'API token revoked.');
    }

    public function saveWebhookDestination(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'int_hook_name' => 'required|string|max:120',
            'int_hook_driver' => 'required|string|in:slack,discord,teams',
            'int_hook_url' => 'required|string|url|max:2000',
            'int_hook_site_id' => 'nullable',
        ]);

        $siteId = $this->int_hook_site_id !== null && $this->int_hook_site_id !== ''
            ? (string) $this->int_hook_site_id
            : null;
        if ($siteId && ! $this->organization->sites()->whereKey($siteId)->exists()) {
            throw ValidationException::withMessages(['int_hook_site_id' => 'Invalid site for this organization.']);
        }

        $events = [];
        if ($this->int_evt_success) {
            $events[] = 'deploy_success';
        }
        if ($this->int_evt_failed) {
            $events[] = 'deploy_failed';
        }
        if ($this->int_evt_skipped) {
            $events[] = 'deploy_skipped';
        }
        if ($this->int_evt_deploy_started) {
            $events[] = 'deploy_started';
        }
        if ($this->int_evt_uptime_down) {
            $events[] = 'uptime_down';
        }
        if ($this->int_evt_uptime_recovered) {
            $events[] = 'uptime_recovered';
        }
        if ($this->int_evt_insight_opened) {
            $events[] = 'insight_opened';
        }
        if ($this->int_evt_insight_resolved) {
            $events[] = 'insight_resolved';
        }

        NotificationWebhookDestination::query()->create([
            'organization_id' => $this->organization->id,
            'site_id' => $siteId,
            'name' => $this->int_hook_name,
            'driver' => $this->int_hook_driver,
            'webhook_url' => $this->int_hook_url,
            'events' => $events !== [] ? $events : null,
            'enabled' => true,
        ]);

        $this->reset(['int_hook_name', 'int_hook_url', 'int_hook_site_id']);
        $this->int_hook_driver = NotificationWebhookDestination::DRIVER_SLACK;
        $this->int_evt_success = true;
        $this->int_evt_failed = true;
        $this->int_evt_skipped = true;
        $this->int_evt_deploy_started = false;
        $this->int_evt_uptime_down = true;
        $this->int_evt_uptime_recovered = true;
        $this->int_evt_insight_opened = false;
        $this->int_evt_insight_resolved = false;
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Webhook destination saved.');
    }

    public function deleteWebhookDestination(string $id): void
    {
        $this->authorize('update', $this->organization);
        $hook = $this->organization->notificationWebhookDestinations()->whereKey($id)->firstOrFail();
        $hook->delete();
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Webhook destination removed.');
    }

    public function toggleWebhookDestination(string $id): void
    {
        $this->authorize('update', $this->organization);
        $hook = $this->organization->notificationWebhookDestinations()->whereKey($id)->firstOrFail();
        $hook->update(['enabled' => ! $hook->enabled]);
        $this->refreshOrganization();
    }

    /**
     * @return array<int, string>|null
     */
    protected function parseTokenAllowedIps(string $raw): ?array
    {
        return ApiToken::parseAllowedIpsInput($raw, 'token_allowed_ips_text');
    }

    public function render(): View
    {
        return view('livewire.organizations.automation');
    }
}
