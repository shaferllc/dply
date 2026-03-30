<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ValidationMessages
{
    public function __construct(
        public array $messages
    ) {}
}
