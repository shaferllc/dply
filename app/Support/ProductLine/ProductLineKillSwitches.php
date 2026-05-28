<?php

declare(strict_types=1);

namespace App\Support\ProductLine;

use App\Models\Site;
use Laravel\Pennant\Feature;

final class ProductLineKillSwitches
{
    public static function vmEnabled(): bool
    {
        return Feature::for(null)->active('global.vm_enabled');
    }

    public static function edgeDeliveryEnabled(): bool
    {
        return Feature::for(null)->active('global.edge_delivery_enabled');
    }

    public static function siteIsVmByo(Site $site): bool
    {
        if ($site->usesEdgeRuntime()) {
            return false;
        }

        if ($site->usesFunctionsRuntime()) {
            return false;
        }

        if ($site->usesContainerRuntime()) {
            return false;
        }

        if ($site->usesDockerRuntime()) {
            return false;
        }

        if ($site->usesKubernetesRuntime()) {
            return false;
        }

        return true;
    }

    public static function blocksVmSiteDeploy(Site $site): bool
    {
        return self::siteIsVmByo($site) && ! self::vmEnabled();
    }

    public static function blocksVmServerCreate(): bool
    {
        return ! self::vmEnabled();
    }

    public static function blocksEdgeDelivery(): bool
    {
        return ! self::edgeDeliveryEnabled();
    }
}
