<?php

declare(strict_types=1);

namespace Tests\Feature\FleetAgeCommandTest;
use App\Models\Server;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('lists all servers oldest first', function () {
    $old = Server::factory()->create(['name' => 'old-server', 'created_at' => now()->subDays(400)]);
    $new = Server::factory()->create(['name' => 'new-server', 'created_at' => now()->subDays(10)]);

    Artisan::call('dply:fleet:age', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(2);
    expect($decoded['servers'][0]['server_name'])->toBe('old-server');
    expect($decoded['servers'][1]['server_name'])->toBe('new-server');
    expect($decoded['servers'][0]['age_days'])->toBeGreaterThanOrEqual(400);
});
test('older than filter', function () {
    Server::factory()->create(['name' => 'older', 'created_at' => now()->subDays(400)]);
    Server::factory()->create(['name' => 'newer', 'created_at' => now()->subDays(10)]);

    Artisan::call('dply:fleet:age', [
        '--older-than' => 100,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    expect($decoded['servers'][0]['server_name'])->toBe('older');
});
test('younger than filter', function () {
    Server::factory()->create(['name' => 'older', 'created_at' => now()->subDays(400)]);
    Server::factory()->create(['name' => 'newer', 'created_at' => now()->subDays(10)]);

    Artisan::call('dply:fleet:age', [
        '--younger-than' => 30,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    expect($decoded['servers'][0]['server_name'])->toBe('newer');
});
test('combined filters form a window', function () {
    Server::factory()->create(['name' => 'too-old', 'created_at' => now()->subDays(400)]);
    Server::factory()->create(['name' => 'in-window', 'created_at' => now()->subDays(60)]);
    Server::factory()->create(['name' => 'too-young', 'created_at' => now()->subDays(5)]);

    Artisan::call('dply:fleet:age', [
        '--older-than' => 30,
        '--younger-than' => 90,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    expect($decoded['servers'][0]['server_name'])->toBe('in-window');
});
test('friendly message when no servers match', function () {
    Artisan::call('dply:fleet:age');
    $output = Artisan::output();

    $this->assertStringContainsString('No servers match', $output);
});
