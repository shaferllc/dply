<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TraceEnabled
{
    public function __construct(
        public bool $enabled = true
    ) {}
}
