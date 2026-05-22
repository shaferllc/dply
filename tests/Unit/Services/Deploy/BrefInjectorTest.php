<?php

namespace Tests\Unit\Services\Deploy;

use App\Services\Deploy\BrefInjector;
use PHPUnit\Framework\TestCase;

class BrefInjectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bref-injector-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/composer.json');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeComposer(array $composer): void
    {
        file_put_contents($this->dir.'/composer.json', json_encode($composer));
    }

    public function test_laravel_app_plans_bref_and_the_laravel_bridge(): void
    {
        $this->writeComposer(['require' => ['laravel/framework' => '^12.0', 'php' => '^8.3']]);

        $plan = (new BrefInjector)->plan($this->dir);

        $this->assertTrue($plan['php']);
        $this->assertSame('laravel', $plan['framework']);
        $this->assertSame(['bref/bref', 'bref/laravel-bridge'], $plan['packages']);
        $this->assertSame('public/index.php', $plan['handler']);
    }

    public function test_plain_php_app_plans_only_bref(): void
    {
        $this->writeComposer(['require' => ['php' => '^8.3', 'guzzlehttp/guzzle' => '^7.0']]);

        $plan = (new BrefInjector)->plan($this->dir);

        $this->assertSame('php', $plan['framework']);
        $this->assertSame(['bref/bref'], $plan['packages']);
    }

    public function test_directory_without_composer_json_is_not_php(): void
    {
        $plan = (new BrefInjector)->plan($this->dir);

        $this->assertFalse($plan['php']);
        $this->assertSame([], $plan['packages']);
    }

    public function test_already_present_bref_packages_are_not_re_added(): void
    {
        $this->writeComposer(['require' => [
            'laravel/framework' => '^12.0',
            'bref/bref' => '^2.1',
            'bref/laravel-bridge' => '^2.0',
        ]]);

        $plan = (new BrefInjector)->plan($this->dir);

        $this->assertSame([], $plan['packages']);
    }

    public function test_laravel_app_already_carrying_base_bref_still_gets_the_bridge(): void
    {
        $this->writeComposer(['require' => [
            'laravel/framework' => '^12.0',
            'bref/bref' => '^2.1',
        ]]);

        $plan = (new BrefInjector)->plan($this->dir);

        $this->assertSame(['bref/laravel-bridge'], $plan['packages']);
    }

    public function test_inject_is_a_no_op_when_nothing_to_add(): void
    {
        $this->writeComposer(['require' => [
            'laravel/framework' => '^12.0',
            'bref/bref' => '^2.1',
            'bref/laravel-bridge' => '^2.0',
        ]]);

        $result = (new BrefInjector)->inject($this->dir);

        $this->assertFalse($result['ran']);
    }
}
