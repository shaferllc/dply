<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Services;

use App\Models\ProviderCredential;

class AwsEc2ServiceFactory
{
    public function make(ProviderCredential $credential, ?string $region = null): AwsEc2Service
    {
        return new AwsEc2Service($credential, $region);
    }
}
