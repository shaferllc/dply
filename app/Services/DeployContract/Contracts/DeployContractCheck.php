<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Contracts;

use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;

interface DeployContractCheck
{
    public function key(): string;

    public function label(): string;

    public function engine(): string;

    public function evaluate(DeployContractContext $context): DeployContractCheckResult;
}
