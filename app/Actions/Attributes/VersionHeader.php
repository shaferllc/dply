<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class VersionHeader
{
    public function __construct(
        public string $header = 'API-Version'
    ) {}
}
