<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ValidationRules
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public function __construct(
        public array $rules
    ) {}
}
