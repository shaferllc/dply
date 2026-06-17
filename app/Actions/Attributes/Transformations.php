<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Transformations
{
    /**
     * @param  array<string, mixed>  $transformations
     */
    public function __construct(
        public array $transformations
    ) {}
}
