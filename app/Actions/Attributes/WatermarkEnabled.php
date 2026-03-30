<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class WatermarkEnabled
{
    public function __construct(
        public bool $enabled = true
    ) {}
}
