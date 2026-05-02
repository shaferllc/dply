<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FleetAgeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_all_servers_oldest_first(): void
    {
        $old = Server::factory()->create(['name' => 'old-server', 'created_at' => now()->subDays(400)]);
        $new = Server::factory()->create(['name' => 'new-server', 'created_at' => now()->subDays(10)]);

        Artisan::call('dply:fleet:age', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(2, $decoded['count']);
        $this->assertSame('old-server', $decoded['servers'][0]['server_name']);
        $this->assertSame('new-server', $decoded['servers'][1]['server_name']);
        $this->assertGreaterThanOrEqual(400, $decoded['servers'][0]['age_days']);
    }

    public function test_older_than_filter(): void
    {
        Server::factory()->create(['name' => 'older', 'created_at' => now()->subDays(400)]);
        Server::factory()->create(['name' => 'newer', 'created_at' => now()->subDays(10)]);

        Artisan::call('dply:fleet:age', [
            '--older-than' => 100,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $this->assertSame('older', $decoded['servers'][0]['server_name']);
    }

    public function test_younger_than_filter(): void
    {
        Server::factory()->create(['name' => 'older', 'created_at' => now()->subDays(400)]);
        Server::factory()->create(['name' => 'newer', 'created_at' => now()->subDays(10)]);

        Artisan::call('dply:fleet:age', [
            '--younger-than' => 30,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $this->assertSame('newer', $decoded['servers'][0]['server_name']);
    }

    public function test_combined_filters_form_a_window(): void
    {
        Server::factory()->create(['name' => 'too-old', 'created_at' => now()->subDays(400)]);
        Server::factory()->create(['name' => 'in-window', 'created_at' => now()->subDays(60)]);
        Server::factory()->create(['name' => 'too-young', 'created_at' => now()->subDays(5)]);

        Artisan::call('dply:fleet:age', [
            '--older-than' => 30,
            '--younger-than' => 90,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $this->assertSame('in-window', $decoded['servers'][0]['server_name']);
    }

    public function test_friendly_message_when_no_servers_match(): void
    {
        Artisan::call('dply:fleet:age');
        $output = Artisan::output();

        $this->assertStringContainsString('No servers match', $output);
    }
}
