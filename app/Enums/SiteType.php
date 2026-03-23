<?php

namespace App\Enums;

enum SiteType: string
{
    case Php = 'php';
    case Static = 'static';
    case Node = 'node';

    public function label(): string
    {
        return match ($this) {
            self::Php => 'PHP (PHP-FPM)',
            self::Static => 'Static / HTML',
            self::Node => 'Node (reverse proxy)',
        };
    }
}
