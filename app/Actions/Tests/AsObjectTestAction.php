<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;

class AsObjectTestAction extends Actions
{
    public string $commandSignature = 'test:object-action';

    public function handle(string $value): string
    {
        return strtoupper($value);
    }
}
