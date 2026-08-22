<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Enums\QuotaSurface;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Support\SiteCreateBlocker;
use App\Modules\Serverless\Support\ServerlessPlatformContext;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

/**
 * Every reason a serverless function may not be created, in one place.
 *
 * These checks used to live in four: route middleware, an `abort_unless` in
 * the create component's mount, three more in its `create()`, and two inside
 * {@see \App\Modules\Serverless\Actions\CreateServerlessFunction}. That was
 * survivable while a Livewire form was the only caller. It is not survivable
 * with an API create as well — `dry_run` has to reproduce the chain exactly,
 * and it cannot call a Livewire component (modules must not depend on the
 * shell). Two implementations of a money-gating chain would drift, and the
 * drift shows up as the CLI allowing what the UI forbids.
 *
 * So: one class, returning the first blocker or null. The wizard renders the
 * message inline, the API serialises the whole blocker, and `dry_run` is
 * simply this without the create.
 */
final class ServerlessCreateGate
{
    /** The web create wizard. */
    public const CONTEXT_WEB = 'web';

    /** The HTTP API — additionally gated on the CLI-create feature flag. */
    public const CONTEXT_API = 'api';

    /**
     * @param  array<string, mixed>  $payload  the create payload, as far as it is known
     */
    public function check(
        User $user,
        Organization $organization,
        array $payload = [],
        string $context = self::CONTEXT_WEB,
    ): ?SiteCreateBlocker {
        if (! Feature::active('surface.serverless')) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::SURFACE_DISABLED,
                __('Serverless is not enabled on this dply instance.'),
            );
        }

        // The CLI-create surface ships dark and enables per instance. The
        // wizard predates it and is not gated on it.
        if ($context === self::CONTEXT_API && ! Feature::active('surface.serverless_cli_create')) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::CLI_CREATE_DISABLED,
                __('Creating functions from the CLI is not enabled on this dply instance yet. Create it in the dashboard instead.'),
                '/serverless/create',
            );
        }

        if (! Gate::forUser($user)->allows('update', $organization)) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::FORBIDDEN,
                __('Your role in this organization cannot create functions.'),
            );
        }

        // The function ceiling — NOT canCreateSite(), which is the machine-site
        // surface. Functions already tally into QuotaSurface::Serverless via
        // quotaUsageBySurface(), so checking the Site ceiling meant a count
        // that functions never increment: max_functions went unenforced
        // entirely. See docs/adr/cli-init-and-site-creation.md.
        if (! $organization->canCreateOnSurface(QuotaSurface::Serverless)) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::QUOTA_EXCEEDED,
                $organization->quotaLimitMessage(QuotaSurface::Serverless)
                    ?: __('This organization has reached its function limit.'),
                '/settings/billing',
            );
        }

        // Creating is only half the act: the chain immediately provisions a
        // billable namespace and hands off to a deploy that a pause-blocked
        // org cannot run. Letting it through leaves a namespace standing and a
        // function that can never go live.
        if (! $organization->canDeploy()) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::TRIAL_PAUSED,
                __('Deploys are paused for this organization — add a payment method before creating a function.'),
                '/settings/billing',
            );
        }

        $regions = (array) config('serverless.regions', []);
        $region = trim((string) ($payload['region'] ?? ''));
        if ($region !== '' && $regions !== [] && ! array_key_exists($region, $regions)) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::INVALID_REGION,
                __('":region" is not a region functions can run in. Available: :list', [
                    'region' => $region,
                    'list' => implode(', ', array_keys($regions)),
                ]),
            );
        }

        return ($payload['delivery_mode'] ?? 'byo') === 'managed'
            ? $this->checkManaged()
            : $this->checkByo($organization, $payload);
    }

    public function managedAvailable(): bool
    {
        return Feature::active('surface.serverless_managed')
            && ServerlessPlatformContext::fromConfig()->configured();
    }

    private function checkManaged(): ?SiteCreateBlocker
    {
        if (! $this->managedAvailable()) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::MANAGED_UNAVAILABLE,
                __('dply-managed serverless is not available on this instance. Use your own DigitalOcean account instead.'),
                '/credentials',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkByo(Organization $organization, array $payload): ?SiteCreateBlocker
    {
        $credentialId = trim((string) ($payload['provider_credential_id'] ?? ''));

        // No credential named: fall back to the org's preferred one, which is
        // what both the wizard and `dply init` do when the user never picks.
        $credential = $credentialId !== ''
            ? ProviderCredential::query()
                ->where('id', $credentialId)
                ->where('organization_id', $organization->id)
                ->where('provider', 'digitalocean')
                ->first()
            : ProviderCredential::preferredHealthyForOrganization($organization->id, 'digitalocean');

        if ($credential === null) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::NO_PROVIDER_CREDENTIAL,
                $credentialId !== ''
                    ? __('That DigitalOcean credential is missing, belongs to another organization, or is not a DigitalOcean credential.')
                    : __('This organization has no DigitalOcean credential, and a namespace cannot be provisioned without one.'),
                '/credentials',
            );
        }

        if ($credential->isUnhealthy()) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::CREDENTIAL_UNHEALTHY,
                __('The DigitalOcean credential ":name" can no longer authenticate. Replace it, then try again.', [
                    'name' => (string) $credential->name,
                ]),
                '/credentials',
            );
        }

        return null;
    }

    /**
     * The credential a create would actually use, so callers do not repeat the
     * fallback logic the gate just performed.
     */
    public function resolveCredential(Organization $organization, array $payload): ?ProviderCredential
    {
        if (($payload['delivery_mode'] ?? 'byo') === 'managed') {
            return null;
        }

        $credentialId = trim((string) ($payload['provider_credential_id'] ?? ''));

        return $credentialId !== ''
            ? ProviderCredential::query()
                ->where('id', $credentialId)
                ->where('organization_id', $organization->id)
                ->where('provider', 'digitalocean')
                ->first()
            : ProviderCredential::preferredHealthyForOrganization($organization->id, 'digitalocean');
    }
}
