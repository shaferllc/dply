<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\OrganizationSecret;
use App\Models\Site;

/**
 * Decrypts linked org vault secrets for deploy / Cloud spec writes.
 * Do not call from Livewire render paths.
 *
 * @see docs/ORG_SHARED_SECRETS.md
 */
final class LinkedOrganizationSecrets
{
    /**
     * Request-scoped memo keyed by site id.
     *
     * The docblock above says not to call this from a render path, but
     * BuildsSiteBindingFormDefaults does — twice, seeding mail and cache binding
     * defaults — so a single site page ran this join-with-subquery three times at
     * ~2ms each. Memoising is the cheaper fix than unpicking those call sites,
     * and matches GetProviderCredentialsForServerType.
     *
     * @var array<string, array<string, string>>
     */
    private static array $memo = [];

    /**
     * @return array<string, string>
     */
    public function valuesForSite(Site $site): array
    {
        $key = (string) $site->getKey();
        if ($key !== '' && array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $secrets = OrganizationSecret::query()
            ->whereIn(
                'id',
                $site->organizationSecrets()->select('organization_secrets.id'),
            )
            ->get();

        $values = [];
        foreach ($secrets as $secret) {
            $values[$secret->key] = (string) $secret->value;
        }

        return $key !== '' ? self::$memo[$key] = $values : $values;
    }

    /** Drop the memo — after linking/unlinking a secret, and between tests. */
    public static function flushMemo(?string $siteId = null): void
    {
        if ($siteId === null) {
            self::$memo = [];

            return;
        }

        unset(self::$memo[$siteId]);
    }

    /**
     * Merge linked secret values under a higher-priority map (later keys win).
     *
     * @param  array<string, string>  $over
     * @return array<string, string>
     */
    public function mergeUnder(array $over, Site $site): array
    {
        return array_merge($this->valuesForSite($site), $over);
    }
}
