<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Actions\Servers\Support\ServerProviderTypeMap;
use App\Models\Organization;
use App\Models\ProviderCredential;
use Illuminate\Support\Collection;

final class GetProviderCredentialsForServerType
{
    use AsObject;

    /**
     * Cross-call memo keyed by `{organization_id}:{credential_provider}` so mount,
     * catalog resolution, and sync helpers share one query per org + provider.
     *
     * @var array<string, Collection<int, ProviderCredential>>
     */
    private static array $memo = [];

    /**
     * @return Collection<int, ProviderCredential>
     */
    public function handle(Organization $org, string $type): Collection
    {
        $provider = ServerProviderTypeMap::toCredentialProvider($type);
        if ($provider === null) {
            return collect();
        }

        $key = (string) $org->getKey().':'.$provider;

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        return self::$memo[$key] = ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get();
    }

    public static function forgetOrganizationProvider(string $organizationId, string $provider): void
    {
        unset(self::$memo[$organizationId.':'.$provider]);
    }

    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
