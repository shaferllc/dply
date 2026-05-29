<?php

declare(strict_types=1);

namespace App\Support\ProductLine;

use App\Models\Site;

final class ProductLineKillSwitches
{
    public static function vmEnabled(): bool
    {
        return (bool) config('features.global.vm_enabled', true);
    }

    public static function edgeDeliveryEnabled(): bool
    {
        return (bool) config('features.global.edge_delivery_enabled', true);
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
