<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\Site;
use App\Models\SiteBinding;
use InvalidArgumentException;

/**
 * Attach the `cache` config binding (injects CACHE_STORE / CACHE_DRIVER).
 */
trait ManagesCacheBindings
{
    /**
     * Pick the cache store Laravel should use. Like the queue binding this is a
     * driver choice rather than an attached resource — it injects CACHE_STORE
     * (and the legacy CACHE_DRIVER alias so pre-11 apps pick it up too). Redis
     * needs the Redis binding attached to supply the connection variables.
     *
     * @param  array<string, mixed> $params
     */
    private function attachCache(Site $site, array $params): SiteBinding
    {
        $driver = strtolower(trim((string) ($params['driver'] ?? 'database')));
        if (! in_array($driver, ['database', 'redis', 'file', 'array'], true)) {
            throw new InvalidArgumentException(__('Cache store must be database, redis, file, or array.'));
        }
        $this->assertDriverDependency($site, __('the cache store'), $driver);

        // Optional cache key prefix. Owned by the cache binding so it surfaces as
        // a managed CACHE_PREFIX row under the Cache resource rather than as a
        // loose editable variable. Left out when blank so the framework default
        // applies.
        $prefix = trim((string) ($params['prefix'] ?? ''));

        $injected = [
            'CACHE_STORE' => $driver,
            'CACHE_DRIVER' => $driver,
        ];
        if ($prefix !== '') {
            $injected['CACHE_PREFIX'] = $prefix;
        }

        return $this->persist($site, 'cache', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'cache-'.$driver,
            'target_type' => 'cache_driver',
            'target_id' => null,
            'injected_env' => $injected,
            // Persist the raw prefix so re-opening the modal prefills it.
            'config' => ['driver' => $driver, 'prefix' => $prefix],
        ]);
    }
}
