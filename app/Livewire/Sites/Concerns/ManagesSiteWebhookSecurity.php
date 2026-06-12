<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Services\Sites\RepositoryWebhookProvisioner;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteWebhookSecurity
{
    public ?string $revealed_webhook_secret = null;

    public string $webhook_allowed_ips_text = '';

    public function saveWebhookSecurity(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'webhook_allowed_ips_text' => 'nullable|string|max:4000',
        ]);
        $lines = preg_split('/\r\n|\r|\n/', $this->webhook_allowed_ips_text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! $this->validIpOrCidr($line)) {
                $this->addError('webhook_allowed_ips_text', 'Invalid IP or CIDR: '.$line);

                return;
            }
            $clean[] = $line;
        }
        $this->site->webhook_allowed_ips = $clean !== [] ? $clean : null;
        $this->site->save();
        $this->toastSuccess('Webhook IP allow list saved. Leave empty to allow any source (signature still required).');
        $this->syncFormFromSite();
    }

    protected function validIpOrCidr(string $value): bool
    {
        if (str_contains($value, '/')) {
            return (bool) preg_match('#^(\d{1,3}\.){3}\d{1,3}/(3[0-2]|[12]?\d)$#', $value);
        }

        return (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
    }

    public function regenerateWebhookSecret(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        $plain = Str::random(48);
        $this->site->webhook_secret = $plain;
        $this->site->save();
        $this->revealed_webhook_secret = $plain;
        $provisioner->syncProviderHookSecret($this->site->fresh());

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.webhook.secret_rotated', $this->site, null, null);
        }

        $this->toastSuccess('Webhook secret rotated. Copy it below — it will not be shown again.');
    }
}
