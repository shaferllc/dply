<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TimeoutSeconds
{
    public function __construct(
        public int $seconds = 300
    ) {}
}
