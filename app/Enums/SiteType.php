<?php

namespace App\Enums;

enum SiteType: string
{
    case Php = 'php';
    case Static = 'static';
    case Node = 'node';
    case Container = 'container';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Php => 'PHP (PHP-FPM)',
            self::Static => 'Static / HTML',
            self::Node => 'Node (reverse proxy)',
            self::Container => 'Container (image-based)',
            self::Custom => 'Custom',
        };
    }

    public function managesWebserver(): bool
    {
        return $this !== self::Custom;
    }

    public function requiresHostname(): bool
    {
        return $this !== self::Custom;
    }
}
