<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationInboxItem;
use App\Models\ProviderCredential;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Services\Providers\ProviderCredentialHealth;
use App\Support\Providers\ProviderAuthFailure;
use Illuminate\Console\Command;

/**
 * Periodic health check for stored cloud/DNS API tokens. Validates each against
 * its provider and drops an inbox item when a credential is rejected — BEFORE
 * the next droplet create fails with "Unable to authenticate you".
 */
class CheckProviderCredentialsCommand extends Command
{
    protected $signature = 'dply:provider-credentials:check';

    protected $description = 'Validate stored provider API tokens and notify owners of rejected ones';

    public function handle(ProviderCredentialHealth $health): int
    {
        $checked = 0;
        $unhealthy = 0;

        ProviderCredential::query()->orderBy('id')->chunkById(50, function ($credentials) use ($health, &$checked, &$unhealthy): void {
            foreach ($credentials as $credential) {
                if (! $health->supports($credential->provider)) {
                    continue;
                }

                $checked++;
                $ok = $health->refresh($credential, force: true);
                $credential->refresh();

                if ($ok === null) {
                    $this->line(sprintf('%s: skipped (provider unreachable)', $credential->name ?: $credential->id));

                    continue;
                }

                if ($ok === false) {
                    $unhealthy++;
                    $this->warn(sprintf(
                        '%s: %s',
                        $credential->name ?: $credential->provider,
                        $credential->validation_error ?? 'rejected',
                    ));
                    $this->notifyOwner($credential);
                } else {
                    $this->line(sprintf('%s: OK', $credential->name ?: $credential->provider));
                }
            }
        });

        $this->info(sprintf('Checked %d credential(s); %d need attention.', $checked, $unhealthy));

        return self::SUCCESS;
    }

    private function notifyOwner(ProviderCredential $credential): void
    {
        if (! filled($credential->user_id)) {
            return;
        }

        $exists = NotificationInboxItem::query()
            ->where('user_id', $credential->user_id)
            ->whereNull('read_at')
            ->where('metadata->kind', 'provider_credential_health')
            ->where('metadata->credential_id', $credential->id)
            ->exists();
        if ($exists) {
            return;
        }

        $label = filled($credential->name) ? $credential->name : ProviderAuthFailure::providerLabel($credential->provider);

        app(NotificationPublisher::class)->publish(
            eventKey: 'account.provider_credential.unhealthy',
            subject: $credential,
            title: __(':label was rejected by the provider', ['label' => $label]),
            body: __(':provider no longer accepts this API token. Creating servers or workers with it will fail until you add a new token on Credentials.', [
                'provider' => ProviderAuthFailure::providerLabel($credential->provider),
            ]),
            url: route('credentials.index', absolute: true),
            metadata: [
                'kind' => 'provider_credential_health',
                'credential_id' => $credential->id,
                'cta_label' => __('Open credentials'),
            ],
            recipientUsers: [(string) $credential->user_id],
        );
    }
}
