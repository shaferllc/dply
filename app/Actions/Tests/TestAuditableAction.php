<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsAuditable;

class TestAuditableAction extends Actions
{
    use AsAuditable;

    public function handle(string $data): string
    {
        return "processed: {$data}";
    }
}
