<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Enums\QuotaSurface;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\SiteCreateBlocker;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

/**
 * Every reason a site may not be created on a server the organization owns —
 * the BYO sibling of {@see \App\Modules\Serverless\Services\ServerlessCreateGate}
 * and {@see \App\Modules\Cloud\Services\CloudCreateGate}.
 *
 * Kernel rather than a module because BYO servers are the kernel's own product:
 * `Site` and `Server` are hub models and there is no Vm module to put this in.
 *
 * Unlike its two siblings this gate is **not** yet shared with the create
 * wizard. The wizard's create lives inline in a Livewire concern that branches
 * on host capabilities, and extracting it is a change to the app's oldest flow
 * that deserves its own review — see {@see CreateVmSite}. Until then the two
 * paths agree on quota and role by both deferring to the same
 * `Organization`/`SitePolicy` methods, and the CLI path is additionally the
 * stricter of the two.
 */
final class VmCreateGate
{
    public const CONTEXT_WEB = 'web';

    public const CONTEXT_API = 'api';

    public const HOST_UNSUPPORTED = 'host_unsupported';

    public const SERVER_NOT_READY = 'server_not_ready';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function check(
        User $user,
        Organization $organization,
        ?Server $server,
        array $payload = [],
        string $context = self::CONTEXT_WEB,
    ): ?SiteCreateBlocker {
        if ($context === self::CONTEXT_API && ! Feature::active('surface.vm_cli_create')) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::CLI_CREATE_DISABLED,
                __('Creating server sites from the CLI is not enabled on this dply instance yet.'),
                '/servers',
            );
        }

        if (! Gate::forUser($user)->allows('update', $organization)) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::FORBIDDEN,
                __('Your role in this organization cannot create sites.'),
            );
        }

        if ($server === null || (string) $server->organization_id !== (string) $organization->id) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::SOURCE_REQUIRED,
                __('Pick a server in this organization to create the site on.'),
                '/servers',
            );
        }

        // A site created against a server that never came up would sit pending
        // forever, and the reason would be on the server rather than the site.
        if (! $server->isReady()) {
            return new SiteCreateBlocker(
                self::SERVER_NOT_READY,
                __('Server ":name" is not ready yet (status: :status).', [
                    'name' => (string) $server->name,
                    'status' => (string) $server->status,
                ]),
                '/servers/'.$server->id,
            );
        }

        if (! CreateVmSite::supports($server)) {
            return new SiteCreateBlocker(
                self::HOST_UNSUPPORTED,
                __('":name" is a container, Kubernetes, functions, or headless host. Sites on those are created in the dashboard, where the host-specific options live.', [
                    'name' => (string) $server->name,
                ]),
                '/servers/'.$server->id.'/sites/create',
            );
        }

        if (! $organization->canCreateOnSurface(QuotaSurface::Site)) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::QUOTA_EXCEEDED,
                $organization->quotaLimitMessage(QuotaSurface::Site)
                    ?: __('This organization has reached its site limit.'),
                '/settings/billing',
            );
        }

        if (! $organization->canDeploy()) {
            return new SiteCreateBlocker(
                SiteCreateBlocker::TRIAL_PAUSED,
                __('Deploys are paused for this organization — add a payment method before creating a site.'),
                '/settings/billing',
            );
        }

        return null;
    }
}
