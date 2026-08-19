<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;

/**
 * Default worker size: one step smaller than the app box when we know the
 * provider's ladder, otherwise the same slug the web server already uses.
 */
final class SiteWorkerFleetSize
{
    /**
     * Ascending size slugs per provider family. The worker default is the
     * previous rung (or the smallest when the app is already there).
     *
     * @var list<list<string>>
     */
    private const LADDERS = [
        [
            's-1vcpu-512mb-10gb',
            's-1vcpu-1gb',
            's-1vcpu-2gb',
            's-2vcpu-2gb',
            's-2vcpu-4gb',
            's-4vcpu-8gb',
            's-8vcpu-16gb',
        ],
        ['cx22', 'cx32', 'cx42', 'cx52'],
        ['cpx11', 'cpx21', 'cpx31', 'cpx41', 'cpx51'],
        ['ccx13', 'ccx23', 'ccx33', 'ccx43', 'ccx53'],
        ['g6-nanode-1', 'g6-standard-1', 'g6-standard-2', 'g6-standard-4'],
        ['vc2-1c-1gb', 'vc2-1c-2gb', 'vc2-2c-4gb', 'vc2-4c-8gb'],
        ['1xCPU-1GB', '1xCPU-2GB', '2xCPU-4GB', '4xCPU-8GB'],
        ['t3.micro', 't3.small', 't3.medium', 't3.large'],
        ['e2-micro', 'e2-small', 'e2-medium', 'e2-standard-2'],
        ['Standard_B1s', 'Standard_B1ms', 'Standard_B2s', 'Standard_B2ms'],
    ];

    public static function defaultFor(Server $app): string
    {
        $size = trim((string) $app->size);
        if ($size === '') {
            return 's-2vcpu-2gb';
        }

        foreach (self::LADDERS as $ladder) {
            $index = array_search($size, $ladder, true);
            if ($index === false) {
                continue;
            }

            return $ladder[max(0, $index - 1)];
        }

        return $size;
    }
}
