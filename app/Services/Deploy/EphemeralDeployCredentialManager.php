<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploymentEphemeralCredential;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use App\Support\Ssh\EphemeralSshKeyGenerator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class EphemeralDeployCredentialManager
{
    public function __construct(
        protected EphemeralSshKeyGenerator $keyGenerator,
        protected ServerAuthorizedKeysSynchronizer $synchronizer,
        protected EphemeralDeployCredentialContext $context,
    ) {}

    public function shouldUseForSite(Site $site): bool
    {
        $site->loadMissing('server.organization');

        if (! $site->server?->isVmHost() || ! $site->server->isReady()) {
            return false;
        }

        if (! ephemeral_deploy_credentials_active($site->organization)) {
            return false;
        }

        return $site->usesEphemeralDeployCredentials();
    }

    public function provision(Site $site, SiteDeployment $deployment): SiteDeploymentEphemeralCredential
    {
        $server = $site->server;
        if ($server === null) {
            throw new RuntimeException('Site has no server for ephemeral deploy credential provisioning.');
        }

        $organization = $site->organization;
        if ($organization === null) {
            throw new RuntimeException('Site has no organization for ephemeral deploy credential provisioning.');
        }

        [$privateOpenSsh, $publicOpenSsh, $fingerprint] = $this->keyGenerator->generate(
            $this->keyLabel($deployment),
        );

        $credential = SiteDeploymentEphemeralCredential::query()->create([
            'site_deployment_id' => $deployment->id,
            'organization_id' => $organization->id,
            'server_id' => $server->id,
            'public_key_fingerprint' => $fingerprint,
            'private_key_encrypted' => Crypt::encryptString($privateOpenSsh),
            'provisioned_at' => now(),
        ]);

        $authorizedKey = ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'target_linux_user' => '',
            'managed_key_type' => SiteDeploymentEphemeralCredential::class,
            'managed_key_id' => $credential->id,
            'name' => $this->keyName($deployment),
            'public_key' => trim($publicOpenSsh),
        ]);

        $credential->update(['server_authorized_key_id' => $authorizedKey->id]);

        $this->synchronizer->sync($server);

        audit_log($organization, null, 'site.deploy.ephemeral_credential_provisioned', $credential, null, [
            'site_id' => (string) $site->id,
            'site' => $site->name,
            'deployment_id' => (string) $deployment->id,
            'server_id' => (string) $server->id,
            'fingerprint' => $fingerprint,
        ]);

        return $credential->fresh(['serverAuthorizedKey']);
    }

    public function activateForDeploy(SiteDeploymentEphemeralCredential $credential): void
    {
        $this->context->setPrivateKey(Crypt::decryptString($credential->private_key_encrypted));
    }

    public function revoke(SiteDeploymentEphemeralCredential $credential): void
    {
        if ($credential->isRevoked()) {
            return;
        }

        $this->context->clear();

        $credential->loadMissing(['server.organization', 'siteDeployment.site']);

        if ($credential->server_authorized_key_id !== null) {
            ServerAuthorizedKey::query()
                ->whereKey($credential->server_authorized_key_id)
                ->delete();
        }

        $server = $credential->server;
        if ($server instanceof Server && $server->isReady()) {
            try {
                $this->synchronizer->sync($server);
            } catch (\Throwable $e) {
                Log::warning('Failed to sync authorized keys after ephemeral deploy credential revoke', [
                    'credential_id' => $credential->id,
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $credential->update(['revoked_at' => now()]);

        $organization = $credential->organization;
        if ($organization instanceof Organization) {
            $site = $credential->siteDeployment?->site;
            audit_log($organization, null, 'site.deploy.ephemeral_credential_revoked', $credential->fresh(), null, [
                'site_id' => $site ? (string) $site->id : null,
                'site' => $site?->name,
                'deployment_id' => (string) $credential->site_deployment_id,
                'server_id' => (string) $credential->server_id,
                'fingerprint' => $credential->public_key_fingerprint,
            ]);
        }
    }

    protected function keyLabel(SiteDeployment $deployment): string
    {
        return 'dply-ephemeral-deploy-'.Str::lower(Str::substr($deployment->id, -10));
    }

    protected function keyName(SiteDeployment $deployment): string
    {
        return 'dply-ephemeral-deploy-'.Str::lower(Str::substr($deployment->id, -8));
    }
}
