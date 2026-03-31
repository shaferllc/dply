<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ValidationAttributes
{
    public function __construct(
        public array $attributes
    ) {}
}
