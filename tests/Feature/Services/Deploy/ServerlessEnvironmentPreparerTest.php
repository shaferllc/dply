<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy;

use App\Models\Site;
use App\Services\Deploy\ServerlessEnvironmentPreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ServerlessEnvironmentPreparerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/serverless-env-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_it_seeds_managed_env_from_the_repo_and_mints_an_app_key(): void
    {
        File::put($this->dir.'/.env', "APP_ENV=production\nLOG_CHANNEL=stderr\n");
        $site = Site::factory()->create(['env_file_content' => null]);

        (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

        $managed = (string) $site->fresh()->env_file_content;
        $this->assertStringContainsString('APP_ENV=production', $managed);
        $this->assertMatchesRegularExpression('/APP_KEY=base64:.+/', $managed);

        // The artifact's .env carries the managed environment.
        $this->assertStringContainsString('APP_KEY=base64:', (string) file_get_contents($this->dir.'/.env'));
    }

    public function test_it_injects_the_command_secret_for_background_ticks(): void
    {
        $site = Site::factory()->create(['env_file_content' => null]);

        (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

        $managed = (string) $site->fresh()->env_file_content;
        $this->assertStringContainsString('DPLY_COMMAND_SECRET='.$site->webhook_secret, $managed);
    }

    public function test_it_keeps_an_existing_app_key(): void
    {
        $existing = "APP_ENV=production\nAPP_KEY=base64:keepme0000000000000000000000000000000000000=\n";
        $site = Site::factory()->create(['env_file_content' => $existing]);

        (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

        $managed = (string) $site->fresh()->env_file_content;
        $this->assertSame(1, substr_count($managed, 'APP_KEY='));
        $this->assertStringContainsString('APP_KEY=base64:keepme', $managed);
    }

    public function test_a_non_laravel_function_gets_no_app_key(): void
    {
        File::put($this->dir.'/.env', "PORT=3000\n");
        $site = Site::factory()->create(['env_file_content' => null]);

        (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, false);

        $managed = (string) $site->fresh()->env_file_content;
        $this->assertStringContainsString('PORT=3000', $managed);
        $this->assertStringNotContainsString('APP_KEY', $managed);
    }

    public function test_managed_env_is_authoritative_over_the_repo_env(): void
    {
        File::put($this->dir.'/.env', "FROM_REPO=1\n");
        $site = Site::factory()->create([
            'env_file_content' => "APP_KEY=base64:set\nMANAGED=1\n",
        ]);

        (new ServerlessEnvironmentPreparer)->prepare($site, $this->dir, true);

        $built = (string) file_get_contents($this->dir.'/.env');
        $this->assertStringContainsString('MANAGED=1', $built);
        $this->assertStringNotContainsString('FROM_REPO', $built);
    }
}
