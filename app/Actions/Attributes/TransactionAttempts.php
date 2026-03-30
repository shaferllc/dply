<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TransactionAttempts
{
    public function __construct(
        public int $attempts = 1
    ) {}
}
