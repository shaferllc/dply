<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

class EdgeFilesystemRegistrar
{
    /** @var array<string, true> */
    private array $registered = [];

    public function registerDisk(EdgeDeliveryContext $context): void
    {
        if ($context->isPlatform() || isset($this->registered[$context->diskName])) {
            return;
        }

        config([
            'filesystems.disks.'.$context->diskName => [
                'driver' => 's3',
                'key' => $context->r2AccessKey,
                'secret' => $context->r2Secret,
                'region' => 'auto',
                'bucket' => $context->r2Bucket,
                'endpoint' => $context->r2Endpoint,
                'use_path_style_endpoint' => true,
                'throw' => false,
                'report' => false,
            ],
        ]);

        $this->registered[$context->diskName] = true;
    }
}
