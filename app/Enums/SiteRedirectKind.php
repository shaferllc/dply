<?php

namespace App\Enums;

enum SiteRedirectKind: string
{
    case Http = 'http';
    case InternalRewrite = 'internal_rewrite';

    public function label(): string
    {
        return match ($this) {
            self::Http => __('HTTP redirect (3xx)'),
            self::InternalRewrite => __('Internal rewrite'),
        };
    }
}
