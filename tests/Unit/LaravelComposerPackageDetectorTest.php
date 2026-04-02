<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Deploy\LaravelComposerPackageDetector;
use Tests\TestCase;

class LaravelComposerPackageDetectorTest extends TestCase
{
    public function test_flags_are_false_when_composer_is_null(): void
    {
        $d = new LaravelComposerPackageDetector;
        $flags = $d->flags(null);

        $this->assertFalse($flags['octane']);
        $this->assertFalse($flags['horizon']);
        $this->assertFalse($flags['pulse']);
        $this->assertFalse($flags['reverb']);
    }

    public function test_detects_packages_in_require_or_require_dev(): void
    {
        $d = new LaravelComposerPackageDetector;
        $flags = $d->flags([
            'require' => [
                'laravel/octane' => '^2.0',
                'laravel/horizon' => '^5.0',
            ],
            'require-dev' => [
                'laravel/pulse' => '^1.0',
                'laravel/reverb' => '^1.0',
            ],
        ]);

        $this->assertTrue($flags['octane']);
        $this->assertTrue($flags['horizon']);
        $this->assertTrue($flags['pulse']);
        $this->assertTrue($flags['reverb']);
    }
}
