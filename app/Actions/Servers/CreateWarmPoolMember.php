<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionHetznerServerJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerPoolMember;
use App\Support\Servers\ServerHostingPlatformContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Create one warm-pool member: a dply-MANAGED VM (hosting_backend=dply_managed,
 * no customer credential) provisioned on dply's OWN platform account, owned by
 * the system pool org until claimed, tracked by a {@see ServerPoolMember} in
 * `warming`. The autoscaler flips it to `ready` once provisioning completes.
 *
 * Warm pool is managed-only by design: a pooled VM lives on the platform
 * account, so it can only be handed to a managed-server create (same account).
 * Managed servers are Hetzner today (mirrors {@see StoreManagedServer}).
 *
 * Defensive: if the pool org / platform context isn't configured it logs and
 * returns null rather than creating an orphaned resource.
 */
class CreateWarmPoolMember
{
    /**
     * @param  array<string, mixed>  $bucket
     */
    public function create(array $bucket): ?ServerPoolMember
    {
        $providerValue = (string) ($bucket['provider'] ?? '');
        $region = (string) ($bucket['region'] ?? '');
        $size = (string) ($bucket['size'] ?? '');
        $tier = (string) ($bucket['tier'] ?? ServerPoolMember::TIER_BASELINE);

        // Managed hosting is Hetzner-only today.
        if (ServerProvider::tryFrom($providerValue) !== ServerProvider::Hetzner) {
            Log::warning('warm_pool.create.unsupported_provider', ['provider' => $providerValue, 'note' => 'warm pool is managed-only (Hetzner)']);

            return null;
        }

        $org = $this->poolOrganization();
        if (! $org) {
            Log::warning('warm_pool.create.no_pool_org', ['config' => 'warm_pool.pool_organization_id']);

            return null;
        }

        $platform = ServerHostingPlatformContext::forOrg($org);
        if (! $platform->configured()) {
            Log::warning('warm_pool.create.platform_not_configured', ['org' => $org->id]);

            return null;
        }

        /** @var \App\Models\User|null $user */
        $user = $org->users()->first();
        if (! $user) {
            Log::warning('warm_pool.create.no_user', ['org' => $org->id]);

            return null;
        }

        $meta = $this->metaForTier($tier, $bucket);
        $meta['warm_pool'] = true;

        $signature = $tier === ServerPoolMember::TIER_STACK
            ? ServerPoolMember::signatureFor($meta)
            : null;

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => 'dply-warm-'.$region.'-'.$size.'-'.Str::lower(Str::random(6)),
            'provider' => ServerProvider::Hetzner,
            'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
            'provider_credential_id' => null,
            'region' => $region,
            'size' => $size,
            'meta' => $meta,
            'status' => Server::STATUS_PENDING,
        ]);

        $member = ServerPoolMember::create([
            'provider' => ServerProvider::Hetzner->value,
            'region' => $region,
            'size' => $size,
            'tier' => $tier,
            'stack_signature' => $signature,
            'server_id' => $server->id,
            'status' => ServerPoolMember::STATUS_WARMING,
        ]);

        Log::info('warm_pool.create.dispatched', [
            'member' => $member->id,
            'server' => $server->id,
            'region' => $region,
            'size' => $size,
            'tier' => $tier,
        ]);

        ProvisionHetznerServerJob::dispatch($server);

        return $member;
    }

    protected function poolOrganization(): ?Organization
    {
        $id = trim((string) config('warm_pool.pool_organization_id', ''));
        if ($id === '') {
            return null;
        }

        return Organization::query()->find($id);
    }

    /**
     * Build the stack meta for a member. For tier=stack, use the bucket's
     * install profile (default laravel_app) so a claim of that stack is instant.
     * For baseline, a minimal box (base packages + runtimes only).
     *
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    protected function metaForTier(string $tier, array $bucket): array
    {
        if ($tier !== ServerPoolMember::TIER_STACK) {
            return ['server_role' => 'plain', 'install_profile' => 'none'];
        }

        $stack = is_array($bucket['stack'] ?? null) ? $bucket['stack'] : [];
        $profileId = (string) ($bucket['install_profile'] ?? ($stack['install_profile'] ?? 'laravel_app'));
        $profile = collect((array) config('server_provision_options.install_profiles', []))
            ->firstWhere('id', $profileId) ?? [];

        return BuildServerProvisionMeta::run(
            $profileId,
            (string) ($stack['server_role'] ?? $profile['server_role'] ?? 'application'),
            (string) ($stack['cache_service'] ?? $profile['cache_service'] ?? 'redis'),
            (string) ($stack['webserver'] ?? $profile['webserver'] ?? 'nginx'),
            (string) ($stack['php_version'] ?? $profile['php_version'] ?? '8.3'),
            (string) ($stack['database'] ?? $profile['database'] ?? 'mysql84'),
        );
    }
}
