<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ValidationAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public array $attributes
    ) {}
}
