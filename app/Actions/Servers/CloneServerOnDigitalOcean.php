<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\ServerProvider;
use App\Jobs\CloneServerOnDigitalOceanJob;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * DB-clone + DigitalOcean droplet snapshot path. Validates the source is
 * cloneable, creates a draft Server row in PROVISIONING status with the
 * dply-side configuration copied over, seeds a `clone_server` ConsoleAction so
 * the banner picks up the in-flight clone immediately, and dispatches the job
 * that orchestrates the DO API calls.
 *
 * Eligibility:
 *  - Source must be ready (no clone of in-flight or errored servers).
 *  - Source's provider must be DigitalOcean (this is the only path wired today).
 *  - Source must have a host_kind of `vm` — managed-Kubernetes / App Platform /
 *    Lambda / Functions hosts can't be cloned via droplet snapshot.
 *  - Source must have a provider_credential and provider_id (droplet ID) on file.
 *
 * What's cloned (DB side):
 *  - name (with "(clone)" suffix unless overridden by the caller)
 *  - workspace_id, organization_id, user_id
 *  - provider, provider_credential_id, region, size
 *  - meta keys that represent operator intent: preset_key, runtime_defaults,
 *    default_php_version, manage_auto_updates_interval, host_kind, digitalocean
 *    options (ipv6, monitoring, vpc_uuid, tags, backups). Probe-derived meta
 *    (manage_*, inventory_*, ssh_login_*) is intentionally NOT carried over —
 *    the cloned droplet re-probes from its own state.
 *
 * What's NOT cloned:
 *  - ip_address, provider_id (set after the new droplet boots).
 *  - SSH keys (fresh keys generated for the clone — even though the snapshot
 *    contains the source's authorized_keys, dply rotates so audit trails stay
 *    distinct per-server).
 *  - Sites, databases, console history, activity log — those go through their
 *    normal lifecycle on the cloned box.
 */
final class CloneServerOnDigitalOcean
{
    /**
     * @param  array{name?: string, region?: string, size?: string}  $overrides
     */
    public function handle(User $actor, Organization $org, Server $source, array $overrides = []): Server
    {
        $this->assertCloneable($source, $org);

        $name = isset($overrides['name']) && is_string($overrides['name']) && trim($overrides['name']) !== ''
            ? trim($overrides['name'])
            : $source->name.' (clone)';
        $region = isset($overrides['region']) && is_string($overrides['region']) && $overrides['region'] !== ''
            ? $overrides['region']
            : (string) $source->region;
        $size = isset($overrides['size']) && is_string($overrides['size']) && $overrides['size'] !== ''
            ? $overrides['size']
            : (string) $source->size;

        if ($region === '' || $size === '') {
            throw ValidationException::withMessages([
                'source' => __('Source server is missing a region or size; cannot clone.'),
            ]);
        }

        $clonedMeta = $this->cloneableMeta($source);

        $clone = $actor->servers()->create([
            'organization_id' => $org->id,
            'name' => $name,
            'provider' => ServerProvider::DigitalOcean,
            'provider_credential_id' => $source->provider_credential_id,
            'region' => $region,
            'size' => $size,
            'meta' => $clonedMeta,
            'status' => Server::STATUS_PROVISIONING,
        ]);

        $this->seedCloneConsoleAction($clone, $source);

        CloneServerOnDigitalOceanJob::dispatch(
            sourceServerId: (string) $source->id,
            cloneServerId: (string) $clone->id,
            snapshotName: $this->buildSnapshotName($source, $clone),
        );

        audit_log($org, $actor, 'server.cloned', $clone, [
            'source_server_id' => (string) $source->id,
        ]);

        return $clone;
    }

    protected function assertCloneable(Server $source, Organization $org): void
    {
        if ($source->organization_id !== $org->id) {
            throw ValidationException::withMessages([
                'source' => __('Source server belongs to a different organization.'),
            ]);
        }

        if ($source->provider !== ServerProvider::DigitalOcean) {
            throw ValidationException::withMessages([
                'source' => __('Cloning is only supported for DigitalOcean droplets today.'),
            ]);
        }

        $hostKind = (string) (($source->meta ?? [])['host_kind'] ?? Server::HOST_KIND_VM);
        if ($hostKind !== Server::HOST_KIND_VM) {
            throw ValidationException::withMessages([
                'source' => __('Only VM-kind servers can be snapshot-cloned. Managed Kubernetes / App Platform / serverless hosts can\'t be duplicated this way.'),
            ]);
        }

        if (! $source->providerCredential || $source->providerCredential->provider !== 'digitalocean') {
            throw ValidationException::withMessages([
                'source' => __('Source server has no DigitalOcean credential on file.'),
            ]);
        }

        if ($source->provider_id === null || $source->provider_id === '') {
            throw ValidationException::withMessages([
                'source' => __('Source server has no droplet ID — nothing to snapshot from.'),
            ]);
        }

        if ($source->status !== Server::STATUS_READY) {
            throw ValidationException::withMessages([
                'source' => __('Source server must be ready before it can be cloned (status is :status).', [
                    'status' => (string) $source->status,
                ]),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function cloneableMeta(Server $source): array
    {
        $sourceMeta = is_array($source->meta) ? $source->meta : [];

        // Allowlist the keys that represent operator intent. Probe / state /
        // per-deploy keys are intentionally absent so the clone starts with a
        // fresh inventory snapshot of its own.
        $keep = [
            'host_kind',
            'preset_key',
            'runtime_defaults',
            'default_php_version',
            'manage_auto_updates_interval',
            'digitalocean',
            'tags',
        ];

        $cloned = [];
        foreach ($keep as $key) {
            if (array_key_exists($key, $sourceMeta)) {
                $cloned[$key] = $sourceMeta[$key];
            }
        }

        // Mark provenance so the cloned server's UI and audit log can show
        // "cloned from X" once we wire that up.
        $cloned['cloned_from_server_id'] = (string) $source->id;
        $cloned['cloned_at'] = now()->toIso8601String();

        return $cloned;
    }

    protected function buildSnapshotName(Server $source, Server $clone): string
    {
        // DO snapshot names allow letters/digits/.-_; keep it short and stable
        // so the polling loop can find the snapshot by name after completion.
        $stamp = now()->format('Ymd-His');
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $source->name) ?: 'server';
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            $slug = 'server';
        }

        return 'dply-clone-'.$slug.'-'.$stamp;
    }

    protected function seedCloneConsoleAction(Server $clone, Server $source): void
    {
        try {
            ConsoleAction::query()->create([
                'subject_type' => $clone->getMorphClass(),
                'subject_id' => $clone->id,
                'kind' => 'clone_server',
                'status' => ConsoleAction::STATUS_QUEUED,
                'user_id' => auth()->id(),
                'label' => __('Cloning :source …', ['source' => $source->name]),
                'output' => [
                    'v' => (int) config('console_actions.current_version', 1),
                    'lines' => [],
                    'meta' => [
                        'source_server_id' => (string) $source->id,
                        'source_name' => $source->name,
                    ],
                ],
            ]);
        } catch (\Throwable) {
            // Banner is a nice-to-have; never block the clone on it.
        }
    }
}
