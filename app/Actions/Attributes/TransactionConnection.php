<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TransactionConnection
{
    public function __construct(
        public string $connection
    ) {}
}
