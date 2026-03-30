<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class WatermarkMode
{
    public function __construct(
        public string $mode = 'append'
    ) {}
}
