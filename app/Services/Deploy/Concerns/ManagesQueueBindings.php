<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\Site;
use App\Models\SiteBinding;
use InvalidArgumentException;

/**
 * Attach the `queue` config binding (injects QUEUE_CONNECTION).
 */
trait ManagesQueueBindings
{
    /**
     * @param  array<string, mixed>  $params
     */
    private function attachQueue(Site $site, array $params): SiteBinding
    {
        $driver = strtolower(trim((string) ($params['driver'] ?? 'database')));
        if (! in_array($driver, ['database', 'redis'], true)) {
            throw new InvalidArgumentException(__('Queue driver must be database or redis.'));
        }
        $this->assertDriverDependency($site, __('the queue'), $driver);

        return $this->persist($site, 'queue', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'queue-'.$driver,
            'target_type' => 'queue_driver',
            'target_id' => null,
            'injected_env' => ['QUEUE_CONNECTION' => $driver],
            'config' => ['driver' => $driver],
        ]);
    }
}
