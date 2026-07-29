<?php

namespace App\Services\WorkerPools;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Cloud\Services\HetznerService;
use App\Modules\Cloud\Services\LinodeService;
use App\Modules\Cloud\Services\UpCloudService;
use App\Modules\Cloud\Services\VultrService;
use App\Support\Servers\ServerHostingPlatformContext;
use Throwable;

/**
 * Confirms whether a worker-pool member's underlying provider instance still
 * exists. dply otherwise trusts the DB row forever: when a box is destroyed at
 * the provider its public IP gets recycled to a stranger's machine, and the
 * member sits as a zombie — SSH silently fails (or worse, talks to the wrong
 * box) while the UI still shows it "active".
 *
 * Deliberately conservative: returns false ONLY on a definitive provider
 * "not found", true on a successful lookup, and null for anything ambiguous
 * (transient API error, unsupported provider, missing credential). Callers must
 * treat null as "don't act" so a flaky API never tears down a healthy member.
 */
class WorkerMemberProviderProbe
{
    /**
     * @return bool|null true = instance exists, false = confirmed gone,
     *                   null = unknown (do not act on this)
     */
    public function instanceExists(Server $member): ?bool
    {
        $id = trim((string) $member->provider_id);
        if ($id === '') {
            return null; // never got a provider id — nothing to probe
        }

        try {
            return match ($member->provider) {
                ServerProvider::Hetzner => $this->hetznerExists($member, $id),
                ServerProvider::DigitalOcean => $this->lookup(fn () => (new DigitalOceanService($member->providerCredential))->getDroplet((int) $id), $member),
                ServerProvider::Vultr => $this->lookup(fn () => (new VultrService($member->providerCredential))->getInstance($id), $member),
                ServerProvider::Linode => $this->lookup(fn () => (new LinodeService($member->providerCredential))->getInstance((int) $id), $member),
                ServerProvider::UpCloud => $this->lookup(fn () => (new UpCloudService($member->providerCredential))->getServer($id), $member),
                // AWS / Azure / Oracle: no simple existence lookup wired yet.
                default => null,
            };
        } catch (Throwable $e) {
            return $this->classify($e);
        }
    }

    private function hetznerExists(Server $member, string $id): ?bool
    {
        // Managed boxes carry no providerCredential — their token comes from the
        // platform context (which also routes beta orgs to the isolated project).
        if ($member->usesManagedHosting()) {
            $org = $member->organization;
            if ($org === null) {
                return null;
            }
            $platform = ServerHostingPlatformContext::forOrg($org);
            if (! $platform->configured()) {
                return null;
            }
            $service = $platform->hetzner();
        } elseif ($member->providerCredential !== null) {
            $service = new HetznerService($member->providerCredential);
        } else {
            return null;
        }

        return $this->lookup(fn () => $service->getInstance((int) $id), $member);
    }

    /**
     * Run a provider lookup, returning true on success or classifying the error.
     *
     * @param  callable():mixed  $call
     */
    private function lookup(callable $call, Server $member): ?bool
    {
        if ($member->providerCredential === null && ! $member->usesManagedHosting()) {
            return null;
        }

        try {
            $call();

            return true;
        } catch (Throwable $e) {
            return $this->classify($e);
        }
    }

    /** Map a provider error to: false (gone) only when it clearly means not-found. */
    private function classify(Throwable $e): ?bool
    {
        $msg = strtolower($e->getMessage());

        foreach (['not found', '404', 'does not exist', 'no such server', 'no server with'] as $needle) {
            if (str_contains($msg, $needle)) {
                return false;
            }
        }

        return null; // transient / unknown — caller leaves the member alone
    }
}
