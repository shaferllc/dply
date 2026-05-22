<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RemoveSiteProcessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_a_process_by_name(): void
    {
        $site = $this->makeSite();
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'scale' => 1,
            'is_active' => true,
        ]);

        $exit = Artisan::call('dply:site:process-remove', [
            'site' => $site->slug,
            'name' => 'queue',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('queue', $decoded['removed']);
        $this->assertNull($site->processes()->where('name', 'queue')->first());
    }

    public function test_refuses_to_remove_web_without_force(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:process-remove', [
            'site' => $site->slug,
            'name' => 'web',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing to remove', $output);
        $this->assertNotNull($site->processes()->where('name', 'web')->first());
    }

    public function test_force_removes_web(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:process-remove', [
            'site' => $site->slug,
            'name' => 'web',
            '--force' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertNull($site->processes()->where('name', 'web')->first());
    }

    public function test_fails_when_process_not_found(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:process-remove', [
            'site' => $site->slug,
            'name' => 'nonexistent',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Process not found', $output);
    }

    public function test_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:process-remove', [
            'site' => 'nope',
            'name' => 'web',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    private function makeSite(): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ]);
    }
}
