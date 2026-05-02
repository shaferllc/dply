<?php

declare(strict_types=1);

namespace App\Services\Deploy\Support;

final class ProvisionerConfigReport
{
    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function safeConfigKeys(array $config): array
    {
        $keys = [];

        foreach (array_keys($config) as $key) {
            if ($key === 'credentials') {
                continue;
            }

            $keys[] = $key;
        }

        if (isset($config['credentials']) && is_array($config['credentials']) && $config['credentials'] !== []) {
            $keys[] = 'credentials_present';
        }

        sort($keys);

        return array_values(array_unique($keys));
    }
}
