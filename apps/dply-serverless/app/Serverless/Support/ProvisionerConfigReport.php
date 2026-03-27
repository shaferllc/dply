<?php

namespace App\Serverless\Support;

/**
 * Shapes {@see ServerlessFunctionProvisioner} metadata so secrets are not listed in provisioner_output.
 */
final class ProvisionerConfigReport
{
    /**
     * @return list<string>
     */
    public static function safeConfigKeys(array $config): array
    {
        $keys = [];
        foreach (array_keys($config) as $k) {
            if ($k === 'credentials') {
                continue;
            }
            $keys[] = $k;
        }

        if (isset($config['credentials']) && is_array($config['credentials']) && $config['credentials'] !== []) {
            $keys[] = 'credentials_present';
        }

        sort($keys);

        return array_values(array_unique($keys));
    }
}
