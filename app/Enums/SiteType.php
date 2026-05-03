<?php

namespace App\Enums;

enum SiteType: string
{
    case Php = 'php';
    case Static = 'static';
    case Node = 'node';
    case Container = 'container';

    public function label(): string
    {
        return match ($this) {
            self::Php => 'PHP (PHP-FPM)',
            self::Static => 'Static / HTML',
            self::Node => 'Node (reverse proxy)',
            self::Container => 'Container (image-based)',
        };
    }
}
