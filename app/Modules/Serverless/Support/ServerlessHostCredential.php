<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * Resolves which org credential a BYO functions host should use to create
 * its namespace. A host can sit on a token that already 401s — retry must
 * not keep POSTing that token when a healthy sibling exists.
 */
final class ServerlessHostCredential
{
    public const UNUSABLE_MESSAGE = 'This credential can’t connect — add or pick a working one under /credentials.';

    /**
     * Credential to provision with. Remaps the host (and its function sites)
     * onto the newest healthy org DigitalOcean credential when the attached
     * row is known-rejected. Returns null when none can be used.
     */
    public static function resolveForProvision(Server $server): ?ProviderCredential
    {
        $attached = $server->providerCredential;

        if ($attached !== null && ! $attached->isUnhealthy()) {
            return $attached;
        }

        $healthy = ProviderCredential::newestHealthyForOrganization(
            (string) $server->organization_id,
            'digitalocean',
        );

        if ($healthy === null || ($attached !== null && $healthy->is($attached))) {
            return null;
        }

        self::attach($server, $healthy);

        Log::info('serverless.namespace.remapped_credential', [
            'server_id' => $server->id,
            'from' => $attached?->id,
            'to' => $healthy->id,
            'to_name' => $healthy->name,
        ]);

        return $healthy;
    }

    public static function attach(Server $server, ProviderCredential $credential): void
    {
        $server->update(['provider_credential_id' => $credential->id]);
        $server->setRelation('providerCredential', $credential);

        Site::query()
            ->where('server_id', $server->id)
            ->update(['serverless_provider_credential_id' => $credential->id]);
    }
}
