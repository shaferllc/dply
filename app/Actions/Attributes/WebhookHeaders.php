<?php

namespace App\Actions\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class WebhookHeaders
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public array $headers
    ) {}
}
