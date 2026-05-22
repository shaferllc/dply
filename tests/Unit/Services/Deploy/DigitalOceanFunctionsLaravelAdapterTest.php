<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy;

use App\Services\Deploy\DigitalOceanFunctionsLaravelAdapter;
use Tests\TestCase;

class DigitalOceanFunctionsLaravelAdapterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/do-fn-laravel-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['composer.json', 'index.php', 'artisan', 'bootstrap/app.php'] as $file) {
            @unlink($this->dir.'/'.$file);
        }
        @rmdir($this->dir.'/bootstrap');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_plan_detects_laravel_via_composer_json(): void
    {
        file_put_contents($this->dir.'/composer.json', json_encode([
            'require' => ['laravel/framework' => '^13.0'],
        ]));

        $plan = (new DigitalOceanFunctionsLaravelAdapter)->plan($this->dir);

        $this->assertTrue($plan['laravel']);
        $this->assertSame('index.php', $plan['handler']);
        $this->assertSame('main', $plan['function']);
    }

    public function test_plan_detects_laravel_via_artisan_and_bootstrap(): void
    {
        mkdir($this->dir.'/bootstrap');
        file_put_contents($this->dir.'/artisan', '#!/usr/bin/env php');
        file_put_contents($this->dir.'/bootstrap/app.php', '<?php');

        $this->assertTrue((new DigitalOceanFunctionsLaravelAdapter)->plan($this->dir)['laravel']);
    }

    public function test_plan_is_false_for_a_non_laravel_repo(): void
    {
        file_put_contents($this->dir.'/composer.json', json_encode([
            'require' => ['monolog/monolog' => '^3.0'],
        ]));

        $this->assertFalse((new DigitalOceanFunctionsLaravelAdapter)->plan($this->dir)['laravel']);
    }

    public function test_inject_writes_the_handler_into_a_laravel_repo(): void
    {
        file_put_contents($this->dir.'/composer.json', json_encode([
            'require' => ['laravel/framework' => '^13.0'],
        ]));

        $result = (new DigitalOceanFunctionsLaravelAdapter)->inject($this->dir);

        $this->assertTrue($result['ran']);
        $this->assertFileExists($this->dir.'/index.php');
        $handler = (string) file_get_contents($this->dir.'/index.php');
        $this->assertStringContainsString('function main(array $args)', $handler);
        $this->assertStringContainsString('__ow_method', $handler);
    }

    public function test_inject_is_a_no_op_for_a_non_laravel_repo(): void
    {
        file_put_contents($this->dir.'/composer.json', json_encode(['require' => []]));

        $result = (new DigitalOceanFunctionsLaravelAdapter)->inject($this->dir);

        $this->assertFalse($result['ran']);
        $this->assertFileDoesNotExist($this->dir.'/index.php');
    }

    public function test_the_handler_stub_is_syntactically_valid_php(): void
    {
        $stub = (new DigitalOceanFunctionsLaravelAdapter)->stubPath();
        $this->assertFileExists($stub);

        exec('php -l '.escapeshellarg($stub).' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
