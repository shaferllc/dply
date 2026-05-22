<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetSiteProcessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_process_with_defaults(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:process-set', [
            'site' => $site->slug,
            'name' => 'queue',
            '--command' => 'php artisan queue:work',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('create', $decoded['action']);
        $this->assertSame(SiteProcess::TYPE_WORKER, $decoded['process']['type']);
        $this->assertSame(1, $decoded['process']['scale']);
        $this->assertTrue($decoded['process']['is_active']);
        $this->assertSame('php artisan queue:work', $decoded['process']['command']);
    }

    public function test_updates_existing_process_in_place(): void
    {
        $site = $this->makeSite();
        $existing = $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'queue',
            'command' => 'old',
            'scale' => 1,
            'is_active' => true,
        ]);

        Artisan::call('dply:site:process-set', [
            'site' => $site->slug,
            'name' => 'queue',
            '--command' => 'new',
            '--scale' => '3',
            '--active' => 'false',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('update', $decoded['action']);
        $this->assertSame($existing->id, $decoded['process']['id']);
        $this->assertSame('new', $decoded['process']['command']);
        $this->assertSame(3, $decoded['process']['scale']);
        $this->assertFalse($decoded['process']['is_active']);
    }

    public function test_rejects_invalid_type(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:process-set', [
            'site' => $site->slug,
            'name' => 'queue',
            '--type' => 'daemon',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid type', $output);
    }

    public function test_rejects_invalid_scale(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:process-set', [
            'site' => $site->slug,
            'name' => 'queue',
            '--scale' => '-1',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid scale', $output);
    }

    public function test_rejects_invalid_active_value(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:process-set', [
            'site' => $site->slug,
            'name' => 'queue',
            '--active' => 'maybe',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--active must be', $output);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $site = $this->makeSite();

        Artisan::call('dply:site:process-set', [
            'site' => $site->slug,
            'name' => 'queue',
            '--command' => 'foo',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertNull($site->processes()->where('name', 'queue')->first());
    }

    public function test_update_with_no_changes_fails(): void
    {
        $site = $this->makeSite();
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'queue',
            'command' => 'foo',
            'scale' => 1,
            'is_active' => true,
        ]);

        $exit = Artisan::call('dply:site:process-set', [
            'site' => $site->slug,
            'name' => 'queue',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No changes requested', $output);
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
