<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Enums\ServerProvider;
use App\Jobs\ProvisionHetznerServerJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\Beta\BetaProgram;
use App\Support\Servers\ServerHostingPlatformContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates a dply-managed server: a Hetzner VM provisioned on dply's OWN platform
 * account (dply pays Hetzner) and billed all-in cost-plus, rather than the
 * customer's connected credential. The size/region come from the curated catalog
 * in config/managed_servers.php and the stack from a standard install profile.
 *
 * Counterpart to {@see StoreServerFromCreateForm::storeHetzner()} for BYO servers.
 */
final class StoreManagedServer
{
    use AsObject;

    /**
     * @param  array{name: string, region: string, size: string, install_profile: string}  $input
     */
    public function handle(User $user, Organization $org, array $input): Server
    {
        $platform = ServerHostingPlatformContext::forOrg($org);
        if (! $platform->configured()) {
            throw ValidationException::withMessages([
                'form.size' => __('dply-managed servers are not available yet.'),
            ]);
        }

        // Verified email gates provisioning — the cheap anti-abuse signal that
        // sits before any real Hetzner spend (no card-on-file during beta).
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'form.size' => __('Please verify your email address before creating a managed server.'),
            ]);
        }

        // Enforce the single free-CX22 grant for beta orgs.
        if (! $org->canCreateManagedServer()) {
            throw ValidationException::withMessages([
                'form.size' => __('Your beta includes one free managed server. Delete the existing one to create another, or subscribe for more.'),
            ]);
        }

        // Beta's free box is pinned to CX22 — no comp-the-difference math.
        if ($org->isBeta()) {
            $input['size'] = (string) config('subscription.standard.beta.managed_size', 'cx22');
        }

        $regions = array_keys((array) config('managed_servers.regions', []));
        $sizes = collect((array) config('managed_servers.sizes', []))
            ->pluck('slug')->filter()->values()->all();
        $profiles = collect((array) config('server_provision_options.install_profiles', []))
            ->pluck('id')->filter()->values()->all();

        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'region' => ['required', 'string', Rule::in($regions)],
            'size' => ['required', 'string', Rule::in($sizes)],
            'install_profile' => ['required', 'string', Rule::in($profiles)],
        ])->validate();

        $profile = collect((array) config('server_provision_options.install_profiles', []))
            ->firstWhere('id', $data['install_profile']) ?? [];

        $meta = BuildServerProvisionMeta::run(
            $data['install_profile'],
            (string) ($profile['server_role'] ?? 'application'),
            (string) ($profile['cache_service'] ?? 'redis'),
            (string) ($profile['webserver'] ?? 'nginx'),
            (string) ($profile['php_version'] ?? '8.3'),
            (string) ($profile['database'] ?? 'none'),
        );

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $data['name'],
            'provider' => ServerProvider::Hetzner,
            'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
            'provider_credential_id' => null,
            'region' => $data['region'],
            'size' => $data['size'],
            'meta' => $meta,
            'status' => Server::STATUS_PENDING,
            // Beta's free box is comped until the global cutover (null = until
            // cutover when no date is set yet — see Server::isComped()). This
            // localizes the "is it free?" decision to one column the billing
            // calc already reads, so subscribe-early and cutover fall out cleanly.
            'comped_until' => $org->isBeta() ? BetaProgram::compedUntil() : null,
        ]);

        ProvisionHetznerServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server, ['hosting_backend' => Server::HOSTING_BACKEND_DPLY]);

        return $server;
    }
}
