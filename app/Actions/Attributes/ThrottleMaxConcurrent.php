<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ThrottleMaxConcurrent
{
    public function __construct(
        public int $max = 5
    ) {}
}
