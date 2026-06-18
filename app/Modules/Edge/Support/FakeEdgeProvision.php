<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

class FakeEdgeProvision
{
    public static function enabled(): bool
    {
        if (! filter_var(config('edge.fake.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return in_array(app()->environment(), config('edge.fake.allowed_environments', []), true);
    }

    public static function storageRoot(): string
    {
        return (string) config('edge.fake.storage_root');
    }
}
