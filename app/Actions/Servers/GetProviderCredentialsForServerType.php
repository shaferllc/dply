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
     * @return Collection<int, ProviderCredential>
     */
    public function handle(Organization $org, string $type): Collection
    {
        $provider = ServerProviderTypeMap::toCredentialProvider($type);
        if ($provider === null) {
            return collect();
        }

        return ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', $provider)
            ->orderBy('name')
            ->get();
    }
}
