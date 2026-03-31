<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TimeoutEnabled
{
    public function __construct(
        public bool $enabled = true
    ) {}
}
