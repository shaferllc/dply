<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TraceName
{
    public function __construct(
        public string $name
    ) {}
}
