<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Services;

use App\Enums\QuotaSurface;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Cloud\Backends\CloudRouter;
use App\Support\SiteCreateBlocker;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

/**
 * Every reason a managed container app may not be created, in one place — the
 * Cloud counterpart to
 * {@see \App\Modules\Serverless\Services\ServerlessCreateGate}.
 *
 * Same reasoning as that one: `dry_run` has to reproduce the create wizard's
 * checks exactly and cannot call a Livewire component, so two implementations
 * of a money-gating chain would drift and the drift would show up as the CLI
 * allowing what the UI forbids.
 *
 * One check here is *new* rather than moved. The Cloud wizard never consulted
 * the billing pause, so a paused organization could provision container
 * infrastructure it was not allowed to deploy to — the same hole the Serverless
 * wizard closes with `canDeploy()`, and the same consequence: infrastructure
 * standing, billing, and unable to go live.
 */
final class CloudCreateGate
{
    public const CONTEXT_WEB = 'web';

    public const CONTEXT_API = 'api';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function check(
        User $user,
        Organization $organization,
        array $payload = [],
        string $context = self::CONTEXT_WEB,
    ): ?SiteCreateBlocker {
        if (! Feature::active('surface.cloud')) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::SURFACE_DISABLED,
                __('Cloud apps are not enabled on this dply instance.'),
            );
        }

        if ($context === self::CONTEXT_API && ! Feature::active('surface.cloud_cli_create')) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::CLI_CREATE_DISABLED,
                __('Creating cloud apps from the CLI is not enabled on this dply instance yet. Create it in the dashboard instead.'),
                '/cloud/create',
            );
        }

        if (! Gate::forUser($user)->allows('update', $organization)) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::FORBIDDEN,
                __('Your role in this organization cannot create cloud apps.'),
            );
        }

        if (! $organization->canCreateOnSurface(QuotaSurface::Cloud)) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::QUOTA_EXCEEDED,
                $organization->quotaLimitMessage(QuotaSurface::Cloud)
                    ?: __('This organization has reached its cloud app limit.'),
                '/settings/billing',
            );
        }

        if (! $organization->canDeploy()) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::TRIAL_PAUSED,
                __('Deploys are paused for this organization — add a payment method before creating a cloud app.'),
                '/settings/billing',
            );
        }

        $backend = $this->resolveBackend($organization, $payload);
        if ($backend === null) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::NO_BACKEND,
                __('No container backend is connected. Add DigitalOcean App Platform or AWS App Runner credentials first.'),
                '/credentials',
            );
        }

        // Source mode on App Runner builds from GitHub through an authorized
        // connection on the credential. Without it the create succeeds and the
        // provision fails later, which is the worst place to find out.
        if (($payload['mode'] ?? 'source') === 'source' && $backend === 'aws_app_runner'
            && ! $this->appRunnerSourceReady($organization)) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::SOURCE_UNSUPPORTED,
                __('This AWS App Runner credential has no authorized GitHub connection, so it cannot build from a repository. Authorize one, or deploy a prebuilt image instead.'),
                '/credentials',
            );
        }

        return null;
    }

    /**
     * The backend a create would actually use, so callers do not repeat the
     * auto-selection the gate just performed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveBackend(Organization $organization, array $payload): ?string
    {
        $requested = trim((string) ($payload['backend'] ?? 'auto'));

        if ($requested !== '' && $requested !== 'auto') {
            return $requested;
        }

        return CloudRouter::pickAutoBackend((string) $organization->id);
    }

    private function appRunnerSourceReady(Organization $organization): bool
    {
        $credential = \App\Models\ProviderCredential::query()
            ->where('organization_id', $organization->id)
            ->where('provider', 'aws_app_runner')
            ->first();

        $arn = is_array($credential?->credentials)
            ? ($credential->credentials['github_connection_arn'] ?? null)
            : null;

        return is_string($arn) && trim($arn) !== '';
    }
}
